<?php
namespace local_aimissions;

defined('MOODLE_INTERNAL') || die();

/**
 * Profils psychologiques du client fictif. Partagé par la génération (Agent 1),
 * la correction (systemprompt de assignfeedback_ai) et les réponses aux tickets
 * (Agent 2), pour que le client reste cohérent partout.
 */
class personas {

    /**
     * Consigne de ton injectée dans les prompts pour un profil donné.
     */
    public static function instruction(string $code): string {
        switch ($code) {
            case 'exigeant':
                return "client EXIGEANT : attentes élevées, ton ferme, insiste sur la qualité et les délais.";
            case 'imprecis':
                return "client IMPRÉCIS : besoin formulé de façon floue, quelques ambiguïtés volontaires "
                     . "que l'étudiant devra lever (sans rendre la demande incompréhensible).";
            case 'versatile':
                return "client VERSATILE : mentionne qu'il a déjà changé d'avis par le passé, ton hésitant.";
            case 'lent':
                return "client peu disponible : ton bref, peu de détails, comme quelqu'un de pressé.";
            case 'nontechnique':
                return "client NON TECHNIQUE : vocabulaire courant uniquement, analogies du quotidien, "
                     . "aucune notion informatique.";
            case 'neutre':
            default:
                return "client coopératif et clair, ton professionnel et bienveillant.";
        }
    }

    /** Fourchettes de délai par défaut (heures), si le réglage est absent/invalide. */
    private static function default_delays(): array {
        return array(
            'neutre'       => array(2, 6),
            'exigeant'     => array(1, 4),
            'imprecis'     => array(4, 12),
            'versatile'    => array(3, 10),
            'lent'         => array(12, 36),
            'nontechnique' => array(6, 18),
        );
    }

    /**
     * Délai (en SECONDES) avant que le client réponde, modulé par le persona.
     *
     * Lit le réglage `replydelays` (« code: min-max » en heures, une ligne par
     * persona), retombe sur les défauts pour les entrées absentes/invalides,
     * tire aléatoirement dans la fourchette, puis applique le multiplicateur
     * global `replydelayfactor`.
     */
    public static function reply_delay(string $code): int {
        $defaults = self::default_delays();
        $ranges   = self::parse_delays((string)get_config('local_aimissions', 'replydelays'));

        $range = $ranges[$code] ?? $defaults[$code] ?? $defaults['neutre'];
        $min = (float)$range[0];
        $max = (float)$range[1];
        if ($max < $min) {
            $max = $min;
        }

        $factor = (float)get_config('local_aimissions', 'replydelayfactor');
        if ($factor <= 0) {
            $factor = 1.0;
        }

        // Tirage aléatoire en heures → secondes.
        $hours = $min + (mt_rand(0, 1000) / 1000.0) * ($max - $min);
        return max(0, (int)round($hours * 3600 * $factor));
    }

    /**
     * Parse le réglage « code: min-max » (heures) en [code => [min, max]].
     */
    private static function parse_delays(string $raw): array {
        $out = array();
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, ':') === false) {
                continue;
            }
            list($code, $rest) = explode(':', $line, 2);
            $code = trim($code);
            if ($code !== '' && preg_match('/^\s*([0-9]+(?:\.[0-9]+)?)\s*-\s*([0-9]+(?:\.[0-9]+)?)\s*$/',
                    trim($rest), $m)) {
                $out[$code] = array((float)$m[1], (float)$m[2]);
            }
        }
        return $out;
    }
}
