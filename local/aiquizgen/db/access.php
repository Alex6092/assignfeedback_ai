<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Capabilities du plugin local_aiquizgen.
 *
 * - local/aiquizgen:generate : générer un test IA dans le cours courant
 *   (crée une catégorie + des questions + un quiz). Risque XSS car le LLM
 *   produit du HTML qu'on insère dans la banque ; risque SPAM car ça crée
 *   des activités. Réservé aux enseignants éditeurs et managers.
 */
$capabilities = array(

    'local/aiquizgen:generate' => array(
        'riskbitmask'  => RISK_SPAM | RISK_XSS,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => array(
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ),
    ),

);
