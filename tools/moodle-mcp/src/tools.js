// Outils MCP exposés à l'agent : lecture, et écriture en bac à sable quand
// MOODLE_MCP_READONLY=0. Les garde-fous de l'écriture (réglage du site,
// catégories bac à sable, masquage, suppression des seuls brouillons) sont
// vérifiés par Moodle : ces outils ne font que transmettre.

import path from 'node:path';
import { z } from 'zod';
import { withFiles, readField } from './content.js';
import { errorResult, textResult, truncateStrings } from './shape.js';
import { snapshotCourse } from './snapshot.js';

/** Longueur au-delà de laquelle un contenu est tronqué (mode html: "truncate"). */
export const TRUNCATE_AT = 1500;

const READ = { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: true };
const CREATE = { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: true };
const DELETE = { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: true };

const dryRun = z.boolean().default(false)
  .describe('Simulation : Moodle vérifie tout (droits, bac à sable, réglages) et renvoie ce qui serait fait, sans rien écrire.');

const htmlMode = z.enum(['truncate', 'full']).default('truncate')
  .describe('« truncate » (défaut) coupe les contenus longs ; « full » les renvoie en entier (réponse volumineuse).');

/**
 * Enregistre les outils sur le serveur MCP.
 *
 * @param {import('@modelcontextprotocol/sdk/server/mcp.js').McpServer} server
 * @param {import('./moodle-client.js').MoodleClient} client
 * @param {{log?: (msg: string) => void, readonly?: boolean}} [options]
 */
