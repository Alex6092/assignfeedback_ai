<?php
namespace local_aichat;

defined('MOODLE_INTERNAL') || die();

/**
 * Fil de discussion d'un élève sur une activité : création, historique et
 * cycle de vie des messages.
 */
class conversation {

    const TABLE      = 'local_aichat_conversation';
    const TABLE_MSG  = 'local_aichat_message';

    /** Statuts d'un message assistant en cours de traitement. */
    const BUSY_STATUSES = array('pending', 'streaming');

    /**
     * Conversation active de l'élève sur cette activité (créée au besoin).
     */
    public static function active($cmid, $userid) {
        global $DB;
        $row = $DB->get_record(self::TABLE, array(
            'cmid'   => (int)$cmid,
            'userid' => (int)$userid,
            'status' => 'active',
        ));
        if ($row) {
            return $row;
        }
        $now = time();
        $row = (object)array(
            'cmid'         => (int)$cmid,
            'userid'       => (int)$userid,
            'status'       => 'active',
            'msgcount'     => 0,
            'timecreated'  => $now,
            'timemodified' => $now,
        );
        $row->id = $DB->insert_record(self::TABLE, $row);
        return $row;
    }

    /**
     * Clôt la conversation en cours (bouton « Nouvelle discussion ») et en
     * ouvre une neuve. L'ancienne reste consultable par l'enseignant.
     */
    public static function restart($cmid, $userid) {
        global $DB;
        $current = $DB->get_record(self::TABLE, array(
            'cmid'   => (int)$cmid,
            'userid' => (int)$userid,
            'status' => 'active',
        ));
        if ($current) {
            // Ne pas abandonner une génération en cours sans la libérer.
            $busy = self::busy_message((int)$current->id);
            if ($busy !== null) {
                self::fail_message((int)$busy->id, 'cancelled', null);
            }
            $DB->update_record(self::TABLE, (object)array(
                'id'           => (int)$current->id,
                'status'       => 'closed',
                'timemodified' => time(),
            ));
        }
        return self::active($cmid, $userid);
    }

    /** Messages d'une conversation, du plus ancien au plus récent. */
    public static function messages($conversationid) {
        global $DB;
        return $DB->get_records(self::TABLE_MSG,
            array('conversationid' => (int)$conversationid), 'id ASC');
    }

    /**
     * Message assistant encore en attente ou en cours de génération, s'il y en
     * a un : sert à empêcher deux envois simultanés et à reprendre l'affichage
     * après un rechargement de page.
     */
    public static function busy_message($conversationid) {
        global $DB;
        list($insql, $params) = $DB->get_in_or_equal(self::BUSY_STATUSES);
        array_unshift($params, (int)$conversationid);
        $rows = $DB->get_records_select(self::TABLE_MSG,
            "conversationid = ? AND status $insql", $params, 'id ASC', '*', 0, 1);
        return empty($rows) ? null : reset($rows);
    }

    /**
     * Enregistre la question de l'élève puis le message assistant qui la suivra.
     *
     * @return \stdClass {usermsgid: int, assistantid: int} identifiants des
     *                   deux lignes créées dans {local_aichat_message}
     */
    public static function add_exchange(\stdClass $conv, $content) {
        global $DB;
        $now = time();

        $user = (object)array(
            'conversationid' => (int)$conv->id,
            'userid'         => (int)$conv->userid,
            'role'           => 'user',
            'content'        => (string)$content,
            'contenthtml'    => null,
            'status'         => 'done',
            'slotid'         => 0,
            'serverid'       => 0,
            'tokens'         => 0,
            'websearches'    => 0,
            'pagereads'      => 0,
            'error'          => null,
            'timecreated'    => $now,
            'timemodified'   => $now,
        );
        $usermsgid = $DB->insert_record(self::TABLE_MSG, $user);

        $assistant = clone $user;
        unset($assistant->id);
        $assistant->role    = 'assistant';
        $assistant->content = null;
        $assistant->status  = 'pending';
        $assistantid = $DB->insert_record(self::TABLE_MSG, $assistant);

        $DB->update_record(self::TABLE, (object)array(
            'id'           => (int)$conv->id,
            'msgcount'     => (int)$conv->msgcount + 2,
            'timemodified' => $now,
        ));

        return (object)array('usermsgid' => (int)$usermsgid, 'assistantid' => (int)$assistantid);
    }

    /** Associe un ticket de file au message assistant. */
    public static function attach_slot($messageid, $slotid) {
        global $DB;
        $DB->update_record(self::TABLE_MSG, (object)array(
            'id'           => (int)$messageid,
            'slotid'       => (int)$slotid,
            'timemodified' => time(),
        ));
    }

