<?php
namespace local_aichat\reader;

defined('MOODLE_INTERNAL') || die();

/**
 * Du document téléchargé au texte utile pour le modèle.
 *
 * - HTML : texte visible (sans scripts, styles, menus, pieds de page), titre,
 *   et liens vers des documents (datasheet, manuel) qui deviennent lisibles ;
 * - PDF : pdftotext, via \local_aifeedback\content_extractor (binaire déjà
 *   configuré pour la correction des devoirs) ;
 * - sélection : un manuel peut dépasser 150 000 caractères. On ne transmet
 *   que les passages les plus pertinents pour les mots-clés fournis par le
 *   modèle (focus), plus le début du document, dans un budget de caractères.
 */
class extractor {

    /** Taille visée d'un bloc de sélection. */
    const CHUNK = 500;

    /** Texte extrait gardé au plus (cache et sélection). */
    const MAX_TEXT = 300000;

    /** Liens de documents retenus au plus par page. */
    const MAX_LINKS = 8;

    /** Balises dont le contenu n'est jamais du texte utile. */
    const DROP_TAGS = 'script|style|noscript|svg|nav|header|footer|form|iframe|template|select|button';

    /**
     * Texte d'une page HTML.
     *
     * @param string $html
     * @param string $baseurl adresse de la page (liens relatifs)
     * @return array {title, text, links: [{url, label}]}
     */
    public static function html($html, $baseurl) {
        $html = self::utf8((string)$html);

        $title = '';
        if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) {
            $title = self::clean_inline($m[1]);
        }
        if ($title === '' && preg_match('#<h1[^>]*>(.*?)</h1>#is', $html, $m)) {
            $title = self::clean_inline($m[1]);
        }

        $html  = preg_replace('#<!--.*?-->#s', ' ', $html);
        $html  = preg_replace('#<(' . self::DROP_TAGS . ')\b[^>]*>.*?</\1\s*>#is', ' ', $html);
        $links = self::document_links($html, $baseurl);

        // Structure minimale : un bloc par ligne, cellules séparées par « | ».
        $text = preg_replace('#</(td|th)\s*>#i', ' | ', $html);
        $text = preg_replace('#<(br|/p|/div|/li|/tr|/h[1-6]|/table|/section|/article|/dt|/dd|/ul|/ol)\b[^>]*>#i',
            "\n", $text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = self::tidy($text);

        return array('title' => $title, 'text' => $text, 'links' => $links);
    }

    /**
     * Texte d'un PDF (pdftotext).
     *
     * @param string $bytes contenu du fichier
     * @return string
     * @throws \moodle_exception si pdftotext est absent ou échoue
     */
    public static function pdf($bytes) {
        $dir  = make_request_directory();
        $path = $dir . '/document.pdf';
        file_put_contents($path, $bytes);
        try {
            $text = \local_aifeedback\content_extractor::read_pdf_path($path);
        } finally {
            @unlink($path);
        }
        return self::tidy($text);
    }

    /**
     * Passages les plus pertinents d'un texte, dans l'ordre du document.
     *
     * Score d'un bloc : occurrences des mots de $focus (insensible à la casse
     * et aux accents, comparaison sur les 5 premières lettres pour couvrir
     * « counter / counters / counting ») + bonus pour les valeurs chiffrées
     * suivies d'une unité (les caractéristiques techniques). Le premier bloc
     * (titre, présentation) est toujours gardé.
     *
     * @param string $text
     * @param string $focus  mots-clés (peut être vide)
     * @param int    $budget caractères au plus
     * @return string
     */
    public static function select($text, $focus, $budget) {
        $text = (string)$text;
        if (\core_text::strlen($text) <= $budget) {
            return $text;
        }
        $chunks = self::chunks($text);
        $terms  = self::terms($focus);

        $scores = array();
        foreach ($chunks as $i => $chunk) {
            $norm  = self::fold($chunk);
            $score = 0;
            $hits  = 0;
            foreach ($terms as $term) {
                $count = substr_count($norm, $term);
                if ($count > 0) {
                    $hits++;
                    $score += 2 * min(5, $count);
                }
            }
            $score += 3 * $hits;
            $specs  = preg_match_all('/\d+(?:[.,]\d+)?\s?(?:v|vdc|vac|ma|a|hz|khz|mhz|bits?|ms|us|°c|w|kb|mb|'
                . 'ch|channels?|canaux|voies|inputs?|outputs?|entrees?|sorties?)\b/iu', $norm);
            $score += min(5, (int)$specs);
            $scores[$i] = $score;
        }

        // Premier bloc d'office, puis les meilleurs, tant que le budget le permet.
        $keep  = array(0 => true);
        $used  = \core_text::strlen($chunks[0]);
        arsort($scores);
        foreach ($scores as $i => $score) {
            if ($i === 0 || $score <= 0) {
                continue;
            }
            $len = \core_text::strlen($chunks[$i]) + 6;
            if ($used + $len > $budget) {
                continue;
            }
            $keep[$i] = true;
            $used    += $len;
        }
        ksort($keep);

        $out  = '';
        $prev = -1;
        foreach (array_keys($keep) as $i) {
            if ($prev >= 0 && $i !== $prev + 1) {
                $out .= "\n[…]\n";
            } else if ($prev >= 0) {
                $out .= "\n";
            }
            $out .= $chunks[$i];
            $prev = $i;
        }
        if ($prev < count($chunks) - 1) {
            $out .= "\n[…]";
        }
        return \core_text::substr($out, 0, $budget);
    }

