<?php
defined('MOODLE_INTERNAL') || die();

$observers = array(
    array(
        'eventname' => '\mod_assign\event\assessable_submitted',
        'callback'  => '\assignfeedback_ai\observer::assessable_submitted',
        'priority'  => 9999,
        'internal'  => false,
    ),
    // Garantit la sauvegarde de la config par devoir, même quand mod_assign
    // n'appelle pas save_settings() (cas du tout premier enregistrement d'un devoir
    // où le check is_enabled() retourne false avant que la config existe en base).
    array(
        'eventname' => '\core\event\course_module_created',
        'callback'  => '\assignfeedback_ai\observer::course_module_changed',
        'priority'  => 9999,
        'internal'  => false,
    ),
    array(
        'eventname' => '\core\event\course_module_updated',
        'callback'  => '\assignfeedback_ai\observer::course_module_changed',
        'priority'  => 9999,
        'internal'  => false,
    ),
);
