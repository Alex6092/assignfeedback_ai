import assert from 'node:assert/strict';
import { mkdtemp, readFile, readdir, rm } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { test } from 'node:test';
import { slugify, truncateStrings } from '../src/shape.js';
import { snapshotCourse } from '../src/snapshot.js';

/** Faux client : réponses en dur par fonction, appels mémorisés. */
function fakeClient(responses) {
  const calls = [];
  return {
    calls,
    async callJson(fn, params = {}) {
      calls.push({ fn, params });
      const response = responses[fn];
      if (response instanceof Error) {
        throw response;
      }
      return typeof response === 'function' ? response(params) : structuredClone(response);
    },
  };
}

const SITE = {
  moodle: { release: '5.2+', wwwroot: 'https://moodle.test' },
  plugins: { local_aibridge: { installed: true, release: '0.1.0' }, local_efenotes: { installed: false } },
  theme: { name: 'boost', scsspre: '.objectif { color: green; }', scss: '', cielclasses: ['objectif'], missingclasses: ['astuce'] },
};

const COURSE = {
  course: { id: 9, fullname: 'Parcours Cyber Kali', shortname: 'KALI-2026', format: 'topics', categoryname: 'BTS', enrolledcount: 24 },
  sections: [
    { number: 0, name: 'Généralités', cms: [{ cmid: 10, modname: 'page', name: 'Introduction', visible: 1, extensions: [] }] },
    { number: 1, name: 'M2 — Reconnaissance', cms: [
      { cmid: 11, modname: 'lesson', name: 'M2 — Leçon Nmap', visible: 1, extensions: [] },
      { cmid: 12, modname: 'quiz', name: 'T3 — Test sommatif', visible: 0, extensions: ['EFE C04', 'Surveillée (code)'] },
      { cmid: 13, modname: 'assign', name: 'TP | cassé', visible: 1, extensions: [] },
    ] },
  ],
};

const MODULES = {
  10: { modname: 'page', instance: { intro: '', content: '<h1>Bienvenue</h1>' }, content: {} },
  11: { modname: 'lesson', instance: { intro: '<p>Intro</p>' }, content: { pages: [
    { position: 1, title: 'Où on en est', contents: '<h1>🗺️ Module 2</h1>' },
    { position: 2, title: 'Les scans', contents: '<p>nmap -sV</p>' },
  ] } },
  12: { modname: 'quiz', instance: { intro: '' }, content: { slots: [
    { slot: 1, random: false, questionid: 501 }, { slot: 2, random: true }, { slot: 3, random: false, questionid: 502 },
  ] } },
};

test('slugify : sans accents ni caractères spéciaux', () => {
  assert.equal(slugify('M2 — Leçon « Nmap » !'), 'm2-lecon-nmap');
  assert.equal(slugify(''), 'sans-nom');
  assert.equal(slugify('a'.repeat(80), 10), 'aaaaaaaaaa');
});

test('truncateStrings : seules les chaînes longues sont coupées', () => {
  const out = truncateStrings({ a: 'court', b: ['x'.repeat(20)], c: 3 }, 10);
  assert.equal(out.a, 'court');
  assert.match(out.b[0], /^x{10}… \[tronqué : 20 caractères au total\]$/);
  assert.equal(out.c, 3);
});

test('snapshot : fichiers attendus, questions dans l\'ordre des emplacements, erreurs isolées', async () => {
  const dir = await mkdtemp(path.join(os.tmpdir(), 'moodle-mcp-'));
  try {
    const client = fakeClient({
      local_aibridge_get_site_info: SITE,
      local_aibridge_get_course: COURSE,
      local_aibridge_get_module: ({ cmid }) => {
        if (!MODULES[cmid]) {
          throw new Error('invalidrecord');
        }
        return structuredClone(MODULES[cmid]);
      },
      local_aibridge_get_questions: (params) => {
        if (params.questionids) {
          return { questions: [], xml: `<quiz><!-- ${params.questionids.join(',')} --></quiz>` };
        }
        if (params.categoryids) {
          return { questions: [], xml: `<quiz><!-- cat ${params.categoryids[0]} --></quiz>` };
        }
        return { questions: [], banks: [{ cmid: 30, modname: 'qbank', name: 'Banque KALI', categories: [
          { id: 40, name: 'Entraînement M2', questioncount: 18 }, { id: 41, name: 'Vide', questioncount: 0 },
        ] }] };
      },
      local_aibridge_get_efe_competences: new Error('EFE non configuré'),
    });

    const result = await snapshotCourse(client, 9, dir, { now: new Date('2026-10-09T08:00:00Z') });
    assert.equal(result.root, path.join(dir, 'kali-2026'));
    assert.equal(result.modules, 4);
    assert.equal(result.warnings.length, 2); // devoir introuvable + EFE

    const root = result.root;
    const site = JSON.parse(await readFile(path.join(root, 'site.json'), 'utf8'));
    assert.equal(site.theme.scsspre, 'theme/scsspre.scss');
    assert.equal(await readFile(path.join(root, 'theme', 'scsspre.scss'), 'utf8'), '.objectif { color: green; }');

    const lessonDir = path.join(root, 'contenus', '002-m2-lecon-nmap-lesson-11');
    assert.deepEqual((await readdir(lessonDir)).sort(), ['intro.html', 'page-01-ou-on-en-est.html', 'page-02-les-scans.html']);
    assert.equal(await readFile(path.join(lessonDir, 'page-01-ou-on-en-est.html'), 'utf8'), '<h1>🗺️ Module 2</h1>');
    assert.equal(await readFile(path.join(root, 'contenus', '001-introduction-page-10', 'contenu.html'), 'utf8'),
      '<h1>Bienvenue</h1>');

    // Questions du test : emplacements fixes seulement, dans l'ordre.
    const quizCall = client.calls.find((call) => call.params.questionids);
    assert.deepEqual(quizCall.params.questionids, [501, 502]);
    assert.match(await readFile(path.join(root, 'questions', '003-t3-test-sommatif-quiz-12.xml'), 'utf8'), /501,502/);

    // Banques : une catégorie non vide exportée, la vide ignorée.
    const files = await readdir(path.join(root, 'questions'));
    assert.ok(files.includes('banque-30-40-entrainement-m2.xml'));
    assert.ok(!files.some((file) => file.startsWith('banque-30-41')));

    const readme = await readFile(path.join(root, 'README.md'), 'utf8');
    assert.match(readme, /# Snapshot — Parcours Cyber Kali \(KALI-2026\)/);
    assert.match(readme, /### Section 1 — M2 — Reconnaissance/);
    assert.match(readme, /\| 3 \| 12 \| quiz \| T3 — Test sommatif \| non \| EFE C04 · Surveillée \(code\) \|/);
    assert.match(readme, /TP \\\| cassé/);
    assert.match(readme, /## Avertissements/);
  } finally {
    await rm(dir, { recursive: true, force: true });
  }
});