export function registerTools(server, client, { log = () => {}, readonly = true } = {}) {
  const safe = (fn) => async (args) => {
    try {
      return await fn(args ?? {});
    } catch (error) {
      return errorResult(error);
    }
  };
  const shaped = (data, html) => textResult(html === 'full' ? data : truncateStrings(data, TRUNCATE_AT));

  server.registerTool('moodle_site_info', {
    title: 'Moodle : informations du site',
    description: 'Version de Moodle, plugins du projet installés (IA, heures de cours, EFE...) avec leur version, '
      + 'réglages utiles à la conception d\'un parcours, thème et classes CSS des parcours présentes, état du pont '
      + '(écriture, bac à sable) et compte du jeton. À appeler en premier.',
    inputSchema: {
      theme_css: z.boolean().default(false)
        .describe('Renvoyer le SCSS du thème en entier (sinon seulement sa taille).'),
    },
    annotations: READ,
  }, safe(async ({ theme_css: themeCss }) => {
    const site = await client.callJson('local_aibridge_get_site_info');
    if (!themeCss && site.theme) {
      site.theme.scsspre = `[${(site.theme.scsspre ?? '').length} caractères — theme_css: true pour l'obtenir]`;
      site.theme.scss = `[${(site.theme.scss ?? '').length} caractères — theme_css: true pour l'obtenir]`;
    }
    return textResult(site);
  }));

  server.registerTool('moodle_list_courses', {
    title: 'Moodle : lister les cours',
    description: 'Cours que le pont peut lire, avec leur catégorie. Filtre texte sur le nom complet, le nom abrégé '
      + 'et le numéro d\'identification.',
    inputSchema: {
      search: z.string().default('').describe('Texte à chercher (vide : tous les cours).'),
    },
    annotations: READ,
  }, safe(async ({ search }) => textResult(await client.callJson('local_aibridge_list_courses', { search }))));

  server.registerTool('moodle_get_course', {
    title: 'Moodle : plan d\'un cours',
    description: 'Réglages d\'un cours (format, achèvement, groupes, blocs, carnet de notes), heures de cours, '
      + 'puis le plan : sections et activités dans l\'ordre, conditions d\'accès décodées et résumé des extensions '
      + 'de chaque activité (Correction IA, Tuteur IA, EFE, activité surveillée, verrous...).',
    inputSchema: {
      courseid: z.number().int().positive().describe('Identifiant du cours.'),
      html: htmlMode,
    },
    annotations: READ,
  }, safe(async ({ courseid, html }) => shaped(await client.callJson('local_aibridge_get_course', { courseid }), html)));

  server.registerTool('moodle_get_module', {
    title: 'Moodle : détail d\'une activité',
    description: 'Tout le détail d\'une activité : réglages communs (achèvement, accès, groupes), enregistrement de '
      + 'l\'instance (dates, tentatives, délais...), éléments de note, contenu propre au type (pages de leçon avec '
      + 'sauts, structure du test avec questions et options IA, relecture décodée, plugins du devoir, grille '
      + 'd\'évaluation...) et réglages des extensions (Correction IA, Tuteur IA et son brief, EFE, activité '
      + 'surveillée, durée minimale...).',
    inputSchema: {
      cmid: z.number().int().positive().describe('Identifiant de l\'activité (course_modules.id, « id » de l\'URL view.php).'),
      html: htmlMode,
    },
    annotations: READ,
  }, safe(async ({ cmid, html }) => shaped(await client.callJson('local_aibridge_get_module', { cmid }), html)));

  server.registerTool('moodle_get_questions', {
    title: 'Moodle : banques de questions',
    description: 'Banques de questions d\'un cours (activités Banque de questions et banques des tests), catégories, '
      + 'questions (dernière version, étiquettes) et, sur demande, leur export Moodle XML (options IA comprises, '
      + 'sans clé d\'API).',
    inputSchema: {
      courseid: z.number().int().positive(),
      categoryids: z.array(z.number().int().positive()).default([]).describe('Catégories (vide : toutes).'),
      questionids: z.array(z.number().int().positive()).default([]).describe('Questions précises (vide : toutes).'),
      include_banks: z.boolean().default(true).describe('Renvoyer l\'inventaire des banques et catégories.'),
      include_xml: z.boolean().default(false).describe('Renvoyer l\'export Moodle XML des questions sélectionnées.'),
      html: htmlMode,
    },
    annotations: READ,
  }, safe(async ({ courseid, categoryids, questionids, include_banks: includebanks, include_xml: includexml, html }) => {
    const data = await client.callJson('local_aibridge_get_questions', {
      courseid, categoryids, questionids, includebanks, includexml,
    });
    // Le XML est toujours renvoyé en entier : tronqué, il serait inutilisable.
    const xml = data.xml;
    delete data.xml;
    const shapedData = html === 'full' ? data : truncateStrings(data, TRUNCATE_AT);
    if (xml !== undefined) {
      shapedData.xml = xml;
    }
    return textResult(shapedData);
  }));

  server.registerTool('moodle_get_efe_competences', {
    title: 'Moodle : référentiel EFE',
    description: 'Compétences du référentiel EFE proposées par local_efenotes (N1 = compétences C01..., N2 = '
      + 'critères Cxx.n, N3 = tâches et items des enseignants). Codes à utiliser pour le report EFE d\'une activité.',
    inputSchema: {
      levels: z.array(z.enum(['n1', 'n2', 'n3'])).default(['n1', 'n2'])
        .describe('Niveaux à renvoyer (n3 peut être long).'),
    },
    annotations: READ,
  }, safe(async ({ levels }) => {
    const data = await client.callJson('local_aibridge_get_efe_competences');
    for (const level of ['n1', 'n2', 'n3']) {
      if (!levels.includes(level)) {
        data[level] = `[${(data[level] ?? []).length} éléments — ajouter « ${level} » à levels pour les obtenir]`;
      }
    }
    return textResult(data);
  }));

  server.registerTool('moodle_snapshot_course', {
    title: 'Moodle : photographier un cours',
    description: 'Écrit sur le disque tout ce que le pont sait lire d\'un cours : plan (README.md), réglages, détail '
      + 'JSON de chaque activité, contenus HTML bruts (pages de leçon, pages, consignes), questions de chaque test et '
      + 'de chaque banque en Moodle XML, SCSS du thème, référentiel EFE. Sert à comparer Moodle aux fichiers sources '
      + 'd\'un parcours. Ne modifie rien dans Moodle ; écrase un snapshot précédent du même cours.',
    inputSchema: {
      courseid: z.number().int().positive(),
      outdir: z.string().min(1).describe('Dossier de destination (absolu de préférence) ; un sous-dossier au nom '
        + 'abrégé du cours y est créé.'),
    },
    annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: true },
  }, safe(async ({ courseid, outdir }) => {
    const result = await snapshotCourse(client, courseid, path.resolve(outdir), { log });
    return textResult({
      dossier: result.root,
      activites: result.modules,
      fichiers: result.files,
      avertissements: result.warnings,
      suite: 'Lire README.md dans le dossier pour le plan du cours et les liens vers chaque fichier.',
    });
  }));

  if (!readonly) {
    registerWriteTools(server, client, safe);
  }
}

/**
 * Outils d'écriture (bac à sable). Tout ce qu'ils créent est masqué ; seul ce
 * qu'ils ont créé, encore masqué, peut être supprimé.
 */
