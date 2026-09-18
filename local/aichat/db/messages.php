<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Notification envoyée aux enseignants quand un message d'élève au tuteur est
 * signalé par la modération. Activée par défaut dans les notifications du site
 * (cloche) ; l'envoi par courriel reste au choix de chaque destinataire.
 */
$messageproviders = array(
    'flagged' => array(
        'capability' => 'local/aichat:viewconversations',
        'defaults'   => array(
            'popup' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
            'email' => MESSAGE_PERMITTED,
        ),
    ),
);
