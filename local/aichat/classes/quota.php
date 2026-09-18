<?php
namespace local_aichat;

defined('MOODLE_INTERNAL') || die();

/**
 * Quota d'usage du tuteur, par élève et par fenêtre glissante.
 *
 * Le but n'est pas de rationner la pédagogie mais de protéger la machine du
 * lycée : sans plafond, une classe entière qui « discute » avec l'IA sature le
 * serveur et personne n'a de réponse. Deux limites complémentaires, dans une
 * fenêtre glissante (4 h par défaut) et TOUTES ACTIVITÉS CONFONDUES, puisque
 * c'est bien le serveur qu'on protège :
 *   - un budget de tokens (le vrai coût de calcul) ;
 *   - un nombre de messages (garde-fou contre le spam de questions courtes).
 * Chaque limite à 0 désactive ce plafond.
 */
class quota {

    /** Valeurs par défaut (voir settings.php). */
    const DEFAULT_WINDOW_HOURS = 4;
    const DEFAULT_TOKENS       = 20000;
    const DEFAULT_MESSAGES     = 40;

    /**
     * Estimation du nombre de tokens d'un texte, quand le serveur ne le
     * communique pas : environ 4 caractères par token.
     */
    public static function estimate_tokens($text) {
        $len = \core_text::strlen((string)$text);
        return (int)max(1, ceil($len / 4));
    }

    /**
     * État du quota d'un utilisateur.
     *
     * @param int $userid
     * @return \stdClass {ok, tokensused, tokensmax, messagesused, messagesmax,
     *                    retryafter (secondes), windowhours}
     */
    public static function check($userid) {
        global $DB;

        $windowhours = (int)get_config('local_aichat', 'quota_window_hours');
        if ($windowhours <= 0) {
            $windowhours = self::DEFAULT_WINDOW_HOURS;
        }
        $tokensmax   = self::limit('quota_tokens', self::DEFAULT_TOKENS);
        $messagesmax = self::limit('quota_messages', self::DEFAULT_MESSAGES);

        $since = time() - ($windowhours * HOURSECS);

        $out = (object)array(
            'ok'           => true,
            'tokensused'   => 0,
            'tokensmax'    => $tokensmax,
            'messagesused' => 0,
            'messagesmax'  => $messagesmax,
            'retryafter'   => 0,
            'windowhours'  => $windowhours,
        );

        if ($tokensmax <= 0 && $messagesmax <= 0) {
            return $out; // aucun plafond actif
        }

        $row = $DB->get_record_sql(
            "SELECT COALESCE(SUM(tokens), 0) AS tokens,
                    SUM(CASE WHEN role = :roleuser THEN 1 ELSE 0 END) AS messages,
                    MIN(timecreated) AS oldest
               FROM {local_aichat_message}
              WHERE userid = :userid AND timecreated >= :since
                AND status <> :cancelled",
            array('roleuser' => 'user', 'userid' => (int)$userid,
                  'since' => $since, 'cancelled' => 'cancelled'));

        $out->tokensused   = $row ? (int)$row->tokens : 0;
        $out->messagesused = $row ? (int)$row->messages : 0;

        $overtokens   = ($tokensmax > 0 && $out->tokensused >= $tokensmax);
        $overmessages = ($messagesmax > 0 && $out->messagesused >= $messagesmax);

        if ($overtokens || $overmessages) {
            $out->ok = false;
            // Le quota se libère quand le message le plus ancien de la fenêtre
            // en sort : c'est le délai qu'on annonce à l'élève.
            $oldest = ($row && $row->oldest) ? (int)$row->oldest : time();
            $out->retryafter = max(60, ($oldest + $windowhours * HOURSECS) - time());
        }

        return $out;
    }

    /**
     * Enregistre la consommation d'un échange sur le message assistant.
     *
     * @param int        $messageid
     * @param array|null $usage   bloc « usage » renvoyé par le serveur, si fourni
     * @param string     $prompt  texte envoyé (pour l'estimation de repli)
     * @param string     $answer  texte reçu (pour l'estimation de repli)
     * @return int tokens comptabilisés
     */
    public static function record($messageid, $usage, $prompt, $answer) {
        global $DB;

        $tokens = 0;
        if (is_array($usage) && isset($usage['total_tokens'])) {
            $tokens = (int)$usage['total_tokens'];
        } else if (is_array($usage)
                && (isset($usage['prompt_tokens']) || isset($usage['completion_tokens']))) {
            $tokens = (int)(isset($usage['prompt_tokens']) ? $usage['prompt_tokens'] : 0)
                    + (int)(isset($usage['completion_tokens']) ? $usage['completion_tokens'] : 0);
        }
        if ($tokens <= 0) {
            $tokens = self::estimate_tokens($prompt) + self::estimate_tokens($answer);
        }

        $DB->set_field('local_aichat_message', 'tokens', $tokens, array('id' => (int)$messageid));
        return $tokens;
    }

    /** Lecture d'un plafond (valeur par défaut tant que rien n'a été réglé). */
    private static function limit($name, $default) {
        $value = get_config('local_aichat', $name);
        if ($value === false || $value === '') {
            return (int)$default;
        }
        return max(0, (int)$value);
    }
}
