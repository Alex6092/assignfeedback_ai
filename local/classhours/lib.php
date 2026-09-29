<?php
defined('MOODLE_INTERNAL') || die();

use local_classhours\access;
use local_classhours\efe_sync;
use local_classhours\grant;

/**
 * Liens de la navigation du cours : « Heures de cours » pour les enseignants
 * qui règlent l'emploi du temps, « Demandes d'accès » (avec le nombre de
 * demandes en attente) pour ceux qui accordent les accès ponctuels.
 *
 * @param navigation_node $navigation
 * @param stdClass        $course
 * @param context_course  $context
 */
function local_classhours_extend_navigation_course(navigation_node $navigation, stdClass $course,
        context_course $context) {
    global $DB;
    if (has_capability('local/classhours:manage', $context)) {
        $navigation->add(get_string('menu', 'local_classhours'),
            new moodle_url('/local/classhours/manage.php', array('courseid' => $course->id)),
            navigation_node::TYPE_SETTING, null, 'localclasshours', new pix_icon('i/calendar', ''));
    }
    if (has_capability('local/classhours:grantaccess', $context)) {
        $pending = $DB->count_records(grant::TABLE, array('courseid' => $course->id, 'status' => grant::PENDING));
        $label = get_string('requests_menu', 'local_classhours')
            . ($pending > 0 ? ' (' . $pending . ')' : '');
        $navigation->add($label,
            new moodle_url('/local/classhours/requests.php', array('courseid' => $course->id)),
            navigation_node::TYPE_SETTING, null, 'localclasshoursrequests', new pix_icon('i/unlock', ''));
    }
}

/**
 * Verrou de remise hors créneau.
 *
 * La lecture d'un devoir déjà noté est permise hors créneau (voir
 * \local_classhours\access) : il faut donc bloquer explicitement toute action
 * qui modifie une remise. Le traitement d'un envoi de formulaire a lieu dans
 * mod/assign/view.php APRÈS require_login(), il est donc bien intercepté ici.
 * Un accès ponctuel accordé par l'enseignant lève le verrou.
 */
function local_classhours_after_require_login($courseorid = null, $autologinguest = null, $cm = null,
        $setwantsurltome = null, $preventredirect = null) {
    global $SCRIPT, $USER;
    if (!$cm || ($cm->modname ?? '') !== 'assign' || $SCRIPT !== '/mod/assign/view.php') {
        return;
    }
    $action = optional_param('action', '', PARAM_ALPHA);
    if (!in_array($action, access::WRITE_ACTIONS, true) || !access::is_closed_for($cm, (int)$USER->id)) {
        return;
    }
    redirect(new moodle_url('/mod/assign/view.php', array('id' => $cm->id)),
        get_string('submit_closed', 'local_classhours'), null, \core\output\notification::NOTIFY_WARNING);
}

/**
 * Même verrou pour les services web (application mobile) : les fonctions qui
 * modifient une remise sont refusées hors créneau. Renvoie false pour laisser
 * passer tout le reste (contrat de override_webservice_execution).
 *
 * @param stdClass $externalfunctioninfo ligne de external_functions
 * @param array    $params paramètres, dans l'ordre de déclaration
 * @return false
 * @throws moodle_exception
 */
function local_classhours_override_webservice_execution($externalfunctioninfo, $params) {
    global $USER;
    // Position du paramètre qui porte l'identifiant du devoir.
    $functions = array(
        'mod_assign_save_submission'       => 0,
        'mod_assign_submit_for_grading'    => 0,
        'mod_assign_copy_previous_attempt' => 0,
        'mod_assign_start_submission'      => 0,
        'mod_assign_remove_submission'     => 1,
    );
    $name = $externalfunctioninfo->name ?? '';
    if (!isset($functions[$name]) || !isset($params[$functions[$name]])) {
        return false;
    }
    $cm = get_coursemodule_from_instance('assign', (int)$params[$functions[$name]], 0, false, IGNORE_MISSING);
    if ($cm && access::is_closed_for($cm, (int)$USER->id)) {
        throw new moodle_exception('submit_closed', 'local_classhours');
    }
    return false;
}

/**
 * Après l'enregistrement du formulaire d'une activité : l'option EFE est
 * réévaluée pour elle, en fin de requête (voir efe_sync::defer_cm).
 *
 * @param stdClass $data   données du formulaire ($data->coursemodule = cmid)
 * @param stdClass $course
 * @return stdClass $data inchangé
 */
function local_classhours_coursemodule_edit_post_actions($data, $course) {
    if (!empty($data->coursemodule)) {
        efe_sync::defer_cm((int)$data->coursemodule);
    }
    return $data;
}
