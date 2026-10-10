// Photographie d'un cours : tout ce que le pont sait lire, écrit sur le
// disque pour être comparé aux fichiers sources d'un parcours (HTML entre
// marqueurs, XML de questions) et servir de référence au skill.
//
// <outdir>/<nom-abrégé>/
//   README.md                 plan lisible du cours, avec liens vers les fichiers
//   site.json                 version, plugins, réglages utiles (sans le SCSS)
//   theme/scsspre.scss, theme/scss.scss
//   course.json               réglages du cours et plan complet
//   modules/<n>-<nom>-<type>-<cmid>.json      détail de chaque activité
//   contenus/<n>-<nom>-<type>-<cmid>/*.html   contenus HTML bruts (@@PLUGINFILE@@ conservé)
//   questions/<n>-<nom>-quiz-<cmid>.xml       questions de chaque test, dans l'ordre des emplacements
//   questions/banques.json, questions/banque-<cmid>-<catid>-<nom>.xml
//   efe-competences.json

import { mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { slugify } from './shape.js';

const pad = (n) => String(n).padStart(3, '0');

/**
 * @param {import('./moodle-client.js').MoodleClient} client
 * @param {number} courseid
 * @param {string} outdir
 * @param {{log?: (msg: string) => void, now?: Date}} [options]
 */
export async function snapshotCourse(client, courseid, outdir, { log = () => {}, now = new Date() } = {}) {
  const warnings = [];
  let root = '';
  let files = 0;
  const write = async (relative, content) => {
    const target = path.join(root, relative);
    await mkdir(path.dirname(target), { recursive: true });
    await writeFile(target, typeof content === 'string' ? content : `${JSON.stringify(content, null, 2)}\n`, 'utf8');
    files += 1;
    return relative.split(path.sep).join('/');
  };

  const site = await client.callJson('local_aibridge_get_site_info');
  const course = await client.callJson('local_aibridge_get_course', { courseid });
  root = path.resolve(outdir, slugify(course.course.shortname, 60));
  log(`Snapshot de « ${course.course.fullname} » vers ${root}`);

  const { scsspre = '', scss = '', ...theme } = site.theme ?? {};
  await write(path.join('theme', 'scsspre.scss'), scsspre);
  await write(path.join('theme', 'scss.scss'), scss);
  await write('site.json', { ...site, theme: { ...theme, scsspre: 'theme/scsspre.scss', scss: 'theme/scss.scss' } });
  await write('course.json', course);

  const modules = [];
  let index = 0;
  for (const section of course.sections ?? []) {
    for (const cm of section.cms ?? []) {
      index += 1;
      const base = `${pad(index)}-${slugify(cm.name, 40)}-${cm.modname}-${cm.cmid}`;
      const entry = {
        index, section: section.number, sectionname: section.name, cmid: cm.cmid, modname: cm.modname,
        name: cm.name, visible: cm.visible, extensions: cm.extensions ?? [], file: null, contents: [], questions: null,
      };
      modules.push(entry);
      try {
        const detail = await client.callJson('local_aibridge_get_module', { cmid: cm.cmid });
        entry.file = await write(path.join('modules', `${base}.json`), detail);
        entry.contents = await writeContents(write, base, detail);
        if (cm.modname === 'quiz') {
          entry.questions = await writeQuizQuestions(client, write, courseid, base, detail);
        }
        log(`  ${pad(index)} ${cm.modname} « ${cm.name} »`);
      } catch (error) {
        warnings.push(`cm ${cm.cmid} (${cm.modname} « ${cm.name} ») : ${error.message}`);
      }
    }
  }

  const banks = await writeBanks(client, write, courseid, warnings);

  try {
    await write('efe-competences.json', await client.callJson('local_aibridge_get_efe_competences'));
  } catch (error) {
    warnings.push(`Référentiel EFE : ${error.message}`);
  }

  await write('README.md', readme({ site, course, modules, banks, warnings, now }));
  return { root, modules: modules.length, files, warnings };
}

/** Contenus HTML d'une activité, un fichier par contenu. */
async function writeContents(write, base, detail) {
  const dir = path.join('contenus', base);
  const out = [];
  const instance = detail.instance ?? {};
  const add = async (name, html) => {
    if (typeof html === 'string' && html.trim() !== '') {
      out.push(await write(path.join(dir, name), html));
    }
  };
  await add('intro.html', instance.intro);
  if (detail.modname === 'assign') {
    await add('instructions.html', instance.activity);
  }
  if (detail.modname === 'page') {
    await add('contenu.html', instance.content);
  }
  if (detail.modname === 'lesson') {
    for (const page of detail.content?.pages ?? []) {
      await add(`page-${String(page.position).padStart(2, '0')}-${slugify(page.title, 40)}.html`, page.contents);
    }
  }
  if (detail.modname === 'book') {
    for (const chapter of detail.content?.chapters ?? []) {
      await add(`chapitre-${String(chapter.pagenum).padStart(2, '0')}-${slugify(chapter.title, 40)}.html`, chapter.content);
    }
  }
  return out;
}

/** Questions d'un test (emplacements fixes) en Moodle XML, dans l'ordre. */
async function writeQuizQuestions(client, write, courseid, base, detail) {
  const ids = (detail.content?.slots ?? []).filter((slot) => !slot.random && slot.questionid).map((slot) => slot.questionid);
  if (!ids.length) {
    return null;
  }
  const result = await client.callJson('local_aibridge_get_questions', {
    courseid, questionids: ids, includebanks: false, includexml: true,
  });
  return result.xml ? write(path.join('questions', `${base}.xml`), result.xml) : null;
}

/** Banques du cours : inventaire, puis un export XML par catégorie non vide. */
async function writeBanks(client, write, courseid, warnings) {
  let inventory;
  try {
    inventory = await client.callJson('local_aibridge_get_questions', { courseid, includebanks: true, includexml: false });
  } catch (error) {
    warnings.push(`Banques de questions : ${error.message}`);
    return [];
  }
  await write(path.join('questions', 'banques.json'), inventory);
  const out = [];
  for (const bank of inventory.banks ?? []) {
    for (const category of bank.categories ?? []) {
      if (!category.questioncount) {
        continue;
      }
      try {
        const result = await client.callJson('local_aibridge_get_questions', {
          courseid, categoryids: [category.id], includebanks: false, includexml: true,
        });
        if (result.xml) {
          const file = await write(path.join('questions',
            `banque-${bank.cmid}-${category.id}-${slugify(category.name, 40)}.xml`), result.xml);
          out.push({ bank: bank.name, modname: bank.modname, category: category.name, count: category.questioncount, file });
        }
      } catch (error) {
        warnings.push(`Catégorie ${category.id} « ${category.name} » : ${error.message}`);
      }
    }
  }
  return out;
}

/** Plan lisible du snapshot. */
function readme({ site, course, modules, banks, warnings, now }) {
  const c = course.course;
  const lines = [
    `# Snapshot — ${c.fullname} (${c.shortname})`,
    '',
    `- Date : ${now.toISOString()}`,
    `- Site : ${site.moodle?.wwwroot} — Moodle ${site.moodle?.release}`,
    `- Cours : id ${c.id}, catégorie « ${c.categoryname} », format ${c.format}, ${c.enrolledcount} inscrits`,
    `- Thème : ${site.theme?.name} — classes CIEL trouvées : ${(site.theme?.cielclasses ?? []).join(', ') || 'aucune'}`
      + ((site.theme?.missingclasses ?? []).length ? ` ; manquantes : ${site.theme.missingclasses.join(', ')}` : ''),
    '',
    '## Plugins du projet',
    '',
    Object.entries(site.plugins ?? {})
      .filter(([, info]) => info.installed)
      .map(([component, info]) => `\`${component}\` ${info.release}`)
      .join(' · '),
    '',
    '## Plan du cours',
  ];
  let current = null;
  for (const module of modules) {
    if (module.section !== current) {
      current = module.section;
      lines.push('', `### Section ${module.section} — ${module.sectionname}`, '',
        '| # | cmid | Type | Nom | Visible | Extensions | Détail |', '|---|---|---|---|---|---|---|');
    }
    const links = [module.file ? `[json](${module.file})` : '—'];
    if (module.questions) {
      links.push(`[xml](${module.questions})`);
    }
    lines.push(`| ${module.index} | ${module.cmid} | ${module.modname} | ${module.name.replace(/\|/g, '\\|')} | `
      + `${module.visible ? 'oui' : 'non'} | ${module.extensions.join(' · ').replace(/\|/g, '\\|')} | ${links.join(' ')} |`);
  }
  if (banks.length) {
    lines.push('', '## Banques de questions', '', '| Banque | Catégorie | Questions | Fichier |', '|---|---|---|---|');
    for (const bank of banks) {
      lines.push(`| ${bank.bank} (${bank.modname}) | ${bank.category} | ${bank.count} | [xml](${bank.file}) |`);
    }
  }
  if (warnings.length) {
    lines.push('', '## Avertissements', '', ...warnings.map((warning) => `- ${warning}`));
  }
  return `${lines.join('\n')}\n`;
}
