<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Restriction par heures de cours';
$string['title'] = 'Heures de cours';
$string['description'] = 'Accès seulement pendant les créneaux de classe du cours (page « Heures de cours »).';
$string['privacy:metadata'] = 'La restriction par heures de cours ne stocke aucune donnée personnelle.';

// Description affichée aux utilisateurs.
$string['desc'] = 'Pendant les <strong>heures de cours</strong>';
$string['desc_slots'] = 'Pendant les heures de cours : <strong>{$a}</strong>';
$string['desc_not'] = 'En dehors des <strong>heures de cours</strong>';
$string['desc_not_slots'] = 'En dehors des heures de cours : <strong>{$a}</strong>';
$string['desc_noslots'] = '(aucun créneau n\'est encore configuré)';
$string['desc_next'] = '— prochain créneau : <strong>{$a}</strong>';
$string['request_link'] = 'Demander un accès exceptionnel';
$string['request_pending'] = 'Demande d\'accès en attente';

// Formulaire de restriction d'accès.
$string['form_label'] = 'Pendant les heures de cours';
$string['configure'] = 'Configurer les horaires';
$string['noslots_form'] = 'Aucun créneau n\'est encore configuré : l\'activité restera fermée aux élèves.';
$string['efe_form'] = 'Ajoutée automatiquement pour la remontée EFE. Pour retirer cette activité, cochez « Exclure » sur la page Heures de cours : sinon la restriction revient.';
