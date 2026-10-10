// Déploiement d'un module du skill moodle-ciel à partir de son manifeste
// (module.deploy.json) : section, puis chaque activité dans l'ordre, avec ses
// contenus (leçon, questions, TP), ses réglages de rôle et ses extensions ;
// enfin relecture de ce que Moodle a enregistré et rapport d'écart.
//
// Tout est créé masqué, dans un cours bac à sable : ce sont les garde-fous du
// pont, vérifiés par Moodle. Avec dryRun, rien n'est écrit : le plan est
// calculé et chaque création est simulée par Moodle quand elle peut l'être.

import fs from 'node:fs/promises';
import path from 'node:path';
import { extractBlocks, readField } from './content.js';
import { ROLE_MODULES, roleSettings, shuffles } from './roles.js';

const QUIZ_ROLES = new Set(['entrainement', 'quiz-ia', 'sommatif', 'finale']);
const AI_QTYPES = new Set(['aiessay', 'aishortanswer']);

/**
 * Titre d'une page de leçon : le texte du premier <h1>, sans l'emoji de tête
 * ni le préfixe « Module n — » (convention du skill).
 *
 * @param {string} html
 * @param {string} fallback
 */
export function pageTitle(html, fallback) {
  const match = /<h1[^>]*>([\s\S]*?)<\/h1>/i.exec(html);
  if (!match) {
    return fallback;
  }
  const text = match[1].replace(/<[^>]+>/g, '').replace(/&amp;/g, '&').replace(/&nbsp;/g, ' ')
    .replace(/^[^\p{L}\p{N}]+/u, '')
    .replace(/^Module\s+\S+\s*[—–-]\s*/i, '')
    .trim();
  return text || fallback;
}

/**
 * Sépare un fichier Moodle XML en deux : questions corrigées par IA et autres.
 *
 * @param {string} xml
 * @returns {{regular: string|null, ai: string|null, counts: {regular: number, ai: number}}}
 */
export function splitAiQuestions(xml) {
  const blocks = [...xml.matchAll(/<question\s+type="([^"]+)"\s*>[\s\S]*?<\/question>/g)]
    .filter((m) => m[1] !== 'category');
  const wrap = (list) => (list.length
    ? `<?xml version="1.0" encoding="UTF-8"?>\n<quiz>\n${list.map((m) => m[0]).join('\n')}\n</quiz>\n` : null);
  const ai = blocks.filter((m) => AI_QTYPES.has(m[1]));
  const regular = blocks.filter((m) => !AI_QTYPES.has(m[1]));
  return { regular: wrap(regular), ai: wrap(ai), counts: { regular: regular.length, ai: ai.length } };
}

/** Points de chaque question (defaultgrade) dans l'ordre du fichier, par idnumber. */
export function questionMarks(xml) {
  return [...xml.matchAll(/<question\s+type="([^"]+)"\s*>([\s\S]*?)<\/question>/g)]
    .filter((m) => m[1] !== 'category')
    .map((m) => ({
      idnumber: (/<idnumber>([^<]*)<\/idnumber>/.exec(m[2]) ?? [])[1]?.trim() ?? '',
      maxmark: Number((/<defaultgrade>([^<]*)<\/defaultgrade>/.exec(m[2]) ?? [])[1] ?? 1),
      qtype: m[1],
    }));
}

/**
 * Déploie un module.
 *
 * @param {{callJson: Function}} client
 * @param {string} manifestPath chemin de module.deploy.json
 * @param {{courseid?: number, dryRun?: boolean, log?: Function}} [options]
 */
