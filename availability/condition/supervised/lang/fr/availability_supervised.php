<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Activité surveillée';
$string['title'] = 'Activité surveillée';
$string['description'] = 'Accès seulement quand l\'enseignant ouvre l\'activité en classe (page « Activités surveillées »).';
$string['privacy:metadata'] = 'La condition Activité surveillée ne stocke aucune donnée personnelle.';

// Description affichée aux utilisateurs.
$string['desc_closed'] = 'Ouverte seulement quand l\'enseignant la lance <strong>en classe</strong>';
$string['desc_open'] = '<strong>Ouverte</strong> par l\'enseignant';
$string['desc_open_until'] = '<strong>Ouverte</strong> par l\'enseignant jusqu\'à <strong>{$a}</strong>';
$string['desc_not'] = 'En dehors d\'une <strong>séance surveillée</strong>';
$string['desc_full'] = '<strong>Activité surveillée</strong> : ouverte par l\'enseignant en classe';
$string['state_closed'] = 'actuellement fermée';
$string['state_open'] = 'actuellement ouverte';
$string['state_open_until'] = 'ouverte jusqu\'à {$a}';
$string['manage_link'] = 'Ouvrir / fermer';

// Formulaire de restriction d'accès.
$string['form_label'] = 'Activité surveillée';
$string['form_help'] = 'Fermée tant que l\'enseignant ne l\'ouvre pas.';
