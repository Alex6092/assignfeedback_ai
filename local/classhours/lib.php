<?php
defined('MOODLE_INTERNAL') || die();

use local_classhours\access;
use local_classhours\efe_sync;
use local_classhours\gate;
use local_classhours\grant;
use local_classhours\plan;

/**
 * Liens de la navigation du cours : « Heures de cours » pour les enseignants
 * qui règlent l'emploi du temps, « Activités surveillées » pour ceux qui les
 * ouvrent ou les choisissent, « Demandes d'accès » (avec le nombre de
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
    if (has_capability('local/classhours:supervise', $context) || has_capability('local/classhours:manage', $context)) {
        $navigation->add(get_string('supervised_menu', 'local_classhours'),
            new moodle_url('/local/classhours/supervised.php', array('courseid' => $course->id)),
            navigation_node::TYPE_SETTING, null, 'localclasshourssupervised', new pix_icon('i/lock', ''));
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
    local_classhours_exam_guard($courseorid, $cm, !empty($preventredirect));
    if (!$cm || ($cm->modname ?? '') !== 'assign' || $SCRIPT !== '/mod/assign/view.php') {
        return;
    }
    $action = optional_param('action', '', PARAM_ALPHA);
    if (!in_array($action, access::WRITE_ACTIONS, true)) {
        return;
    }
    $reason = access::closed_reason($cm, (int)$USER->id);
    if ($reason === null) {
        return;
    }
    redirect(new moodle_url('/mod/assign/view.php', array('id' => $cm->id)),
        get_string(local_classhours_closed_message($reason), 'local_classhours'), null,
        \core\output\notification::NOTIFY_WARNING);
}

/**
 * Mode examen d'une activité surveillée : pendant la séance, toute autre page
 * du cours (activités, page du cours, fichiers — pluginfile passe aussi par
 * require_login) renvoie l'élève vers l'activité d'examen. Restent ouvertes
 * l'activité d'examen elle-même (et la saisie de son code de séance) et une
 * autre activité surveillée ouverte pour lui. En AJAX ou en service web, la
 * requête est refusée.
 *
 * @param stdClass|int|null $courseorid
 * @param cm_info|stdClass|null $cm
 * @param bool $preventredirect
 * @throws moodle_exception en AJAX ou en service web
 */
function local_classhours_exam_guard($courseorid, $cm, bool $preventredirect): void {
    global $USER, $SCRIPT;
    if (!isloggedin() || isguestuser() || $SCRIPT === '/local/classhours/code.php') {
        return;
    }
    $courseid = is_object($courseorid) ? (int)$courseorid->id : (int)$courseorid;
    if ($courseid <= 0 && $cm) {
        $courseid = (int)$cm->course;
    }
    if ($courseid <= SITEID) {
        return;
    }
    $examcmid = access::exam_cmid($courseid, (int)$USER->id);
    if ($examcmid === null) {
        return;
    }
    if ($cm && ((int)$cm->id === $examcmid || (gate::is_supervised_json($cm->availability ?? null)
            && gate::in_window($courseid, (int)$cm->id, (int)$USER->id)))) {
        return;
    }
    if ($preventredirect) {
        throw new moodle_exception('exam_locked', 'local_classhours');
    }
    $cms = get_fast_modinfo($courseid)->get_cms();
    $exam = $cms[$examcmid] ?? null;
    $url = ($exam && $exam->url) ? $exam->url : new moodle_url('/course/view.php', array('id' => $courseid));
    redirect($url, get_string('exam_redirect', 'local_classhours'), null, \core\output\notification::NOTIFY_WARNING);
}

/**
 * Message du verrou de remise selon la raison de la fermeture.
 *
 * @param string $reason classhours|supervised (voir access::closed_reason)
 * @return string clé de chaîne
 */
function local_classhours_closed_message(string $reason): string {
    return $reason === 'supervised' ? 'submit_closed_supervised' : 'submit_closed';
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
    $reason = $cm ? access::closed_reason($cm, (int)$USER->id) : null;
    if ($reason !== null) {
        throw new moodle_exception(local_classhours_closed_message($reason), 'local_classhours');
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

/**
 * Suppression d'une activité : ses réglages d'activité surveillée (options,
 * dates prévues) partent avec elle.
 *
 * @param stdClass $cm
 */
function local_classhours_pre_course_module_delete($cm) {
    if (!empty($cm->id)) {
        plan::delete_for_cm((int)$cm->id);
    }
}