    /**
     * Blocs d'environ CHUNK caractères, coupés sur les fins de ligne : une
     * ligne de tableau n'est jamais coupée en deux.
     *
     * @param string $text
     * @return string[]
     */
    public static function chunks($text) {
        $chunks  = array();
        $current = '';
        foreach (explode("\n", $text) as $line) {
            if (\core_text::strlen($line) > 2 * self::CHUNK) {
                // Ligne démesurée (texte sans retour) : découpée en morceaux.
                foreach (self::split_long($line) as $piece) {
                    if ($current !== '') {
                        $chunks[] = $current;
                        $current = '';
                    }
                    $chunks[] = $piece;
                }
                continue;
            }
            $current .= ($current === '' ? '' : "\n") . $line;
            if (\core_text::strlen($current) >= self::CHUNK) {
                $chunks[] = $current;
                $current  = '';
            }
        }
        if (trim($current) !== '') {
            $chunks[] = $current;
        }
        return empty($chunks) ? array('') : $chunks;
    }

    /**
     * Mots-clés de recherche, normalisés et réduits à 5 lettres (≥ 3 lettres,
     * chiffres conservés : « 24v », « rs485 »).
     *
     * @param string $focus
     * @return string[]
     */
    public static function terms($focus) {
        $out = array();
        foreach (preg_split('/[^a-z0-9µ°]+/u', self::fold($focus)) as $word) {
            if (\core_text::strlen($word) >= 3) {
                $out[\core_text::substr($word, 0, 5)] = true;
            }
        }
        return array_keys($out);
    }

    /** Minuscules sans accents, pour comparer. */
    public static function fold($text) {
        $text = \core_text::strtolower((string)$text);
        return strtr($text, array(
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e',
            'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ù' => 'u',
            'û' => 'u', 'ü' => 'u', 'ú' => 'u', 'ÿ' => 'y', 'ñ' => 'n', 'œ' => 'oe', 'æ' => 'ae',
        ));
    }

    /**
     * Liens de la page qui mènent à des documents (datasheet, manuel,
     * téléchargement), rendus absolus.
     *
     * @param string $html
     * @param string $baseurl
     * @return array[] {url, label}
     */
    private static function document_links($html, $baseurl) {
        $out = array();
        if (!preg_match_all('#<a\s[^>]*href\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a\s*>#is', $html, $m, PREG_SET_ORDER)) {
            return $out;
        }
        foreach ($m as $match) {
            $href  = html_entity_decode(trim($match[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $label = self::clean_inline($match[3]);
            $isdoc = preg_match('#\.pdf($|[?\#])#i', $href)
                || preg_match('#(download|datasheet|data-sheet|manual|documentation)#i', $href)
                || preg_match('/(datasheet|fiche technique|manual|manuel|documentation|user guide|notice)/iu', $label);
            if (!$isdoc) {
                continue;
            }
            $url = self::absolute($href, $baseurl);
            if ($url === '' || isset($out[$url])) {
                continue;
            }
            $out[$url] = array('url' => $url, 'label' => \core_text::substr($label, 0, 80));
            if (count($out) >= self::MAX_LINKS) {
                break;
            }
        }
        return array_values($out);
    }

    /** Adresse absolue http(s), ou '' si elle ne mène pas à une page Web. */
    private static function absolute($href, $baseurl) {
        if ($href === '' || $href[0] === '#' || preg_match('#^(javascript|mailto|tel|data):#i', $href)) {
            return '';
        }
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        $base = parse_url($baseurl);
        if (empty($base['scheme']) || empty($base['host'])) {
            return '';
        }
        $root = $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');
        if (strpos($href, '//') === 0) {
            return $base['scheme'] . ':' . $href;
        }
        if ($href[0] === '/') {
            return $root . $href;
        }
        $path = isset($base['path']) ? $base['path'] : '/';
        $dir  = substr($path, 0, strrpos($path, '/') + 1);
        return $root . $dir . $href;
    }

    /** Texte d'un fragment HTML sur une ligne. */
    private static function clean_inline($html) {
        $text = html_entity_decode(strip_tags((string)$html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Texte garanti en UTF-8 valide. Une page ou une sortie de pdftotext peut
     * être en Latin-1 (selon le binaire et sa configuration), ou contenir
     * quelques octets invalides : sans cette étape, les expressions régulières
     * /u renverraient null et tout le texte serait perdu.
     *
     * @param string $text
     * @return string
     */
    public static function utf8($text) {
        $text = (string)$text;
        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }
        // Aucune séquence UTF-8 multi-octets : c'est du Latin-1 (Windows-1252).
        if (!preg_match('/[\xC2-\xF4][\x80-\xBF]/', $text)) {
            return mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }
        // UTF-8 avec quelques octets invalides : ils sont remplacés.
        return mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }

    /** Espaces réduits par ligne, lignes vides groupées, taille bornée. */
    private static function tidy($text) {
        $lines = array();
        foreach (preg_split('/\R/u', self::utf8($text)) as $line) {
            $line = trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', $line));
            $line = trim($line, '| ');
            $lines[] = $line;
        }
        $text = preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines));
        return \core_text::substr(trim($text), 0, self::MAX_TEXT);
    }

    /** Découpe une ligne trop longue en morceaux d'environ CHUNK caractères. */
    private static function split_long($line) {
        $pieces = array();
        $len    = \core_text::strlen($line);
        for ($i = 0; $i < $len; $i += self::CHUNK) {
            $pieces[] = \core_text::substr($line, $i, self::CHUNK);
        }
        return $pieces;
    }
}
