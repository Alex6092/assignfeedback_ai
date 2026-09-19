<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Caches de local_aichat.
 *
 * searchcache : résultats de recherche Web (Brave), partagés entre tous les
 * élèves. Une même requête posée par plusieurs tuteurs ne coûte qu'une
 * recherche. La durée de validité est un réglage (vérifiée à la lecture), pas
 * un TTL de définition : la modifier ne demande aucune purge.
 */
$definitions = array(
    'searchcache' => array(
        'mode'               => cache_store::MODE_APPLICATION,
        'simplekeys'         => true,
        'simpledata'         => false,
        'staticacceleration' => false,
    ),
);
