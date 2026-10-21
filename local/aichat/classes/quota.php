<?php
namespace local_aichat;

defined('MOODLE_INTERNAL') || die();

/**
 * Quota d'usage du tuteur, par élève et par fenêtre.
 *
 * Le but n'est pas de rationner la pédagogie mais de protéger la machine du
 * lycée : sans plafond, une classe entière qui « discute » avec l'IA sature le
 * serveur et personne n'a de réponse. Deux limites complémentaires, TOUTES
 * ACTIVITÉS CONFONDUES, puisque c'est bien le serveur qu'on protège :
 *   - un budget de tokens : ceux de la question de l'élève (fichiers joints
 *     compris) et de la réponse qu'il lit, estimés sur le texte. Le reste de
 *     la requête (prompt système,
 *     contexte de l'activité, historique renvoyé au modèle, résultats de
 *     recherche) n'est pas compté : l'élève n'y peut rien, et son budget
 *     fondrait de plus en plus vite à mesure que la conversation s'allonge ;
 *   - un nombre de messages (garde-fou contre le spam de questions courtes).
 * Chaque limite à 0 désactive ce plafond.
 *
 * Fenêtre FIXE : elle s'ouvre au premier message de l'élève et dure N heures
 * (4 par défaut) ; à son terme, les compteurs repartent de zéro et la fenêtre
 * suivante s'ouvrira au message suivant. L'élève connaît ainsi l'heure exacte
 * de sa réinitialisation, ce qu'une fenêtre glissante ne permet pas.
 *
 * L'enseignant peut, depuis la page « Quotas du tuteur IA » du cours,
 * réinitialiser la fenêtre d'un élève ou lui accorder un bonus (quotas.php).
 */
class quota {

    const TABLE = 'local_aichat_quota';

    /** Valeurs par défaut (voir settings.php). */
    const DEFAULT_WINDOW_HOURS = 4;
    const DEFAULT_TOKENS       = 20000;
    const DEFAULT_MESSAGES     = 40;

    /**
     * Estimation du nombre de tokens d'un texte : environ 4 caractères par
     * token. Le serveur ne détaille pas sa facture message par message, et
     * l'estimation reste la même quel que soit le serveur qui a répondu.
     */
    public static function estimate_tokens($text) {
        $len = \core_text::strlen((string)$text);
        return (int)ceil($len / 4);
    }

    /**
     * État du quota d'un utilisateur (widget de l'élève).
     *
     * @param int $userid
     * @return \stdClass voir states()
     */
    public static function check($userid) {
        $states = self::states(array((int)$userid));
        $state  = $states[(int)$userid];
        // Le widget n'a pas à connaître l'enseignant à l'origine d'un bonus.
        unset($state->grantedby, $state->granttime);
        return $state;
    }

    /**
     * État du quota de plusieurs utilisateurs : deux requêtes pour toute une
     * classe (page enseignant).
     *
     * @param int[] $userids
     * @return \stdClass[] par userid : {ok, tokensused, tokensmax, messagesused,
     *                     messagesmax (plafonds bonus compris, 0 = sans limite),
     *                     bonustokens, bonusmessages, windowstart (0 = aucune
     *                     fenêtre ouverte), resetat, resetlabel, retryafter
     *                     (secondes), windowhours, grantedby, granttime}
     */
    public static function states(array $userids) {
        global $DB;

        $now          = time();
        $length       = self::window_seconds();
        $cutoff       = $now - $length; // fenêtre ouverte <=> windowstart > $cutoff
        $tokensmax    = self::limit('quota_tokens', self::DEFAULT_TOKENS);
        $messagesmax  = self::limit('quota_messages', self::DEFAULT_MESSAGES);

        $userids = array_values(array_unique(array_map('intval', $userids)));
        if (empty($userids)) {
            return array();
        }
        list($insql, $params) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);

        $rows = $DB->get_records_select(self::TABLE, "userid $insql", $params, '',
            'userid, windowstart, bonustokens, bonusmessages, grantedby, granttime');

        // Consommation de la fenêtre en cours. Les réponses annulées comptent ce
        // que l'élève en a vu ; les réponses en échec ne comptent pas (0 token).
        $usage = $DB->get_records_sql(
            "SELECT q.userid, COALESCE(SUM(m.tokens), 0) AS tokens,
                    COALESCE(SUM(CASE WHEN m.role = :roleuser THEN 1 ELSE 0 END), 0) AS messages
               FROM {" . self::TABLE . "} q
               JOIN {local_aichat_message} m
                 ON m.userid = q.userid AND m.timecreated >= q.windowstart
              WHERE q.userid $insql AND q.windowstart > :cutoff
           GROUP BY q.userid",
            array_merge($params, array('roleuser' => 'user', 'cutoff' => $cutoff)));

