<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Pont IA : services web qui permettent à un agent (Claude Code, Codex) de
 * lire un cours Moodle dans le détail, réglages de nos plugins compris, via
 * le serveur MCP local tools/moodle-mcp.
 *
 * Phase 1 : lecture seule. Aucune donnée nominative d'élève n'est renvoyée
 * (ni noms, ni copies, ni notes, ni tiers-temps individuels), et aucun
 * secret (clés d'API, mots de passe de test).
 *
 * Les plugins lus (assignfeedback_ai, local_aichat, local_efenotes,
 * local_classhours, local_attemptcheck, block_opnsenseaccess,
 * block_avancementlapro) sont détectés à l'exécution : aucun n'est requis.
 */
$plugin->component = 'local_aibridge';
$plugin->version   = 2026100900;       // YYYYMMDDXX
$plugin->requires  = 2025041400;       // Moodle 5.0 (banques de questions en activités mod_qbank)
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = '0.1.0';          // Lecture seule : site, cours, activités, banques de questions
