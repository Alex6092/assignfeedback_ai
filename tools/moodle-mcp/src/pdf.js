// Export PDF des leçons d'un cours, sur le poste de l'enseignant : un PDF par
// leçon et un PDF de tout le parcours, pour une distribution en cas de panne
// de Moodle ou en fin de formation.
//
// Le rendu reproduit celui de Moodle : la feuille de style compilée du thème
// (avec le « Code SCSS initial » des parcours), le même balisage que mod_lesson
// (.contents > .no-overflow) et les règles d'impression du plugin
// local_lessonexport ; Edge (ou Chrome), sans fenêtre et avec un profil
// temporaire, imprime chaque page en PDF.

import { execFile } from 'node:child_process';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { slugify } from './shape.js';

const HERE = path.dirname(fileURLToPath(import.meta.url));
/** Règles d'impression du plugin, lues dans le dépôt (le thème les contient si le plugin est installé). */
const PLUGIN_CSS = path.resolve(HERE, '../../../local/lessonexport/styles.css');

const BROWSERS = [
  'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
  'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
  'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
  '/usr/bin/microsoft-edge',
  '/usr/bin/google-chrome',
  '/usr/bin/chromium',
  '/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge',
  '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
];

const escapeHtml = (s) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
  .replace(/"/g, '&quot;');

/** Navigateur à utiliser : MOODLE_PDF_BROWSER, sinon Edge ou Chrome aux emplacements habituels. */
export async function findBrowser(env = process.env) {
  const candidates = [env.MOODLE_PDF_BROWSER, ...BROWSERS].filter(Boolean);
  for (const candidate of candidates) {
    try {
      await fs.access(candidate);
      return candidate;
    } catch {
      // Suivant.
    }
  }
  throw new Error('Ni Edge ni Chrome trouvé : indiquer le chemin du navigateur dans MOODLE_PDF_BROWSER.');
}

/**
 * Imprime un fichier HTML local en PDF avec le navigateur, sans fenêtre et
 * avec un profil temporaire (sinon Edge s'accroche à la session ouverte de
 * l'utilisateur au lieu d'imprimer).
 */
export async function printToPdf(browser, htmlFile, pdfFile, { timeoutMs = 120000 } = {}) {
  const profile = await fs.mkdtemp(path.join(os.tmpdir(), 'moodle-pdf-'));
  const args = [
    '--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
    `--user-data-dir=${profile}`, '--no-pdf-header-footer', '--virtual-time-budget=10000',
    `--print-to-pdf=${pdfFile}`, pathToFileURL(htmlFile).href,
  ];
  try {
    await new Promise((resolve, reject) => {
      execFile(browser, args, { timeout: timeoutMs, windowsHide: true }, (error) => (error ? reject(error) : resolve()));
    });
    const stat = await fs.stat(pdfFile);
    if (!stat.size) {
      throw new Error(`${pdfFile} : PDF vide.`);
    }
  } finally {
    await fs.rm(profile, { recursive: true, force: true }).catch(() => {});
  }
}

/**
 * Adresse de la feuille de style du thème, lue dans la page de connexion de
 * Moodle (publique) quand le pont ne la donne pas.
 *
 * @param {string|undefined} wwwroot
 * @param {typeof fetch} fetchImpl
 * @returns {Promise<string|null>}
 */
export async function themeCssFromLoginPage(wwwroot, fetchImpl = globalThis.fetch) {
  if (!wwwroot) {
    return null;
  }
  try {
    const response = await fetchImpl(`${wwwroot}/login/index.php`);
    const html = await response.text();
    const match = /<link[^>]+href="([^"]*\/theme\/styles\.php[^"]*\/all)"/i.exec(html);
    return match ? match[1].replace(/&amp;/g, '&') : null;
  } catch {
    return null;
  }
}

/** Page HTML autonome, au balisage de local_lessonexport (même rendu que dans Moodle). */
export function lessonDocument({ title, css, sections }) {
  return `<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>${escapeHtml(title)}</title>
${css.map((href) => `<link rel="stylesheet" href="${escapeHtml(href)}">`).join('\n')}
<style>
  /* Hors de Moodle : pas de barre de navigation, une colonne de lecture. */
  body.local-lessonexport { background: #fff; padding: 0; }
  body.local-lessonexport #page { max-width: 900px; margin: 0 auto; padding: 1rem 1.5rem; }
</style>
</head>
<body class="local-lessonexport">
<div id="page"><div id="region-main">
${sections.join('\n')}
</div></div>
</body>
</html>
`;
}

/** Une leçon au balisage du plugin : en-tête, puis chaque page dans sa boîte « contents ». */
export function lessonSection({ course, section, name, pages }) {
  const body = pages.map((page) => {
    const title = /<h1[\s>]/i.test(page.html) ? '' : `<h3 class="lessonexport-pagetitle">${escapeHtml(page.title)}</h3>`;
    const note = page.question ? '<div class="lessonexport-note">Page de question : le texte est imprimé, sans les réponses.</div>' : '';
    return `<div class="lessonexport-page${page.question ? ' lessonexport-question' : ''}">${title}${note}`
      + `<div class="box py-3 contents"><div class="no-overflow">${page.html}</div></div></div>`;
  }).join('\n');
  return `<section class="lessonexport-lesson">
<div class="lessonexport-meta">${escapeHtml(course)} · ${escapeHtml(section)}</div>
<h2 class="lessonexport-name">${escapeHtml(name)}</h2>
${body}
</section>`;
}

/**
 * Exporte les leçons d'un cours.
 *
 * @param {{callJson: Function, download: Function}} client
 * @param {number} courseid
 * @param {string} outdir dossier de destination (un sous-dossier au nom abrégé du cours y est créé)
 * @param {{browser?: string, fetchImpl?: typeof fetch, print?: Function, log?: Function, includeHidden?: boolean}} [options]
 */
export async function exportLessonsPdf(client, courseid, outdir, options = {}) {
  const { fetchImpl = globalThis.fetch, log = () => {}, includeHidden = true } = options;
  const print = options.print ?? printToPdf;
  const warnings = [];

  const course = await client.callJson('local_aibridge_get_course', { courseid });
  const root = path.join(outdir, `${slugify(course.course.shortname)}-lecons-pdf`);
  await fs.mkdir(path.join(root, 'images'), { recursive: true });

  // Feuille de style compilée du thème (publique), gardée en local : l'impression ne dépend pas du réseau.
  const css = [];
  const site = await client.callJson('local_aibridge_get_site_info');
  const cssurl = site.theme?.cssurl ?? await themeCssFromLoginPage(site.moodle?.wwwroot, fetchImpl);
  if (cssurl) {
    const response = await fetchImpl(cssurl);
    if (response.ok) {
      await fs.writeFile(path.join(root, 'theme.css'), await response.text());
      css.push('theme.css');
    } else {
      warnings.push(`Feuille de style du thème introuvable (HTTP ${response.status}) : rendu approché.`);
    }
  } else {
    warnings.push('Feuille de style du thème introuvable : rendu approché.');
  }
  try {
    await fs.copyFile(PLUGIN_CSS, path.join(root, 'lessonexport.css'));
    css.push('lessonexport.css');
  } catch {
    warnings.push('Règles d\'impression de local_lessonexport introuvables dans le dépôt.');
  }

  const lessons = [];
  for (const section of course.sections ?? []) {
    for (const cm of section.cms ?? []) {
      if (cm.modname === 'lesson' && (includeHidden || cm.visible)) {
        lessons.push({ cm, section });
      }
    }
  }
  if (!lessons.length) {
    throw new Error(`Aucune leçon dans le cours ${courseid}.`);
  }

  const browser = options.browser ?? await findBrowser();
  const sections = [];
  const files = [];
  for (const [index, { cm, section }] of lessons.entries()) {
    const module = await client.callJson('local_aibridge_get_module', { cmid: cm.cmid });
    const pages = [];
    for (const page of module.content?.pages ?? []) {
      if (['endofbranch', 'cluster', 'endofcluster'].includes(page.type)) {
        continue;
      }
      let html = page.contents ?? '';
      for (const file of page.files ?? []) {
        if (!module.contextid) {
          warnings.push(`${cm.name} : image « ${file.filename} » non récupérée (local_aibridge 0.3.1 requis).`);
          continue;
        }
        const local = `${cm.cmid}-${page.id}-${file.filename}`;
        try {
          const data = await client.download(`/${module.contextid}/mod_lesson/page_contents/${page.id}${file.filepath}${file.filename}`);
          await fs.writeFile(path.join(root, 'images', local), data);
          const encoded = file.filepath.split('/').map(encodeURIComponent).join('/') + encodeURIComponent(file.filename);
          html = html.split(`@@PLUGINFILE@@${encoded}`).join(`images/${local}`)
            .split(`@@PLUGINFILE@@${file.filepath}${file.filename}`).join(`images/${local}`);
        } catch (error) {
          warnings.push(`${cm.name} : image « ${file.filename} » : ${error.message}`);
        }
      }
      pages.push({ title: page.title, html, question: page.type !== 'content' });
    }
    if (!cm.visible) {
      warnings.push(`${cm.name} : leçon masquée aux élèves, exportée quand même.`);
    }
    const sectionHtml = lessonSection({ course: course.course.fullname, section: section.name, name: cm.name, pages });
    sections.push(sectionHtml);

    const base = `${String(index + 1).padStart(2, '0')}-${slugify(cm.name)}`;
    const htmlFile = path.join(root, `${base}.html`);
    const pdfFile = path.join(root, `${base}.pdf`);
    await fs.writeFile(htmlFile, lessonDocument({ title: cm.name, css, sections: [sectionHtml] }));
    log(`PDF : ${cm.name}`);
    await print(browser, htmlFile, pdfFile);
    files.push({ lesson: cm.name, pdf: pdfFile, pages: pages.length });
  }

  // Tout le parcours : une leçon par page imprimée.
  const allHtml = path.join(root, '00-parcours-complet.html');
  const allPdf = path.join(root, '00-parcours-complet.pdf');
  await fs.writeFile(allHtml, lessonDocument({ title: course.course.fullname, css, sections }));
  await print(browser, allHtml, allPdf);

  return { dossier: root, parcours: allPdf, lecons: files, avertissements: warnings };
}
