import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { test } from 'node:test';
import { exportLessonsPdf, lessonSection, themeCssFromLoginPage } from '../src/pdf.js';

test('lessonSection reprend le balisage de mod_lesson et n\'ajoute pas de titre si la page a son <h1>', () => {
  const html = lessonSection({ course: 'Cours', section: 'M5', name: 'Leçon', pages: [
    { title: 'Page 1', html: '<h1>Titre</h1><p>a</p>', question: false },
    { title: 'Une question', html: '<p>Combien ?</p>', question: true },
  ] });
  assert.match(html, /<div class="box py-3 contents"><div class="no-overflow"><h1>Titre<\/h1>/);
  assert.doesNotMatch(html, /lessonexport-pagetitle">Page 1/);
  assert.match(html, /lessonexport-pagetitle">Une question/);
  assert.match(html, /sans les réponses/);
});

test('themeCssFromLoginPage lit l\'adresse de la feuille de style compilée', async () => {
  const fetchImpl = async () => ({ text: async () => '<link rel="stylesheet" type="text/css" '
    + 'href="https://m.test/theme/styles.php/boost/1791622000_1/all" />' });
  assert.equal(await themeCssFromLoginPage('https://m.test', fetchImpl), 'https://m.test/theme/styles.php/boost/1791622000_1/all');
  assert.equal(await themeCssFromLoginPage(undefined, fetchImpl), null);
});

test('exportLessonsPdf : un PDF par leçon, le parcours complet, images locales', async () => {
  const outdir = await fs.mkdtemp(path.join(os.tmpdir(), 'pdf-'));
  const printed = [];
  const client = {
    async callJson(fn, params) {
      if (fn === 'local_aibridge_get_course') {
        return { course: { shortname: 'IA-01', fullname: 'Parcours IA' }, sections: [
          { name: 'M1', cms: [{ cmid: 1, modname: 'lesson', name: 'Leçon 1', visible: 1 }, { cmid: 2, modname: 'quiz', name: 'QCM' }] },
          { name: 'M2', cms: [{ cmid: 3, modname: 'lesson', name: 'Leçon 2', visible: 0 }] },
        ] };
      }
      if (fn === 'local_aibridge_get_site_info') {
        return { theme: { cssurl: 'https://m.test/theme.css' } };
      }
      return { contextid: 40 + params.cmid, content: { pages: [
        { id: 7, type: 'content', title: 'P', contents: '<h1>T</h1><img src="@@PLUGINFILE@@/sch%C3%A9ma.png">',
          files: [{ filepath: '/', filename: 'schéma.png' }] },
        { id: 8, type: 'endofbranch', title: 'fin', contents: '', files: [] },
      ] } };
    },
    async download(filepath) {
      return Buffer.from(filepath);
    },
  };
  const result = await exportLessonsPdf(client, 57, outdir, {
    browser: 'edge',
    fetchImpl: async () => ({ ok: true, text: async () => '.objectif{}' }),
    print: async (browser, html, pdf) => { printed.push(path.basename(pdf)); await fs.writeFile(pdf, '%PDF'); },
  });
  assert.deepEqual(printed, ['01-lecon-1.pdf', '02-lecon-2.pdf', '00-parcours-complet.pdf']);
  assert.deepEqual(result.lecons.map((l) => l.pages), [1, 1]);
  const html = await fs.readFile(path.join(result.dossier, '01-lecon-1.html'), 'utf8');
  assert.match(html, /<link rel="stylesheet" href="theme.css">/);
  assert.match(html, /src="images\/1-7-schéma.png"/);
  assert.equal(String(await fs.readFile(path.join(result.dossier, 'images', '1-7-schéma.png'))),
    '/41/mod_lesson/page_contents/7/schéma.png');
  assert.equal(String(await fs.readFile(path.join(result.dossier, 'images', '3-7-schéma.png'))),
    '/43/mod_lesson/page_contents/7/schéma.png');
  assert.match(result.avertissements.join('\n'), /Leçon 2 : leçon masquée/);
});