function registerWriteTools(server, client, safe) {
  const call = async (fn, params) => textResult(await client.callJson(fn, params));

  server.registerTool('moodle_create_course', {
    title: 'Moodle : créer un cours (bac à sable)',
    description: 'Crée un cours MASQUÉ dans une catégorie bac à sable du pont : format Thématique, achèvement '
      + 'activé. Refusé hors bac à sable ou si l\'écriture est désactivée sur le site.',
    inputSchema: {
      categoryid: z.number().int().positive().describe('Catégorie bac à sable (voir moodle_site_info, bridge.sandboxcategories).'),
      fullname: z.string().min(1),
      shortname: z.string().min(1).describe('Unique sur le site.'),
      idnumber: z.string().default(''),
      summary: z.string().default('').describe('Résumé HTML.'),
      numsections: z.number().int().min(0).max(52).default(0).describe('Sections à créer en plus de la section 0.'),
      startdate: z.string().regex(/^\d{4}-\d{2}-\d{2}$/).optional().describe('Date de début AAAA-MM-JJ (défaut : aujourd\'hui).'),
      dry_run: dryRun,
    },
    annotations: CREATE,
  }, safe(async ({ categoryid, fullname, shortname, idnumber, summary, numsections, startdate, dry_run: dryrun }) => call(
    'local_aibridge_create_course',
    { categoryid, fullname, shortname, idnumber, summary, numsections, startdate: startdate ? localMidnight(startdate) : 0, dryrun },
  )));

  server.registerTool('moodle_upsert_section', {
    title: 'Moodle : créer ou renommer une section',
    description: 'Crée la section n° « section » si elle manque (MASQUÉE, avec celles qui la précèdent), puis règle '
      + 'son nom et son résumé. Une section existante garde sa visibilité.',
    inputSchema: {
      courseid: z.number().int().positive(),
      section: z.number().int().min(0).max(100),
      name: z.string().optional().describe('Nom (absent : inchangé). Convention : « M2 - Titre du module ».'),
      summary: z.string().optional().describe('Résumé HTML (absent : inchangé).'),
      summary_file: z.string().optional().describe('Fichier du résumé (HTML : contenu entre les marqueurs).'),
      dry_run: dryRun,
    },
    annotations: CREATE,
  }, safe(async ({ courseid, section, name, summary, summary_file: summaryFile, dry_run: dryrun }) => {
    const text = summaryFile ? await readField(summaryFile) : summary;
    return call('local_aibridge_upsert_section', { courseid, section, name, summary: text, dryrun });
  }));

  server.registerTool('moodle_create_module', {
    title: 'Moodle : créer une activité (bac à sable)',
    description: 'Crée une activité MASQUÉE (page, label, url, lesson, quiz, assign) dans une section d\'un cours bac à '
      + 'sable. « settings » reçoit les champs du formulaire de l\'activité (noms des champs Moodle : attempts, delay1, '
      + 'duedate, assignsubmission_onlinetext_enabled, aichatenabled, efenotes_enabled, efenotes_competence...). '
      + 'Dates au format AAAA-MM-JJTHH:MM (heure de Paris) ; « grade »: {"scale": "Barème officiel"} pour un barème ; '
      + '« activity » pour les instructions d\'un devoir. « settings_files » lit un champ dans un fichier (HTML : '
      + 'contenu entre les marqueurs). Utiliser d\'abord dry_run pour voir les champs transmis.',
    inputSchema: {
      courseid: z.number().int().positive(),
      section: z.number().int().min(0).max(100),
      modname: z.enum(['page', 'label', 'url', 'lesson', 'quiz', 'assign']),
      name: z.string().describe('Nom affiché (peut être vide pour une étiquette).'),
      intro: z.string().default('').describe('Description HTML.'),
      intro_file: z.string().optional().describe('Fichier de la description (remplace « intro »).'),
      settings: z.record(z.string(), z.unknown()).default({}).describe('Champs du formulaire de l\'activité.'),
      settings_files: z.record(z.string(), z.string()).default({})
        .describe('Champ → fichier, ex. {"content": "M5/page.html", "assignfeedback_ai_expectedanswer": "M5/tp1/corrige.txt"}.'),
      dry_run: dryRun,
    },
    annotations: CREATE,
  }, safe(async (args) => {
    const intro = args.intro_file ? await readField(args.intro_file) : args.intro;
    const settings = await withFiles(args.settings, args.settings_files);
    return call('local_aibridge_create_module', {
      courseid: args.courseid,
      section: args.section,
      modname: args.modname,
      name: args.name,
      intro,
      settings: JSON.stringify(settings),
      dryrun: args.dry_run,
    });
  }));

  server.registerTool('moodle_delete_draft', {
    title: 'Moodle : supprimer un brouillon du pont',
    description: 'Supprime un objet que le pont a créé et qui est encore MASQUÉ : activité (cm), section vide '
      + '(section : id de course_sections) ou cours entier (course : seulement s\'il ne contient que des brouillons '
      + 'du pont). Tout objet publié ou créé à la main est refusé par Moodle.',
    inputSchema: {
      objecttype: z.enum(['cm', 'section', 'course']),
      objectid: z.number().int().positive(),
      dry_run: dryRun,
    },
    annotations: DELETE,
  }, safe(async ({ objecttype, objectid, dry_run: dryrun }) => call(
    'local_aibridge_delete_draft', { objecttype, objectid, dryrun },
  )));
}

/** « AAAA-MM-JJ » → horodatage de minuit, heure locale du poste. */
export function localMidnight(date) {
  const [year, month, day] = date.split('-').map(Number);
  return Math.floor(new Date(year, month - 1, day).getTime() / 1000);
}
