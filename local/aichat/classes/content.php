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
        return format_text((string)$text, FORMAT_MARKDOWN, $options);
    }
}
