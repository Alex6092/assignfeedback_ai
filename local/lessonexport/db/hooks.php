<?php
defined('MOODLE_INTERNAL') || die();

$callbacks = array(
    array(
        'hook'     => \core\hook\output\after_standard_main_region_html_generation::class,
        'callback' => array(\local_lessonexport\hook_callbacks::class, 'after_main_region'),
    ),
);