        $out = array();
        foreach ($userids as $userid) {
            $row   = isset($rows[$userid]) ? $rows[$userid] : null;
            $open  = ($row !== null && (int)$row->windowstart > $cutoff);
            $bonus = ($row !== null && self::bonus_applies($row, $cutoff, $length));
            $state = (object)array(
                'ok'            => true,
                'tokensused'    => ($open && isset($usage[$userid])) ? (int)$usage[$userid]->tokens : 0,
                'tokensmax'     => 0,
                'messagesused'  => ($open && isset($usage[$userid])) ? (int)$usage[$userid]->messages : 0,
                'messagesmax'   => 0,
                'bonustokens'   => $bonus ? (int)$row->bonustokens : 0,
                'bonusmessages' => $bonus ? (int)$row->bonusmessages : 0,
                'windowstart'   => $open ? (int)$row->windowstart : 0,
                'resetat'       => $open ? (int)$row->windowstart + $length : 0,
                'resetlabel'    => $open ? self::time_label((int)$row->windowstart + $length) : '',
                'retryafter'    => 0,
                'windowhours'   => self::window_hours(),
                'grantedby'     => ($row !== null) ? (int)$row->grantedby : 0,
                'granttime'     => ($row !== null) ? (int)$row->granttime : 0,
            );
            // Un bonus ne lève pas un plafond désactivé : il s'y ajoute.
            if ($tokensmax > 0) {
                $state->tokensmax = $tokensmax + $state->bonustokens;
            }
            if ($messagesmax > 0) {
                $state->messagesmax = $messagesmax + $state->bonusmessages;
            }

            $overtokens   = ($state->tokensmax > 0 && $state->tokensused >= $state->tokensmax);
            $overmessages = ($state->messagesmax > 0 && $state->messagesused >= $state->messagesmax);
            if ($overtokens || $overmessages) {
                $state->ok = false;
                $state->retryafter = max(60, $state->resetat - $now);
            }
            $out[$userid] = $state;
        }
        return $out;
    }

    /**
     * Ouvre la fenêtre de l'élève s'il n'en a pas d'ouverte : appelé à l'envoi
     * d'un message, après check(). Un bonus accordé depuis la fin de la fenêtre
     * précédente (ou après une réinitialisation) vaut pour la nouvelle ; celui
     * de la fenêtre précédente expire avec elle.
     *
     * @param int $userid
     */
    public static function open_window($userid) {
        global $DB;
        $now    = time();
        $length = self::window_seconds();

        $row = $DB->get_record(self::TABLE, array('userid' => (int)$userid));
        if ($row && (int)$row->windowstart > $now - $length) {
            return; // déjà ouverte
        }
        if ($row) {
            $update = (object)array('id' => (int)$row->id, 'windowstart' => $now, 'timemodified' => $now);
            if (!self::bonus_applies($row, $now - $length, $length)) {
                $update->bonustokens   = 0;
                $update->bonusmessages = 0;
            }
            $DB->update_record(self::TABLE, $update);
            return;
        }
        try {
            $DB->insert_record(self::TABLE, (object)array(
                'userid'        => (int)$userid,
                'windowstart'   => $now,
                'bonustokens'   => 0,
                'bonusmessages' => 0,
                'grantedby'     => 0,
                'granttime'     => 0,
                'timemodified'  => $now,
            ));
        } catch (\dml_write_exception $e) {
            // Ouverte au même instant par un envoi sur une autre activité.
        }
    }

    /**
     * Réinitialise la fenêtre d'élèves : compteurs à zéro tout de suite (les
     * messages déjà envoyés ne comptent plus), nouvelle fenêtre au prochain
     * message. Un bonus en cours est retiré : l'élève retrouve son quota normal.
     *
     * @param int[] $userids
     * @param int   $byuserid enseignant
     * @return int nombre d'élèves
     */
    public static function reset_windows(array $userids, $byuserid) {
        $count = 0;
        foreach (array_unique(array_map('intval', $userids)) as $userid) {
            self::upsert($userid, array('windowstart' => 0, 'bonustokens' => 0, 'bonusmessages' => 0), $byuserid);
            $count++;
        }
        return $count;
    }

    /**
     * Accorde un bonus à des élèves : il s'ajoute aux plafonds de leur fenêtre
     * en cours, ou de la prochaine s'ils n'en ont pas d'ouverte, et expire avec
     * elle. Plusieurs bonus sur la même fenêtre s'additionnent.
     *
     * @param int[] $userids
     * @param int   $tokens
     * @param int   $messages
     * @param int   $byuserid enseignant
     * @return int nombre d'élèves
     */
    public static function grant(array $userids, $tokens, $messages, $byuserid) {
        global $DB;
        $length = self::window_seconds();
        $cutoff = time() - $length;
        $count  = 0;
        foreach (array_unique(array_map('intval', $userids)) as $userid) {
            $row  = $DB->get_record(self::TABLE, array('userid' => $userid));
            $keep = ($row && self::bonus_applies($row, $cutoff, $length));
            self::upsert($userid, array(
                'bonustokens'   => ($keep ? (int)$row->bonustokens : 0) + max(0, (int)$tokens),
                'bonusmessages' => ($keep ? (int)$row->bonusmessages : 0) + max(0, (int)$messages),
            ), $byuserid);
            $count++;
        }
        return $count;
    }

    /**
     * Début de la fenêtre en cours d'un élève, 0 s'il n'en a pas d'ouverte
     * (rien ne compte alors). Partagé avec les quotas de recherche Web.
     *
     * @param int $userid
     * @return int
     */
    public static function window_start($userid) {
        global $DB;
        $start = (int)$DB->get_field(self::TABLE, 'windowstart', array('userid' => (int)$userid));
        return ($start > time() - self::window_seconds()) ? $start : 0;
    }

    /** Durée de la fenêtre, en heures (partagée avec la recherche Web). */
    public static function window_hours() {
        $windowhours = (int)get_config('local_aichat', 'quota_window_hours');
        return ($windowhours > 0) ? $windowhours : self::DEFAULT_WINDOW_HOURS;
    }

    /** Durée de la fenêtre, en secondes. */
    public static function window_seconds() {
        return self::window_hours() * HOURSECS;
    }

    /** Plafond de tokens réglé (0 = sans limite), hors bonus. */
    public static function tokens_limit() {
        return self::limit('quota_tokens', self::DEFAULT_TOKENS);
    }

    /** Plafond de messages réglé (0 = sans limite), hors bonus. */
    public static function messages_limit() {
        return self::limit('quota_messages', self::DEFAULT_MESSAGES);
    }

    /**
     * Heure lisible, dans le fuseau de l'utilisateur courant : l'heure seule
     * si c'est aujourd'hui, la date et l'heure sinon.
     *
     * @param int $time
     * @return string
     */
    public static function time_label($time) {
        $sameday = (userdate($time, '%Y%m%d') === userdate(time(), '%Y%m%d'));
        return userdate($time, get_string($sameday ? 'strftimetime' : 'strftimedatetimeshort', 'langconfig'));
    }

    /**
     * Enregistre sur le message assistant les tokens comptés pour l'échange :
     * la question de l'élève et la réponse qu'il a vue (réponse partielle s'il
     * l'a interrompue), sources ajoutées comprises. Les fichiers joints à la
     * question comptent une fois, ici : le texte transmis, et un forfait par
     * image (attachment::IMAGE_TOKENS), même s'ils sont renvoyés au modèle
     * avec les questions suivantes.
     *
     * @param int    $messageid
     * @param string $question
     * @param string $answer
     * @param int    $filetokens tokens des fichiers joints (attachment::tokens_for_message)
     * @return int tokens comptabilisés
     */
    public static function record($messageid, $question, $answer, $filetokens = 0) {
        global $DB;
        $tokens = self::estimate_tokens($question) + self::estimate_tokens($answer) + max(0, (int)$filetokens);
        $DB->set_field('local_aichat_message', 'tokens', $tokens, array('id' => (int)$messageid));
        return $tokens;
    }

    /**
     * Le bonus enregistré vaut-il pour la fenêtre en cours, ou pour la
     * prochaine si aucune n'est ouverte ?
     *
     * @param \stdClass $row    ligne de {local_aichat_quota}
     * @param int       $cutoff instant avant lequel une fenêtre ouverte est terminée
     * @param int       $length durée de la fenêtre (secondes)
     * @return bool
     */
    private static function bonus_applies(\stdClass $row, $cutoff, $length) {
        if ((int)$row->bonustokens <= 0 && (int)$row->bonusmessages <= 0) {
            return false;
        }
        $start = (int)$row->windowstart;
        if ($start > $cutoff) {
            return true; // fenêtre ouverte : open_window() a déjà écarté un bonus périmé
        }
        // Pas de fenêtre : le bonus attend la prochaine s'il a été accordé
        // après la fin de la précédente (ou si elle a été réinitialisée).
        return $start === 0 || (int)$row->granttime >= $start + $length;
    }

    /** Crée ou complète la ligne de quota d'un élève, au nom d'un enseignant. */
    private static function upsert($userid, array $fields, $byuserid) {
        global $DB;
        $now = time();
        $fields['grantedby']    = (int)$byuserid;
        $fields['granttime']    = $now;
        $fields['timemodified'] = $now;

        $id = $DB->get_field(self::TABLE, 'id', array('userid' => (int)$userid));
        if ($id) {
            $DB->update_record(self::TABLE, (object)array_merge($fields, array('id' => (int)$id)));
            return;
        }
        $DB->insert_record(self::TABLE, (object)array_merge(array(
            'userid'        => (int)$userid,
            'windowstart'   => 0,
            'bonustokens'   => 0,
            'bonusmessages' => 0,
        ), $fields));
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
