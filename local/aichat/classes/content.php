<?php
namespace local_aichat;

defined('MOODLE_INTERNAL') || die();

/**
 * Conversions de texte partagées : ce qui entre dans un prompt (texte brut
 * borné) et ce qui ressort vers le navigateur (markdown assaini).
 */
class content {

    /**
     * HTML Moodle → texte brut utilisable dans un prompt.
     *
     * @param string         $html
     * @param int            $format  FORMAT_* de la source
     * @param \context|null  $context pour les filtres et les fichiers
     * @param int            $maxlen  troncature (0 = pas de limite)
     * @return string
     */
    public static function to_plain_text($html, $format = FORMAT_HTML, $context = null, $maxlen = 3000) {
        $options = array('noclean' => true, 'para' => false, 'filter' => false);
        if ($context !== null) {
            $options['context'] = $context;
        }
        $text = format_text((string)$html, $format, $options);
        $text = html_to_text($text, 0, false);
        $text = preg_replace("/\n{3,}/", "\n\n", trim($text));
        return ($maxlen > 0) ? self::truncate($text, $maxlen) : $text;
    }

    /**
     * Tronque proprement un texte destiné à un prompt (les troncatures muettes
     * produisent des instructions coupées en plein milieu, que les petits
     * modèles interprètent n'importe comment).
     */
    public static function truncate($text, $maxlen) {
        $text = (string)$text;
        if ($maxlen <= 0 || \core_text::strlen($text) <= $maxlen) {
            return $text;
        }
        return \core_text::substr($text, 0, $maxlen) . "\n[…texte tronqué…]";
    }

    /**
     * Réponse du LLM (markdown) → HTML sûr pour l'affichage.
     *
     * format_text() en FORMAT_MARKDOWN passe systématiquement par clean_text()
     * (le markdown n'est jamais « trusted » dans Moodle) : le HTML produit est
     * donc assaini, ce qui est indispensable pour du texte venant d'un modèle
     * qui a lu des messages d'élèves.
     */
    public static function markdown_to_html($text, $context = null) {
        $options = array('para' => false, 'filter' => false);
        if ($context !== null) {
            $options['context'] = $context;
        }
        return format_text(self::tidy_markdown($text), FORMAT_MARKDOWN, $options);
    }