export async function deployModule(client, manifestPath, { courseid, dryRun = false, log = () => {} } = {}) {
  const file = path.resolve(manifestPath);
  const dir = path.dirname(file);
  const manifest = JSON.parse((await fs.readFile(file, 'utf8')).replace(/^﻿/, ''));
  const target = courseid ?? manifest.course?.id;
  if (!Number.isInteger(target) || target <= 0) {
    throw new Error('Cours cible inconnu : renseigner course.id dans le manifeste, ou courseid.');
  }
  if (manifest.format !== 'moodle-ciel/module@1') {
    throw new Error(`${file} : format « moodle-ciel/module@1 » attendu.`);
  }
  const section = manifest.section?.number;
  const report = { courseid: target, section, dryRun, steps: [], activities: [], warnings: [], differences: [] };
  const step = (what, result) => {
    report.steps.push({ what, result });
    for (const warning of result?.warnings ?? []) {
      report.warnings.push(`${what} : ${warning}`);
    }
    log(what);
    return result;
  };
  const read = (relative) => readField(relative, dir);
  const readRaw = async (relative) => (await fs.readFile(path.resolve(dir, relative), 'utf8')).replace(/^﻿/, '');

  // Pas de doublon : une activité du même nom dans la section est refusée.
  const plan = await client.callJson('local_aibridge_get_course', { courseid: target });
  const existing = (plan.sections ?? []).find((s) => s.number === section)?.cms ?? [];
  const duplicates = manifest.activities.filter((a) => existing.some((cm) => cm.name === a.name)).map((a) => a.name);
  if (duplicates.length) {
    throw new Error(`Déjà présent dans la section ${section} : ${duplicates.join(', ')}. Supprimer les brouillons `
      + '(moodle_delete_draft) ou choisir une autre section.');
  }

  step(`section ${section}`, await client.callJson('local_aibridge_upsert_section', {
    courseid: target, section, name: manifest.section?.name, dryrun: dryRun,
    ...(manifest.section?.summary_file ? { summary: await read(manifest.section.summary_file) } : {}),
  }));

  for (const activity of manifest.activities) {
    const modname = ROLE_MODULES[activity.role];
    if (!modname) {
      report.warnings.push(`${activity.key} : rôle « ${activity.role} » non déployable par le pont (à créer à la main).`);
      continue;
    }
    const entry = { key: activity.key, role: activity.role, name: activity.name, modname };
    report.activities.push(entry);
    const settings = roleSettings(activity, manifest);
    let intro = '';

    // Contenus lus dans les fichiers du module.
    if (activity.role === 'page' && activity.file) {
      settings.content = await read(activity.file);
    }
    if (activity.role === 'label' && activity.file) {
      intro = await read(activity.file);
    }
    if (activity.role === 'tp') {
      const tpdir = activity.dir ?? activity.key;
      settings.activity = await read(path.join(tpdir, 'consigne.html'));
      settings.assignfeedback_ai_exercise = await read(path.join(tpdir, 'enonce.txt'));
      settings.assignfeedback_ai_expectedanswer = await read(path.join(tpdir, 'corrige.txt'));
      settings.assignfeedback_ai_competencies = await read(path.join(tpdir, 'competences.txt'));
      try {
        settings.aichatcustomprompt = await read(path.join(tpdir, 'tuteur.txt'));
      } catch {
        // Facultatif.
      }
    }

    // Questions : importées avant le test, pour que rien ne soit créé si l'import échoue.
    let imported = null;
    let marks = [];
    if (QUIZ_ROLES.has(activity.role)) {
      const xml = await readRaw(activity.questions.file);
      marks = questionMarks(xml);
      const category = activity.questions.category;
      if (activity.role === 'entrainement') {
        const split = splitAiQuestions(xml);
        if (!split.regular) {
          throw new Error(`${activity.questions.file} : aucune question hors IA à tirer au sort.`);
        }
        imported = { regular: null, ai: null };
        imported.regular = step(`${activity.key} : questions → « ${category} »`, await client.callJson(
          'local_aibridge_import_questions', { courseid: target, category, parent: '', xml: split.regular, dryrun: dryRun }));
        if (split.ai) {
          imported.ai = step(`${activity.key} : questions IA → « ${category} — IA »`, await client.callJson(
            'local_aibridge_import_questions',
            { courseid: target, category: `${category} — IA`, parent: dryRun ? '' : category, xml: split.ai, dryrun: dryRun }));
        }
      } else {
        imported = { regular: step(`${activity.key} : questions → « ${category} »`, await client.callJson(
          'local_aibridge_import_questions', { courseid: target, category, parent: '', xml, dryrun: dryRun })) };
      }
    }

    const created = step(`${activity.key} : création (${modname})`, await client.callJson('local_aibridge_create_module', {
      courseid: target, section, modname, name: activity.name, intro, settings: JSON.stringify(settings), dryrun: dryRun,
    }));
    entry.cmid = created.cmid ?? null;
    if (dryRun) {
      entry.settings = Object.keys(settings).length;
      continue;
    }

    // Pages de la leçon.
    if (activity.role === 'lecon') {
      const html = await readRaw(activity.file);
      const pages = extractBlocks(html).map((contents, i) => ({ title: pageTitle(contents, `${activity.name} (${i + 1})`), contents }));
      step(`${activity.key} : ${pages.length} page(s)`, await client.callJson('local_aibridge_set_lesson_pages',
        { cmid: entry.cmid, pages: JSON.stringify(pages) }));
    }

    // Structure du test.
    if (QUIZ_ROLES.has(activity.role)) {
      let slots;
      if (activity.role === 'entrainement') {
        const draw = activity.questions.draw ?? 10;
        slots = [{ random: { categoryid: imported.regular.categoryid }, count: draw, maxmark: 1 }];
        if (imported.ai && activity.questions.draw_ai) {
          slots.push({ random: { categoryid: imported.ai.categoryid }, count: activity.questions.draw_ai, maxmark: 1 });
        }
      } else {
        slots = marks.map((m) => ({ idnumber: m.idnumber, maxmark: m.maxmark }));
        const missing = marks.filter((m) => !m.idnumber).length;
        if (missing) {
          throw new Error(`${activity.questions.file} : ${missing} question(s) sans idnumber ; un test à questions fixes les repère par leur idnumber.`);
        }
      }
      step(`${activity.key} : structure du test`, await client.callJson('local_aibridge_set_quiz_layout', {
        cmid: entry.cmid, layout: JSON.stringify({ slots, shufflequestions: shuffles(activity.role) }),
      }));
    }

    // Extensions hors formulaire : surveillance, Avancement.
    const extras = {};
    if (activity.supervised) {
      extras.supervised = activity.supervised;
    }
    if (activity.minduration_minutes) {
      extras.minduration = Math.round(activity.minduration_minutes * 60);
    }
    extras.avancement = activity.role === 'tp' || !activity.duedate ? {} : { duedate: activity.duedate };
    step(`${activity.key} : extensions`, await client.callJson('local_aibridge_set_module_extras',
      { cmid: entry.cmid, extras: JSON.stringify(extras) }));
  }

  if (!dryRun) {
    await verify(client, manifest, report);
  }
  return report;
}

