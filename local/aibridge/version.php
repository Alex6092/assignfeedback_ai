<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Pont IA : services web qui permettent à un agent (Claude Code, Codex) de
 * lire un cours Moodle dans le détail, réglages de nos plugins compris, via
 * le serveur MCP local tools/moodle-mcp, puis d'y créer des brouillons.
 *
 * Lecture : aucune donnée nominative d'élève n'est renvoyée (ni noms, ni
 * copies, ni notes, ni tiers-temps individuels), et aucun secret (clés
 * d'API, mots de passe de test).
 *
 * Écriture (0.2) : désactivée par défaut, limitée aux catégories bac à sable,
 * tout est créé masqué ; le pont ne supprime que ses propres brouillons.
 *
 * Les plugins lus (assignfeedback_ai, local_aichat, local_efenotes,
 * local_classhours, local_attemptcheck, block_opnsenseaccess,
 * block_avancementlapro) sont détectés à l'exécution : aucun n'est requis.
 */
$plugin->component = 'local_aibridge';
$plugin->version   = 2026101001;       // YYYYMMDDXX
$plugin->requires  = 2025041400;       // Moodle 5.0 (banques de questions en activités mod_qbank)
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = '0.3.0';          // Écriture : pages de leçon, questions, structure des tests, surveillance, Avancement