    /**
     * Répare le markdown des petits modèles avant conversion : formules LaTeX
     * (« $\pm 10\text{ V}$ ») et puces écrites à la suite sur une même ligne
     * (« plages : * Tension… * Courant… »). Le widget n'affiche pas les
     * mathématiques, et le markdown ne reconnaît pas ces puces : sans ça,
     * l'élève lit du code source.
     *
     * Le texte brut enregistré n'est pas touché : la réparation n'a lieu qu'à
     * l'affichage, pour le chat comme pour la transcription enseignant.
     *
     * @param string $text markdown produit par le modèle
     * @return string
     */
    public static function tidy_markdown($text) {
        $text = (string)$text;
        if ($text === '') {
            return $text;
        }
        // Blocs de code protégés : on ne touche à rien entre ``` ou `…`.
        $parts = preg_split('/(```.*?```|`[^`\n]*`)/su', $text, -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $out = '';
        foreach ($parts as $part) {
            $out .= (substr($part, 0, 1) === '`') ? $part : self::split_inline_bullets(self::unlatex($part));
        }
        return $out;
    }

    /** Commandes LaTeX courantes → caractères Unicode. */
    const LATEX = array(
        '\\pm' => '±', '\\mp' => '∓', '\\times' => '×', '\\cdot' => '·', '\\div' => '÷',
        '\\dots' => '…', '\\ldots' => '…', '\\cdots' => '…', '\\approx' => '≈', '\\sim' => '~',
        '\\leq' => '≤', '\\le' => '≤', '\\geq' => '≥', '\\ge' => '≥', '\\neq' => '≠', '\\ne' => '≠',
        '\\rightarrow' => '→', '\\to' => '→', '\\leftarrow' => '←', '\\Rightarrow' => '⇒',
        '\\mu' => 'µ', '\\Omega' => 'Ω', '\\omega' => 'ω', '\\alpha' => 'α', '\\beta' => 'β',
        '\\Delta' => 'Δ', '\\delta' => 'δ', '\\circ' => '°', '\\degree' => '°', '\\infty' => '∞',
        '\\%' => '%', '\\$' => '$', '\\&' => '&', '\\#' => '#', '\\_' => '_',
        '\\,' => ' ', '\\;' => ' ', '\\:' => ' ', '\\!' => '', '\\ ' => ' ',
        '\\quad' => ' ', '\\qquad' => ' ', '\\left' => '', '\\right' => '',
    );

    /**
     * Retire la notation mathématique : contenu des délimiteurs $…$, \(…\),
     * \[…\] et $$…$$, commandes \text{…} et symboles courants.
     *
     * Un « $ » de prix reste intact : le contenu doit ressembler à des
     * mathématiques (une commande LaTeX, ou uniquement chiffres, unités et
     * opérateurs).
     *
     * @param string $text
     * @return string
     */
    private static function unlatex($text) {
        if (strpos($text, '\\') === false && strpos($text, '$') === false) {
            return $text;
        }
        $inner = function($matches) {
            return self::plain_math($matches[1]);
        };
        // Délimiteurs explicites : toujours des mathématiques.
        $text = preg_replace_callback('/\$\$(.+?)\$\$/su', $inner, $text);
        $text = preg_replace_callback('/\\\\\((.+?)\\\\\)/su', $inner, $text);
        $text = preg_replace_callback('/\\\\\[(.+?)\\\\\]/su', $inner, $text);
        // $…$ : seulement si le contenu a l'air mathématique.
        $text = preg_replace_callback('/\$(?!\s)([^$\n]{1,200})(?<!\s)\$/u', function($matches) {
            $in = $matches[1];
            if (strpos($in, '\\') === false
                    && !preg_match('~^[0-9\s.,:;^_+\-*/()\[\]=<>%±×·…°µΩ]+$~u', $in)) {
                return $matches[0]; // prix, variable de shell, etc.
            }
            return self::plain_math($in);
        }, $text);
        // Formule ouverte mais jamais fermée : un « $ » collé à une commande
        // n'est jamais un prix.
        $text = preg_replace('/\$(?=\\\\[a-zA-Z])/u', '', $text);
        // Commandes restées hors délimiteurs (fréquent chez les petits
        // modèles). Ici, pas de nettoyage des accolades ni des espaces : le
        // reste du texte n'est pas une formule.
        if (strpos($text, '\\') !== false) {
            $text = self::plain_commands($text);
        }
        return $text;
    }

    /** Contenu d'une formule → texte lisible. */
    private static function plain_math($math) {
        $math = self::plain_commands($math);
        // Exposants et indices simples : ^{2} → 2, _1 → 1 (pas de <sup> : le
        // markdown est ensuite assaini, et le sens reste lisible).
        $math = preg_replace('/[\^_]\s*\{([^{}]*)\}/u', '$1', $math);
        $math = preg_replace('/\^(\d)/u', '$1', $math);
        // Accolades et espaces résiduels.
        $math = str_replace(array('{', '}'), '', $math);
        return trim(preg_replace('/[ \t]{2,}/u', ' ', $math));
    }

    /** Commandes et symboles LaTeX → texte, sans toucher à la mise en page. */
    private static function plain_commands($math) {
        // \text{…} et \mathrm{…} : du texte, où « -- » est un tiret d'intervalle.
        $math = preg_replace_callback('/\\\\(?:text|textrm|mathrm|mathit|textit|textbf)\s*\{([^{}]*)\}/u',
            function($matches) {
                return str_replace('--', '–', $matches[1]);
            }, $math);
        $math = preg_replace_callback('/\\\\frac\s*\{([^{}]*)\}\s*\{([^{}]*)\}/u', function($matches) {
            return $matches[1] . '/' . $matches[2];
        }, $math);
        $math = preg_replace('/\\\\sqrt\s*\{([^{}]*)\}/u', '√$1', $math);
        $math = str_replace(array_keys(self::LATEX), array_values(self::LATEX), $math);
        // « \pm 10 V » se lit « ±10 V ».
        return preg_replace('/([±∓])[ \t]+/u', '$1', $math);
    }

    /**
     * Puces écrites à la suite sur une même ligne → une par ligne.
     *
     * Deux « * » au moins doivent être entourés d'espaces et suivre autre
     * chose qu'un chiffre : « 2 * 3 * 4 » et « **gras** » ne sont pas touchés.
     *
     * @param string $text
     * @return string
     */
    private static function split_inline_bullets($text) {
        if (strpos($text, '*') === false) {
            return $text;
        }
        $bullet = '/(?<=[^\s\d])[ \t]+\*[ \t]+(?=[^\s*]|\*\*)/u';
        $lines = preg_split('/\R/u', $text);
        foreach ($lines as $index => $line) {
            if (preg_match_all($bullet, $line) < 2) {
                continue;
            }
            // Une liste doit être précédée d'une ligne vide, sauf quand la
            // ligne est elle-même une puce : on poursuit alors la même liste.
            $first = preg_match('/^\s*[*+-]\s/u', $line) ? "\n* " : "\n\n* ";
            $seen  = false;
            $lines[$index] = preg_replace_callback($bullet, function() use (&$seen, $first) {
                if ($seen) {
                    return "\n* ";
                }
                $seen = true;
                return $first;
            }, $line);
        }
        return implode("\n", $lines);
    }
}
