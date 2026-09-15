<?php
namespace local_aifeedback;

defined('MOODLE_INTERNAL') || die();

/**
 * Cohérence niveau ↔ score, partagée par toutes les corrections IA.
 *
 * Le LLM renvoie deux informations redondantes : un « niveau » (enum strict,
 * libellé vu par l'étudiant) et un « score » entier censé être un pourcentage
 * 0-100. En pratique le score est fragile : quand l'énoncé ou le corrigé
 * mentionne un barème en points (/5, /20…), certains modèles renvoient le
 * nombre de points brut (ex. 5) au lieu du pourcentage (100) — d'où des
 * affichages absurdes du type « Très bonne maîtrise — 5/100 » et une note
 * Moodle fausse (5 % du maximum).
 *
 * Règle : le NIVEAU fait foi. Le score est ramené dans la bande du niveau
 * (mêmes bornes que celles annoncées dans les prompts), et n'est dérivé du
 * score que si le niveau manque.
 */
class scoring {

    /** Bandes (min, max) de score par niveau canonique, du plus haut au plus bas. */
    const BANDS = array(
        'Très bonne maîtrise'    => array(80, 100),
        'Maîtrise satisfaisante' => array(50, 79),
        'Maîtrise fragile'       => array(25, 49),
        'Maîtrise insuffisante'  => array(0, 24),
    );

    /**
     * Niveau canonique correspondant à un score 0-100.
     */
    public static function niveau_for_score($score): string {
        $score = (int)$score;
        foreach (self::BANDS as $niveau => $band) {
            if ($score >= $band[0]) {
                return $niveau;
            }
        }
        return 'Maîtrise insuffisante';
    }

    /**
     * Bande (min, max) d'un libellé de niveau, comparé sans accents ni casse.
     * Null si le libellé n'est pas un niveau connu.
     */
    public static function band_for_niveau($niveau): ?array {
        $key = self::normalize_key((string)$niveau);
        foreach (self::BANDS as $label => $band) {
            if (self::normalize_key($label) === $key) {
                return $band;
            }
        }
        return null;
    }

    /**
     * Rend cohérent un résultat LLM {niveau, score} :
     *   - score borné à 0-100 (0 si absent) ;
     *   - niveau dérivé du score s'il manque ;
     *   - score ramené dans la bande du niveau s'il en sort (borne la plus
     *     proche : un « 5 » avec « Très bonne maîtrise » devient 80).
     *
     * @param array $result payload JSON décodé
     * @return array le même payload, corrigé
     */
    public static function reconcile(array $result): array {
        $score = isset($result['score']) ? (int)$result['score'] : 0;
        $score = max(0, min(100, $score));

        if (empty($result['niveau'])) {
            $result['niveau'] = self::niveau_for_score($score);
        }

        $band = self::band_for_niveau($result['niveau']);
        if ($band !== null && ($score < $band[0] || $score > $band[1])) {
            $clamped = max($band[0], min($band[1], $score));
            debugging('local_aifeedback: score ' . $score . ' incohérent avec le niveau "'
                . $result['niveau'] . '" — ramené à ' . $clamped, DEBUG_DEVELOPER);
            $score = $clamped;
        }

        $result['score'] = $score;
        return $result;
    }

    private static function normalize_key(string $s): string {
        $from = array('é','è','ê','ë','à','â','ä','î','ï','ô','ö','ù','û','ü','ç',
                      'É','È','Ê','Ë','À','Â','Ä','Î','Ï','Ô','Ö','Ù','Û','Ü','Ç');
        $to   = array('e','e','e','e','a','a','a','i','i','o','o','u','u','u','c',
                      'E','E','E','E','A','A','A','I','I','O','O','U','U','U','C');
        return strtolower(trim(str_replace($from, $to, $s)));
    }
}
