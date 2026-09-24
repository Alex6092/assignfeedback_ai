<?php
defined('MOODLE_INTERNAL') || die();

$callbacks = array(
    // Entrée « MoodleSearch » dans le menu principal.
    array(
        'hook'     => \core\hook\navigation\primary_extend::class,
        'callback' => '\local_moodlesearch\hook_callbacks::primary_extend',
    ),
);
