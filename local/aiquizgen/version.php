<?php
defined('MOODLE_INTERNAL') || die();

$plugin->version   = 2026053023;
$plugin->requires  = 2023042400; // Moodle 4.2
$plugin->component = 'local_aiquizgen';
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = '0.10.3';
$plugin->dependencies = array(
    'local_aifeedback'     => 2026052905, // content_extractor, file d'attente, api::call
    'qtype_aishortanswer'  => 2026052901, // génération de questions à réponse courte IA
    'qtype_aiessay'        => 2026052902, // génération de compositions IA
    // Dépendances OPTIONNELLES (détectées au runtime via core_component) :
    //   - qtype_answersselect (Joseph Rézeau) : QCM à pool aléatoire
    //   - qtype_coderunner    (Richard Lobb)  : exercices de programmation
    //                                            exécutés en sandbox Jobe
    // Si présents, leurs champs s'activent dans le formulaire de génération.
);
