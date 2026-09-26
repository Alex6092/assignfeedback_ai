<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Notification envoyée aux enseignants quand une tentative ou une remise
 * déclenche un indicateur. Activée par défaut dans les notifications du site
 * (cloche) ; l'envoi par courriel reste au choix de chaque destinataire.
 */
$messageproviders = array(
    'suspicious' => array(
        'capability' => 'local/attemptcheck:notify',
        'defaults'   => array(
            'popup' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
            'email' => MESSAGE_PERMITTED,
        ),
    ),
);