    /** Passe un message en cours de génération, sur un serveur donné. */
    public static function start_message($messageid, $serverid) {
        global $DB;
        $DB->update_record(self::TABLE_MSG, (object)array(
            'id'           => (int)$messageid,
            'status'       => 'streaming',
            'serverid'     => (int)$serverid,
            'timemodified' => time(),
        ));
    }

    /** Enregistre la réponse complète et son rendu HTML. */
    public static function finish_message($messageid, $content, $context, $status = 'done') {
        global $DB;
        $content = (string)$content;
        $DB->update_record(self::TABLE_MSG, (object)array(
            'id'           => (int)$messageid,
            'content'      => $content,
            'contenthtml'  => ($content === '') ? null : content::markdown_to_html($content, $context),
            'status'       => (string)$status,
            'error'        => null,
            'timemodified' => time(),
        ));
    }

    /** Marque un message en échec ou annulé, en conservant l'éventuel partiel. */
    public static function fail_message($messageid, $status, $error, $context = null) {
        global $DB;
        $row = $DB->get_record(self::TABLE_MSG, array('id' => (int)$messageid));
        if (!$row) {
            return;
        }
        $update = (object)array(
            'id'           => (int)$messageid,
            'status'       => (string)$status,
            'error'        => ($error === null) ? null : \core_text::substr((string)$error, 0, 900),
            'timemodified' => time(),
        );
        $partial = trim((string)$row->content);
        if ($partial !== '' && $context !== null) {
            $update->contenthtml = content::markdown_to_html($partial, $context);
        }
        $DB->update_record(self::TABLE_MSG, $update);
    }

    /**
     * Enregistre les recherches Web d'une réponse : leur nombre (quota de
     * l'élève, toutes issues confondues) et leur journal (transcription
     * enseignant).
     *
     * Le journal dit aussi à l'enseignant si la recherche était proposée au
     * modèle : « [] » = proposée mais non utilisée ; une entrée « notoffered »
     * = activée sur l'activité mais indisponible pour cette réponse (raison).
     * Rien n'est écrit quand la recherche n'est pas activée.
     *
     * @param int                        $messageid
     * @param \local_aichat\toolset|null $tool      outil(s) proposés pour cette réponse
     * @param string                     $websearch disponibilité (manager::availability())
     */
    public static function record_searches($messageid, $tool, $websearch = 'disabled') {
        global $DB;
        if ($tool !== null) {
            $log      = $tool->log();
            $counters = $tool->counters();
        } else if ($websearch !== '' && $websearch !== 'disabled') {
            $log      = array(array('q' => '', 'status' => 'notoffered', 'reason' => (string)$websearch,
                'results' => 0, 'time' => time()));
            $counters = array('websearches' => 0, 'pagereads' => 0);
        } else {
            return;
        }
        $DB->update_record(self::TABLE_MSG, (object)array(
            'id'          => (int)$messageid,
            'websearches' => (int)$counters['websearches'],
            'pagereads'   => (int)$counters['pagereads'],
            'toolcalls'   => json_encode($log, JSON_UNESCAPED_UNICODE),
        ));
    }

    /**
     * Question de l'élève à laquelle répond le message assistant donné.
     *
     * @param int $conversationid
     * @param int $assistantid
     * @return string
     */
    public static function student_question($conversationid, $assistantid) {
        global $DB;
        $rows = $DB->get_records_select(self::TABLE_MSG, 'conversationid = ? AND id < ? AND role = ?',
            array((int)$conversationid, (int)$assistantid, 'user'), 'id DESC', 'id, content', 0, 1);
        return empty($rows) ? '' : (string)reset($rows)->content;
    }

    /**
     * Journal des recherches Web d'un message.
     *
     * @param \stdClass $row
     * @return array[] {q, status, reason, results, time}
     */
    public static function searches(\stdClass $row) {
        if (empty($row->toolcalls)) {
            return array();
        }
        $log = json_decode((string)$row->toolcalls, true);
        return is_array($log) ? $log : array();
    }

    /** Enregistre le texte partiel reçu jusqu'ici (reprise après coupure). */
    public static function store_partial($messageid, $content) {
        global $DB;
        $DB->set_field(self::TABLE_MSG, 'content', (string)$content, array('id' => (int)$messageid));
    }

    /**
     * Représentation JSON d'un message pour le widget.
     *
     * Le HTML n'est envoyé que pour les messages de l'assistant (markdown
     * assaini côté serveur) ; la question de l'élève est renvoyée en texte brut
     * et sera insérée avec textContent.
     */
    public static function export(\stdClass $row) {
        return array(
            'id'      => (int)$row->id,
            'role'    => (string)$row->role,
            'status'  => (string)$row->status,
            'content' => ($row->role === 'user') ? (string)$row->content : null,
            'html'    => ($row->role === 'assistant') ? (string)$row->contenthtml : null,
            'error'   => ($row->status === 'failed') ? (string)$row->error : null,
            'time'    => (int)$row->timecreated,
        );
    }
}
