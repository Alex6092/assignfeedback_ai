<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Le widget du tuteur est injecté en pied de page, ce qui le rend indépendant
 * du thème et des régions de blocs disponibles sur les pages d'activité.
 */
$callbacks = array(
    array(
        'hook'     => \core\hook\output\before_footer_html_generation::class,
        'callback' => \local_aichat\hook_callbacks::class . '::before_footer_html_generation',
        'priority' => 0,
    ),
);