/** Relit chaque activité créée et compare l'essentiel au manifeste. */
async function verify(client, manifest, report) {
  for (const entry of report.activities) {
    if (!entry.cmid) {
      continue;
    }
    const activity = manifest.activities.find((a) => a.key === entry.key);
    const module = await client.callJson('local_aibridge_get_module', { cmid: entry.cmid });
    const diff = (what, expected, actual) => {
      if (String(expected) !== String(actual)) {
        report.differences.push({ key: entry.key, what, expected, actual });
      }
    };
    diff('masquée', 0, module.cm?.visible);
    diff('nom', activity.name, module.name);
    if (activity.efe) {
      const efe = module.extensions?.efe?.competences_json?.quality ?? [];
      diff('EFE', activity.efe.competences.join(','), efe.join(','));
    }
    if (activity.role === 'tp') {
      diff('Correction IA', 1, module.extensions?.aifeedback?.enabled);
      diff('Tuteur IA', 1, module.extensions?.aichat?.enabled);
    }
    if (QUIZ_ROLES.has(activity.role)) {
      const points = activity.points;
      if (points && activity.role !== 'entrainement') {
        diff('total des points', points, Number(module.instance?.sumgrades));
      }
      if (activity.role === 'entrainement') {
        const expected = (activity.questions.draw ?? 10) + (activity.questions.draw_ai ?? 0);
        diff('questions tirées', expected, module.content?.slotcount);
      }
    }
    if (activity.supervised) {
      diff('surveillée', true, module.extensions?.classhours?.supervised);
      diff('mode examen', activity.supervised.exammode ? 1 : 0, module.extensions?.classhours?.exammode);
    }
    entry.url = module.url;
  }
}
