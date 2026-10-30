<?php
defined('MOODLE_INTERNAL') || die();

$definitions = array(
    // Catalogue des modèles du load balancer (GET /v1/models).
    'lbmodels' => array(
        'mode'       => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'ttl'        => 600,
    ),
);
