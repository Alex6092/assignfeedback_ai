import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { test } from 'node:test';
import { deployModule, pageTitle, questionMarks, splitAiQuestions } from '../src/deploy.js';
import { review, roleSettings } from '../src/roles.js';

const q = (type, id, grade = 1) => `<question type="${type}"><name><text>${id}</text></name>
<defaultgrade>${grade}</defaultgrade><idnumber>${id}</idnumber></question>`;
const quiz = (...questions) => `<?xml version="1.0" encoding="UTF-8"?>\n<quiz>\n${questions.join('\n')}\n</quiz>`;
const block = (html) => `<!-- ▶ DÉBUT CONTENU MOODLE ◀ -->\n${html}\n<!-- ▶ FIN CONTENU MOODLE ◀ -->`;

test('pageTitle : texte du h1 sans emoji ni « Module n — »', () => {
  assert.equal(pageTitle('<h1>✍️ Module 5 — Deep learning &amp; images</h1><p>…</p>', 'x'), 'Deep learning & images');
  assert.equal(pageTitle('<h1>Récap &amp; suite</h1>', 'x'), 'Récap & suite');
  assert.equal(pageTitle('<p>sans titre</p>', 'Page 2'), 'Page 2');
});

test('splitAiQuestions sépare les questions corrigées par IA', () => {
  const split = splitAiQuestions(quiz(q('multichoice', 'A'), q('aishortanswer', 'B'), q('numerical', 'C')));
  assert.deepEqual(split.counts, { regular: 2, ai: 1 });
  assert.match(split.ai, /type="aishortanswer"/);
  assert.doesNotMatch(split.regular, /aishortanswer/);
});

test('questionMarks lit idnumber et points dans l\'ordre du fichier', () => {
  assert.deepEqual(questionMarks(quiz(q('multichoice', 'IA-T5-Q1', 2), q('aiessay', 'IA-T5-Q6', 6))), [
    { idnumber: 'IA-T5-Q1', maxmark: 2, qtype: 'multichoice' },
    { idnumber: 'IA-T5-Q6', maxmark: 6, qtype: 'aiessay' },
  ]);
});

test('rôles : entraînement sans bonne réponse ni feedback général ; sommatif tout montré après fermeture', () => {
  const entr = roleSettings({ role: 'entrainement', timelimit_minutes: 11 }, {});
  assert.equal(entr.timelimit, 660);
  assert.equal(entr.delay2, 172800);
  assert.equal(entr.rightansweropen, 0);
  assert.equal(entr.generalfeedbackimmediately, 0);
  assert.equal(entr.specificfeedbackimmediately, 1);
  const somm = roleSettings({ role: 'sommatif', efe: { competences: ['C08.1.D4.T5'] } }, { course: { teacher_id: 2 } });
  assert.equal(somm.timeclose, 0);
  assert.equal(somm.rightanswerclosed, 1);
  assert.equal(somm.rightansweropen, 0);
  assert.equal(somm.showuserpicture, 2);
  assert.deepEqual(somm.efenotes_competence, ['C08.1.D4.T5']);
  assert.equal(somm.efenotes_punct_enabled, 0);
  assert.equal(somm.efenotes_prof_moodleid, 2);
  const overridden = roleSettings({ role: 'entrainement', overrides: { delay2: 86400 } }, {});
  assert.equal(overridden.delay2, 86400);
  assert.equal(review({ attempt: ['during'] }).attemptduring, 1);
});

/** Module minimal sur disque : leçon de 2 pages, entraînement avec une question IA, TP, sommatif. */
async function moduleOnDisk() {
  const dir = await fs.mkdtemp(path.join(os.tmpdir(), 'deploy-'));
  await fs.writeFile(path.join(dir, 'lecon.html'), `<html><body>${block('<h1>📘 Module 5 — Images</h1><p>a</p>')}
${block('<h1>Les convolutions</h1><p>b</p>')}</body></html>`);
  await fs.writeFile(path.join(dir, 'entrainement.xml'), quiz(q('multichoice', 'IA-M5-E01'), q('multichoice', 'IA-M5-E02'),
    q('aishortanswer', 'IA-M5-E03')));
  await fs.writeFile(path.join(dir, 'sommatif.xml'), quiz(q('multichoice', 'IA-T5-Q1', 8), q('aiessay', 'IA-T5-Q2', 12)));
  await fs.mkdir(path.join(dir, 'tp1'));
  for (const [file, text] of [['consigne.html', '<p>Mission</p>'], ['enonce.txt', 'Énoncé'], ['corrige.txt', 'Noter sur 100.'],
    ['competences.txt', 'C08 — Coder']]) {
    await fs.writeFile(path.join(dir, 'tp1', file), text);
  }
  const manifest = {
    format: 'moodle-ciel/module@1', course: { id: 74, teacher_id: 2 }, section: { number: 5, name: 'M5 - Images' }, prefix: 'IA',
    activities: [
      { key: 'lecon', role: 'lecon', name: 'Images', file: 'lecon.html' },
      { key: 'entrainement', role: 'entrainement', name: 'QCM - Images',
        questions: { file: 'entrainement.xml', category: 'M5 - Entraînement', draw: 2, draw_ai: 1 }, timelimit_minutes: 4 },
      { key: 'tp1', role: 'tp', name: 'TP5.1', dir: 'tp1', submission: { type: 'onlinetext' },
        efe: { competences: ['C08.4.D2.T3'], ponctualite: ['C03.4.R4.T5'], after_due: true }, duedate: '2026-11-09T12:00' },
      { key: 'sommatif', role: 'sommatif', name: 'Test sommatif : Images', questions: { file: 'sommatif.xml', category: 'M5 - Test sommatif' },
        points: 20, efe: { competences: ['C08.1.D4.T5'], after_due: true },
        supervised: { planned: '2026-11-16T09:20', sessioncode: true, exammode: true }, duedate: '2026-11-20T17:45' },
      { key: 'video', role: 'video', name: 'Vidéo', file: 'video.json' },
    ],
  };
  await fs.writeFile(path.join(dir, 'module.deploy.json'), JSON.stringify(manifest));
  return path.join(dir, 'module.deploy.json');
}

