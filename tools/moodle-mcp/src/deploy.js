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

const QUIZ_ROLES = new Set(['entrainement', 'td', 'quiz-ia', 'sommatif', 'finale']);
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

/** Images d'une leçon envoyées au pont : mêmes noms admis et mêmes limites que lesson_writer.php. */
export const LESSON_IMAGES = { maxFileBytes: 2 * 1024 * 1024, maxTotalBytes: 4 * 1024 * 1024, maxFiles: 40 };
const IMAGE_NAME = /^[\p{L}\p{N}][\p{L}\p{N}._-]{0,99}\.(png|jpe?g|gif|svg)$/iu;
const mo = (bytes) => `${(bytes / 1048576).toFixed(1).replace('.', ',')} Mo`;

/** %xx décodé ; tel quel si l'encodage est invalide. */
function decodePath(text) {
  try {
    return decodeURIComponent(text);
  } catch {
    return text;
  }
}

/**
 * src relatifs des images d'un fragment HTML, commentaires exclus : ni URL
 * (http:, data:…), ni chemin depuis la racine du site, ni @@PLUGINFILE@@.
 *
 * @param {string} html
 * @returns {string[]}
 */
export function relativeImageSources(html) {
  const sources = [];
  for (const [tag] of String(html).replace(/<!--[\s\S]*?-->/g, '').matchAll(/<img\b[^>]*>/gi)) {
    const src = /\ssrc\s*=\s*(["'])([\s\S]*?)\1/i.exec(tag)?.[2].replace(/&amp;/g, '&').trim();
    if (src && !/^([a-z][a-z0-9+.-]*:|\/|#|@@)/i.test(src)) {
      sources.push(src);
    }
  }
  return sources;
}

/**
 * Pages d'une leçon (un bloc entre marqueurs = une page) et images qu'elles
 * citent en relatif, lues à côté du fichier, pour local_aibridge_set_lesson_pages.
 * Le pont range chaque image dans la zone de fichiers de la page qui la cite,
 * sous son seul nom (images/schema.png → schema.png) : deux fichiers différents
 * de même nom sont refusés.
 *
 * @param {string} file lecon.html
 * @param {{baseDir?: string, fallback?: (i: number) => string}} [options] fallback : titre d'une page sans <h1>
 * @returns {Promise<{pages: {title: string, contents: string}[], files: {filename: string, content: string}[]}>}
 *   files : chaque image une seule fois, contenu en base64
 */
export async function readLesson(file, { baseDir = process.cwd(), fallback = (i) => `Page ${i + 1}` } = {}) {
  const full = path.resolve(baseDir, file);
  const blocks = extractBlocks((await fs.readFile(full, 'utf8')).replace(/^﻿/, ''));
  if (!blocks.length) {
    throw new Error(`${file} : aucun bloc entre les marqueurs ▶ DÉBUT / FIN CONTENU MOODLE ◀.`);
  }
  const samePath = process.platform === 'win32' ? (a, b) => a.toLowerCase() === b.toLowerCase() : (a, b) => a === b;
  const images = new Map();
  for (const [i, block] of blocks.entries()) {
    for (const src of relativeImageSources(block)) {
      const where = `${file}, page ${i + 1}, image « ${src} »`;
      const relative = decodePath(src.replace(/[?#][\s\S]*$/, ''));
      const name = relative.split(/[\\/]/).pop();
      if (!IMAGE_NAME.test(name)) {
        throw new Error(`${where} : nom refusé par le pont (lettres, chiffres, point, tiret, tiret bas ; png, jpg, jpeg, gif `
          + 'ou svg) ; renommer le fichier.');
      }
      const imagePath = path.resolve(path.dirname(full), relative);
      const known = images.get(name);
      if (known) {
        if (!samePath(known.path, imagePath)) {
          throw new Error(`${where} : même nom que « ${known.src} » pour un autre fichier ; dans Moodle, les images d'une `
            + 'leçon sont rangées sous leur seul nom : renommer l\'une des deux.');
        }
        continue;
      }
      let bytes;
      try {
        bytes = await fs.readFile(imagePath);
      } catch {
        throw new Error(`${where} : fichier introuvable (${imagePath}).`);
      }
      if (!bytes.length || bytes.length > LESSON_IMAGES.maxFileBytes) {
        throw new Error(`${where} : ${bytes.length ? mo(bytes.length) : 'fichier vide'} ; ${mo(LESSON_IMAGES.maxFileBytes)} au `
          + 'plus par image (réduire ou compresser).');
      }
      images.set(name, { path: imagePath, src, bytes });
    }
  }
  const total = [...images.values()].reduce((sum, image) => sum + image.bytes.length, 0);
  if (images.size > LESSON_IMAGES.maxFiles || total > LESSON_IMAGES.maxTotalBytes) {
    throw new Error(`${file} : ${images.size} image(s), ${mo(total)} ; le pont en reçoit ${LESSON_IMAGES.maxFiles} et `
      + `${mo(LESSON_IMAGES.maxTotalBytes)} au plus par leçon (réduire ou compresser).`);
  }
  return {
    pages: blocks.map((contents, i) => ({ title: pageTitle(contents, fallback(i)), contents })),
    files: [...images].map(([filename, image]) => ({ filename, content: image.bytes.toString('base64') })),
  };
}

/** Lien vers une activité : @@CM:clé@@ (clé d'une activité du manifeste) ou @@CM:cmid@@ (activité existante du cours). */
const CM_LINK = /@@CM:([A-Za-z0-9_-]+)@@/g;

/**
 * Clés ou cmid cités par les liens @@CM:…@@ d'un contenu, sans doublon, dans l'ordre.
 *
 * @param {string} html
 * @returns {string[]}
 */
export function cmLinks(html) {
  return [...new Set([...String(html).matchAll(CM_LINK)].map((m) => m[1]))];
}

/**
 * Remplace chaque lien @@CM:…@@ par l'URL de l'activité ; un lien que urlOf ne connaît pas reste tel quel.
 *
 * @param {string} html
 * @param {(ref: string) => string|null|undefined} urlOf
 */
export function resolveCmLinks(html, urlOf) {
  return String(html).replace(CM_LINK, (token, ref) => urlOf(ref) ?? token);
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

  // Références entre activités (before, requires) : un cmid, ou la clé d'une activité placée plus haut.
  manifest.activities.forEach((a, i) => {
    for (const ref of [...(a.requires ?? []), ...(a.before === undefined ? [] : [a.before])]) {
      if (!Number.isInteger(ref) && !manifest.activities.slice(0, i).some((b) => b.key === ref)) {
        throw new Error(`${a.key} : « ${ref} » n'est ni un cmid ni la clé d'une activité placée plus haut dans le manifeste.`);
      }
    }
  });

  // Liens @@CM:clé@@ et @@CM:cmid@@, contrôlés avant toute création. Une page, une étiquette ou une consigne de TP
  // reçoit son contenu à sa création : sa cible doit être créée avant elle. Une leçon, ou le résumé de la section,
  // reçoit le sien en fin de déploiement quand sa cible est créée après elle.
  const position = new Map(manifest.activities.map((a, i) => [a.key, i]));
  const linkedCmids = new Set();
  const checkLinks = (owner, index, html, deferrable) => {
    const refs = cmLinks(html);
    let late = false;
    for (const ref of refs) {
      const target = manifest.activities[position.get(ref)];
      if (/^\d+$/.test(ref)) {
        linkedCmids.add(Number(ref));
      } else if (!target) {
        throw new Error(`${owner} : lien @@CM:${ref}@@ vers une activité absente du manifeste (clé d'une activité du `
          + 'manifeste, ou cmid d\'une activité existante du cours).');
      } else if (!ROLE_MODULES[target.role]) {
        report.warnings.push(`${owner} : lien @@CM:${ref}@@ vers une activité que le pont ne crée pas (rôle `
          + `« ${target.role} ») : laissé tel quel, à remplacer à la main.`);
      } else if (position.get(ref) > index || (position.get(ref) === index && !deferrable)) {
        if (!deferrable) {
          throw new Error(`${owner} : lien @@CM:${ref}@@ vers une activité créée après elle (ou elle-même) ; son contenu `
            + 'part à sa création. Placer la cible plus haut dans le manifeste (before règle la position dans la section), '
            + 'ou mettre le lien dans la leçon.');
        }
        late = true;
      }
    }
    return { refs, late };
  };
  const summary = manifest.section?.summary_file ? await read(manifest.section.summary_file) : null;
  const summaryLinks = checkLinks(`résumé de la section ${section}`, -1, summary ?? '', true);
  const links = new Map();
  for (const [i, a] of manifest.activities.entries()) {
    let html = '';
    if (a.role === 'lecon') {
      html = extractBlocks(await readRaw(a.file)).join('\n');
    } else if ((a.role === 'page' || a.role === 'label') && a.file) {
      html = await read(a.file);
    } else if (a.role === 'tp') {
      html = await read(path.join(a.dir ?? a.key, 'consigne.html'));
    }
    links.set(a.key, checkLinks(a.key, i, html, a.role === 'lecon'));
  }

  // Pas de doublon : une activité du même nom dans la section est refusée.
  const plan = await client.callJson('local_aibridge_get_course', { courseid: target });
  const existing = (plan.sections ?? []).find((s) => s.number === section)?.cms ?? [];
  const duplicates = manifest.activities.filter((a) => existing.some((cm) => cm.name === a.name)).map((a) => a.name);
  if (duplicates.length) {
    throw new Error(`Déjà présent dans la section ${section} : ${duplicates.join(', ')}. Supprimer les brouillons `
      + '(moodle_delete_draft) ou choisir une autre section.');
  }

  // URL des activités citées : existantes du cours d'abord, puis chacune à sa création.
  const urls = new Map();
  const courseCms = (plan.sections ?? []).flatMap((s) => s.cms ?? []);
  for (const cmid of linkedCmids) {
    const cm = courseCms.find((c) => c.cmid === cmid);
    if (!cm) {
      throw new Error(`Lien @@CM:${cmid}@@ : aucune activité ${cmid} dans le cours ${target}.`);
    }
    urls.set(String(cmid), new URL(`../mod/${cm.modname}/view.php?id=${cmid}`, plan.course.url).href);
  }
  const resolve = (html) => resolveCmLinks(html, (ref) => urls.get(ref));

  // Une activité désignée par sa clé dans le manifeste (déjà créée) ou par son cmid.
  const cmidOf = (ref) => (Number.isInteger(ref) ? ref : report.activities.find((e) => e.key === ref)?.cmid ?? null);

  // Pages d'une leçon et leurs images (sans image, pas de paramètre files : un pont antérieur à 0.3.3 le refuserait).
  const setLessonPages = async (entry, lesson, note = '') => step(
    `${entry.key} : ${lesson.pages.length} page(s)${lesson.files.length ? `, ${lesson.files.length} image(s)` : ''}${note}`,
    await client.callJson('local_aibridge_set_lesson_pages', {
      cmid: entry.cmid, pages: JSON.stringify(lesson.pages.map((p) => ({ ...p, contents: resolve(p.contents) }))),
      ...(lesson.files.length ? { files: JSON.stringify(lesson.files) } : {}),
    }));
  const lateLessons = [];

  step(`section ${section}`, await client.callJson('local_aibridge_upsert_section', {
    courseid: target, section, name: manifest.section?.name, dryrun: dryRun,
    ...(summary !== null && !(summaryLinks.late && !dryRun) ? { summary: resolve(summary) } : {}),
  }));

  for (const activity of manifest.activities) {
    const modname = ROLE_MODULES[activity.role];
    if (!modname) {
      report.warnings.push(`${activity.key} : rôle « ${activity.role} » non déployable par le pont (à créer à la main).`);
      continue;
    }
    const entry = { key: activity.key, role: activity.role, name: activity.name, modname };
    report.activities.push(entry);
    if (links.get(activity.key).refs.length) {
      entry.links = links.get(activity.key).refs;
    }
    const settings = roleSettings(activity, manifest);
    let intro = '';

    // Contenus lus dans les fichiers du module, liens @@CM résolus (leurs cibles sont déjà créées).
    if (activity.role === 'page' && activity.file) {
      settings.content = resolve(await read(activity.file));
    }
    if (activity.role === 'label' && activity.file) {
      intro = resolve(await read(activity.file));
    }
    if (activity.role === 'tp') {
      const tpdir = activity.dir ?? activity.key;
      settings.activity = resolve(await read(path.join(tpdir, 'consigne.html')));
      settings.assignfeedback_ai_exercise = await read(path.join(tpdir, 'enonce.txt'));
      settings.assignfeedback_ai_expectedanswer = await read(path.join(tpdir, 'corrige.txt'));
      settings.assignfeedback_ai_competencies = await read(path.join(tpdir, 'competences.txt'));
      try {
        settings.aichatcustomprompt = await read(path.join(tpdir, 'tuteur.txt'));
      } catch {
        // Facultatif.
      }
    }
    // Hors leçon (page, étiquette, consigne de TP), une image citée en relatif ne part pas avec le contenu.
    const unsent = [...new Set([settings.content, intro, settings.activity].flatMap((html) => (html ? relativeImageSources(html) : [])))];
    if (unsent.length) {
      report.warnings.push(`${activity.key} : image(s) en relatif non envoyée(s) par le pont, à déposer à la main dans l'éditeur : `
        + `${unsent.join(', ')}.`);
    }
    // Leçon : pages et images lues avant la création, pour qu'une image manquante ne laisse pas une leçon vide.
    let lesson = null;
    if (activity.role === 'lecon') {
      lesson = await readLesson(activity.file, { baseDir: dir, fallback: (i) => `${activity.name} (${i + 1})` });
      entry.pages = lesson.pages.length;
      entry.images = lesson.files.length;
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

    // Position : avant une activité de la section (le TD après la leçon, un bonus recréé avant le sommatif).
    const before = activity.before === undefined ? 0 : cmidOf(activity.before);
    if (before === null) {
      report.warnings.push(`${activity.key} : position « avant ${activity.before} » inconnue${dryRun ? ' en simulation' : ''}, `
        + 'activité placée en fin de section.');
    }
    const created = step(`${activity.key} : création (${modname})`, await client.callJson('local_aibridge_create_module', {
      courseid: target, section, modname, name: activity.name, intro, settings: JSON.stringify(settings), dryrun: dryRun,
      ...(before ? { beforecmid: before } : {}),
    }));
    entry.cmid = created.cmid ?? null;
    if (dryRun) {
      entry.settings = Object.keys(settings).length;
      continue;
    }
    urls.set(activity.key, created.url);

    // Pages de la leçon : tout de suite, ou en fin de déploiement si un lien vise une activité créée après elle.
    if (lesson && links.get(activity.key).late) {
      lateLessons.push({ entry, lesson });
    } else if (lesson) {
      await setLessonPages(entry, lesson);
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
        entry.slots = slots.length;
        const missing = marks.filter((m) => !m.idnumber).length;
        if (missing) {
          throw new Error(`${activity.questions.file} : ${missing} question(s) sans idnumber ; un test à questions fixes les repère par leur idnumber.`);
        }
      }
      step(`${activity.key} : structure du test`, await client.callJson('local_aibridge_set_quiz_layout', {
        cmid: entry.cmid, layout: JSON.stringify({ slots, shufflequestions: shuffles(activity.role) }),
      }));
    }

    // Extensions hors formulaire : surveillance, activités à achever d'abord, Avancement.
    const extras = {};
    if (activity.supervised) {
      extras.supervised = activity.supervised;
    }
    if (activity.requires?.length) {
      entry.requires = activity.requires.map(cmidOf);
      if (entry.requires.includes(null)) {
        throw new Error(`${activity.key} : requires ${JSON.stringify(activity.requires)} désigne une activité inconnue `
          + '(clé d\'une activité créée plus haut dans le manifeste, ou cmid).');
      }
      extras.requires = entry.requires;
    }
    if (activity.minduration_minutes) {
      extras.minduration = Math.round(activity.minduration_minutes * 60);
    }
    extras.avancement = activity.role === 'tp' || !activity.duedate ? {} : { duedate: activity.duedate };
    step(`${activity.key} : extensions`, await client.callJson('local_aibridge_set_module_extras',
      { cmid: entry.cmid, extras: JSON.stringify(extras) }));
  }

  if (!dryRun) {
    // Contenus dont un lien @@CM vise une activité créée après eux : toutes les URL sont maintenant connues.
    for (const { entry, lesson } of lateLessons) {
      await setLessonPages(entry, lesson, ' (liens vers la suite du module)');
    }
    if (summaryLinks.late) {
      step(`section ${section} : résumé (liens vers les activités)`, await client.callJson('local_aibridge_upsert_section',
        { courseid: target, section, summary: resolve(summary) }));
    }
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
    if (activity.role === 'lecon') {
      // Chaque image citée par une page (@@PLUGINFILE@@/nom) doit être dans la zone de fichiers de cette page.
      const missing = (module.content?.pages ?? []).flatMap((page) => {
        const files = new Set((page.files ?? []).map((f) => f.filename));
        return [...String(page.contents ?? '').matchAll(/@@PLUGINFILE@@\/([^"'?#\s]+)/g)]
          .map((m) => decodePath(m[1])).filter((name) => !files.has(name));
      });
      diff('images absentes des pages', '', [...new Set(missing)].join(', '));
    }
    if (activity.efe) {
      const efe = module.extensions?.efe?.competences_json?.quality ?? [];
      diff('EFE', activity.efe.competences.join(','), efe.join(','));
    }
    if (activity.role === 'tp') {
      // Le Tuteur IA est coupé sur un TP évalué ou bonus (overrides.aichatenabled = 0) : c'est voulu.
      diff('Correction IA', activity.overrides?.assignfeedback_ai_enabled ?? 1, module.extensions?.aifeedback?.enabled);
      diff('Tuteur IA', activity.overrides?.aichatenabled ?? 1, module.extensions?.aichat?.enabled);
    }
    if (entry.requires) {
      const conditions = (module.availability?.c ?? []).filter((c) => c.type === 'completion').map((c) => Number(c.cm));
      diff('activités à achever', [...entry.requires].sort().join(','), conditions.sort().join(','));
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
      if (activity.role === 'td') {
        diff('éléments du TD', entry.slots, module.content?.slotcount);
      }
    }
    if (activity.supervised) {
      diff('surveillée', true, module.extensions?.classhours?.supervised);
      diff('mode examen', activity.supervised.exammode ? 1 : 0, module.extensions?.classhours?.exammode);
    }
    entry.url = module.url;
  }
}