/** Faux Moodle : enregistre les appels et répond comme le pont. */
function fakeMoodle({ existing = [] } = {}) {
  const calls = [];
  let cmid = 3000;
  let categoryid = 100;
  const created = {};
  return {
    calls,
    async callJson(fn, params = {}) {
      calls.push({ fn, params });
      switch (fn) {
        case 'local_aibridge_get_course': return { sections: [{ number: 5, cms: existing }] };
        case 'local_aibridge_upsert_section': return { sectionid: 1, section: params.section };
        case 'local_aibridge_import_questions': return params.dryrun ? { dryrun: true } : { categoryid: ++categoryid };
        case 'local_aibridge_create_module': {
          if (params.dryrun) {
            return { dryrun: true, moduleinfo: {} };
          }
          cmid += 1;
          created[cmid] = { ...params, settings: JSON.parse(params.settings) };
          return { cmid, warnings: [] };
        }
        case 'local_aibridge_get_module': {
          const c = created[params.cmid];
          const s = c.settings;
          return {
            name: c.name, cm: { visible: 0 }, url: `u/${params.cmid}`,
            instance: { sumgrades: c.modname === 'quiz' ? '20.00000' : undefined },
            content: { slotcount: 3 },
            extensions: {
              ...(s.efenotes_enabled ? { efe: { competences_json: { quality: s.efenotes_competence } } } : {}),
              ...(c.modname === 'assign' ? { aifeedback: { enabled: 1 }, aichat: { enabled: '1' } } : {}),
              ...(c.name.startsWith('Test sommatif') ? { classhours: { supervised: true, exammode: 1 } } : {}),
            },
          };
        }
        default: return { ok: true };
      }
    },
  };
}

test('deployModule en simulation : rien n\'est créé, chaque création est simulée par Moodle', async () => {
  const manifest = await moduleOnDisk();
  const moodle = fakeMoodle();
  const report = await deployModule(moodle, manifest, { dryRun: true });
  assert.ok(moodle.calls.every((c) => c.fn === 'local_aibridge_get_course' || c.params.dryrun === true));
  assert.deepEqual(report.activities.map((a) => a.key), ['lecon', 'entrainement', 'tp1', 'sommatif']);
  assert.match(report.warnings.join('\n'), /video : rôle « video » non déployable/);
});

test('deployModule : ordre des appels, tirages, questions fixes, surveillance et rapport sans écart', async () => {
  const manifest = await moduleOnDisk();
  const moodle = fakeMoodle();
  const report = await deployModule(moodle, manifest);
  const fns = moodle.calls.map((c) => c.fn.replace('local_aibridge_', ''));
  assert.deepEqual(fns.slice(0, 6), ['get_course', 'upsert_section', 'create_module', 'set_lesson_pages',
    'set_module_extras', 'import_questions']);

  const pages = JSON.parse(moodle.calls.find((c) => c.fn.endsWith('set_lesson_pages')).params.pages);
  assert.deepEqual(pages.map((p) => p.title), ['Images', 'Les convolutions']);

  const layouts = moodle.calls.filter((c) => c.fn.endsWith('set_quiz_layout')).map((c) => JSON.parse(c.params.layout));
  assert.deepEqual(layouts[0].slots, [{ random: { categoryid: 101 }, count: 2, maxmark: 1 },
    { random: { categoryid: 102 }, count: 1, maxmark: 1 }]);
  assert.equal(layouts[0].shufflequestions, false);
  assert.deepEqual(layouts[1].slots, [{ idnumber: 'IA-T5-Q1', maxmark: 8 }, { idnumber: 'IA-T5-Q2', maxmark: 12 }]);
  assert.equal(layouts[1].shufflequestions, true);

  const aiImport = moodle.calls.filter((c) => c.fn.endsWith('import_questions'))[1];
  assert.equal(aiImport.params.category, 'M5 - Entraînement — IA');
  assert.equal(aiImport.params.parent, 'M5 - Entraînement');

  const extras = moodle.calls.filter((c) => c.fn.endsWith('set_module_extras')).map((c) => JSON.parse(c.params.extras));
  assert.deepEqual(extras[3], { supervised: { planned: '2026-11-16T09:20', sessioncode: true, exammode: true },
    avancement: { duedate: '2026-11-20T17:45' } });

  const tp = JSON.parse(moodle.calls.find((c) => c.fn.endsWith('create_module') && c.params.modname === 'assign').params.settings);
  assert.equal(tp.activity, '<p>Mission</p>');
  assert.equal(tp.assignfeedback_ai_expectedanswer, 'Noter sur 100.');
  assert.equal(tp.efenotes_prof_moodleid, 2);
  assert.equal(tp.duedate, '2026-11-09T12:00');

  assert.deepEqual(report.differences, []);
});

test('deployModule refuse de créer en double une activité déjà présente dans la section', async () => {
  const manifest = await moduleOnDisk();
  const moodle = fakeMoodle({ existing: [{ cmid: 9, name: 'TP5.1' }] });
  await assert.rejects(deployModule(moodle, manifest), /Déjà présent dans la section 5 : TP5.1/);
  assert.equal(moodle.calls.length, 1);
});
