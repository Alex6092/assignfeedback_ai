import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { test } from 'node:test';
import { cmLinks, deployModule, LESSON_IMAGES, pageTitle, questionMarks, readLesson, relativeImageSources,
  resolveCmLinks, splitAiQuestions } from '../src/deploy.js';
import { review, roleSettings } from '../src/roles.js';

const WWWROOT = 'https://moodle.test/sous-dossier';

const q = (type, id, grade = 1) => `<question type="${type}"><name><text>${id}</text></name>
<defaultgrade>${grade}</defaultgrade><idnumber>${id}</idnumber></question>`;
const quiz = (...questions) => `<?xml version="1.0" encoding="UTF-8"?>\n<quiz>\n${questions.join('\n')}\n</quiz>`;
const block = (html) => `<!-- ▶ DÉBUT CONTENU MOODLE ◀ -->\n${html}\n<!-- ▶ FIN CONTENU MOODLE ◀ -->`;
/** Octets d'une image : en-tête PNG suivi d'un marqueur, pour reconnaître chaque fichier. */
const png = (mark) => Buffer.concat([Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]), Buffer.from(mark)]);

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

/**
 * Module minimal sur disque : leçon de 2 pages (deux images, dont une citée par les deux pages), entraînement avec une
 * question IA, TP, sommatif.
 */
async function moduleOnDisk() {
  const dir = await fs.mkdtemp(path.join(os.tmpdir(), 'deploy-'));
  await fs.writeFile(path.join(dir, 'lecon.html'), `<html><body>${block('<h1>📘 Module 5 — Images</h1><p>a</p>'
    + '<p style="text-align: center;"><img class="img-fluid" src="images/reseau.png" alt="Réseau"></p>')}
${block('<h1>Les convolutions</h1><p>b</p><img src="images/conv.png" alt="Convolution">'
    + '<img src="./images/reseau.png" alt="Réseau, encore"><!-- <img src="images/brouillon.png"> -->'
    + '<img src="https://exemple.fr/x.png" alt="x">')}</body></html>`);
  await fs.mkdir(path.join(dir, 'images'));
  await fs.writeFile(path.join(dir, 'images', 'reseau.png'), png('reseau'));
  await fs.writeFile(path.join(dir, 'images', 'conv.png'), png('conv'));
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

/**
 * Faux Moodle : enregistre les appels et répond comme le pont. existing : activités déjà dans la section 5 ;
 * elsewhere : dans une autre section du cours ; lostImage : image que Moodle « perd ».
 */
function fakeMoodle({ existing = [], elsewhere = [], lostImage = null } = {}) {
  const calls = [];
  let cmid = 3000;
  let categoryid = 100;
  const created = {};
  return {
    calls,
    async callJson(fn, params = {}) {
      calls.push({ fn, params });
      switch (fn) {
        case 'local_aibridge_get_course': return { course: { url: `${WWWROOT}/course/view.php?id=${params.courseid}` },
          sections: [{ number: 4, cms: elsewhere }, { number: 5, cms: existing }] };
        case 'local_aibridge_upsert_section': return { sectionid: 1, section: params.section };
        case 'local_aibridge_import_questions': return params.dryrun ? { dryrun: true } : { categoryid: ++categoryid };
        case 'local_aibridge_create_module': {
          if (params.dryrun) {
            return { dryrun: true, moduleinfo: {} };
          }
          cmid += 1;
          created[cmid] = { ...params, settings: JSON.parse(params.settings) };
          return { cmid, url: `${WWWROOT}/mod/${params.modname}/view.php?id=${cmid}`, warnings: [] };
        }
        case 'local_aibridge_set_lesson_pages': {
          // Comme le pont : src relatif d'une image envoyée → @@PLUGINFILE@@/nom, image rangée dans chaque page qui la cite.
          const sent = JSON.parse(params.files ?? '[]').map((f) => f.filename);
          created[params.cmid].pages = JSON.parse(params.pages).map((p) => {
            const contents = p.contents.replace(/src="[^"]*?([^"/]+)"/g,
              (all, name) => (sent.includes(name) ? `src="@@PLUGINFILE@@/${name}"` : all));
            const files = sent.filter((name) => contents.includes(`@@PLUGINFILE@@/${name}"`) && name !== lostImage);
            return { contents, files: files.map((filename) => ({ filename })) };
          });
          return { cmid: params.cmid, images: sent.length, warnings: [] };
        }
        case 'local_aibridge_set_quiz_layout':
          created[params.cmid].slots = JSON.parse(params.layout).slots.reduce((n, s) => n + (s.count ?? 1), 0);
          return { ok: true };
        case 'local_aibridge_set_module_extras':
          created[params.cmid].extras = JSON.parse(params.extras);
          return { ok: true };
        case 'local_aibridge_get_module': {
          const c = created[params.cmid];
          const s = c.settings;
          const supervised = c.extras?.supervised;
          return {
            name: c.name, cm: { visible: 0 }, url: `u/${params.cmid}`,
            availability: { op: '&', c: (c.extras?.requires ?? []).map((cm) => ({ type: 'completion', cm, e: 1 })) },
            instance: { sumgrades: c.modname === 'quiz' ? '20.00000' : undefined },
            content: { slotcount: c.slots, pages: c.pages },
            extensions: {
              ...(s.efenotes_enabled ? { efe: { competences_json: { quality: s.efenotes_competence } } } : {}),
              ...(c.modname === 'assign' ? { aifeedback: { enabled: 1 }, aichat: { enabled: String(s.aichatenabled) } } : {}),
              ...(supervised ? { classhours: { supervised: true, exammode: supervised.exammode ? 1 : 0 } } : {}),
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
  assert.equal(report.activities[0].pages, 2);
  assert.equal(report.activities[0].images, 2);
  assert.match(report.warnings.join('\n'), /video : rôle « video » non déployable/);
});

test('deployModule : ordre des appels, tirages, questions fixes, surveillance et rapport sans écart', async () => {
  const manifest = await moduleOnDisk();
  const moodle = fakeMoodle();
  const report = await deployModule(moodle, manifest);
  const fns = moodle.calls.map((c) => c.fn.replace('local_aibridge_', ''));
  assert.deepEqual(fns.slice(0, 6), ['get_course', 'upsert_section', 'create_module', 'set_lesson_pages',
    'set_module_extras', 'import_questions']);

  const lessonCall = moodle.calls.find((c) => c.fn.endsWith('set_lesson_pages')).params;
  const pages = JSON.parse(lessonCall.pages);
  assert.deepEqual(pages.map((p) => p.title), ['Images', 'Les convolutions']);
  assert.match(pages[1].contents, /src="images\/conv.png"/, 'le pont réécrit les src, pas le serveur MCP');
  // Chaque image une seule fois, dans l'ordre où les pages la citent ; ni l'image en commentaire, ni l'URL absolue.
  const files = JSON.parse(lessonCall.files);
  assert.deepEqual(files.map((f) => f.filename), ['reseau.png', 'conv.png']);
  assert.deepEqual(Buffer.from(files[1].content, 'base64'), png('conv'));
  assert.ok(report.steps.some((s) => s.what === 'lecon : 2 page(s), 2 image(s)'));

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

test('relativeImageSources : src relatifs seulement, hors commentaires', () => {
  assert.deepEqual(relativeImageSources('<img src="images/a.png" alt="a"><img alt=\'b\' src=\'../_commun/b.svg\'>'
    + '<!-- <img src="images/c.png"> --><img src="http://x/d.png"><img src="//x/e.png"><img src="/pix/f.png">'
    + '<img src="data:image/png;base64,AA"><img src="@@PLUGINFILE@@/g.png"><img data-src="h.png" alt="sans src">'
    + '<pre><code>&lt;img src="i.png"&gt;</code></pre>'), ['images/a.png', '../_commun/b.svg']);
});

test('deployModule : une image manquante arrête le déploiement avant la création de la leçon, simulation comprise', async () => {
  const manifest = await moduleOnDisk();
  await fs.rm(path.join(path.dirname(manifest), 'images', 'conv.png'));
  for (const dryRun of [true, false]) {
    const moodle = fakeMoodle();
    await assert.rejects(deployModule(moodle, manifest, { dryRun }),
      /lecon\.html, page 2, image « images\/conv\.png » : fichier introuvable/);
    assert.ok(!moodle.calls.some((c) => c.fn.endsWith('create_module')));
  }
});

test('deployModule : une image citée mais absente de sa page dans Moodle est un écart', async () => {
  const moodle = fakeMoodle({ lostImage: 'conv.png' });
  const report = await deployModule(moodle, await moduleOnDisk());
  assert.deepEqual(report.differences, [{ key: 'lecon', what: 'images absentes des pages', expected: '', actual: 'conv.png' }]);
});

/** Leçon d'une page (contenu donné) et ses fichiers, avec son manifeste. */
async function leconOnDisk(html, files = {}, extra = []) {
  const dir = await fs.mkdtemp(path.join(os.tmpdir(), 'deploy-lecon-'));
  await fs.writeFile(path.join(dir, 'lecon.html'), block(`<h1>Leçon</h1>${html}`));
  for (const [name, bytes] of Object.entries(files)) {
    await fs.mkdir(path.dirname(path.join(dir, name)), { recursive: true });
    await fs.writeFile(path.join(dir, name), bytes);
  }
  const manifest = {
    format: 'moodle-ciel/module@1', course: { id: 74 }, section: { number: 5, name: 'M5' },
    activities: [{ key: 'lecon', role: 'lecon', name: 'Leçon', file: 'lecon.html' }, ...extra],
  };
  await fs.writeFile(path.join(dir, 'module.deploy.json'), JSON.stringify(manifest));
  return path.join(dir, 'module.deploy.json');
}

test('deployModule : sans image, pas de paramètre files (pont antérieur) ; image d\'une page signalée, non envoyée', async () => {
  const manifest = await leconOnDisk('<p>Rien à montrer.</p>', { 'page.html': '<p><img src="images/p.png" alt="p"></p>' },
    [{ key: 'page', role: 'page', name: 'Fiche', file: 'page.html' }]);
  const moodle = fakeMoodle();
  const report = await deployModule(moodle, manifest);
  const lessonCall = moodle.calls.find((c) => c.fn.endsWith('set_lesson_pages')).params;
  assert.equal('files' in lessonCall, false);
  assert.match(report.warnings.join('\n'), /page : image\(s\) en relatif non envoyée\(s\) par le pont, à déposer à la main dans l'éditeur : images\/p\.png\./);
  assert.deepEqual(report.differences, []);
});

test('readLesson refuse deux fichiers de même nom, un nom refusé par Moodle, une image trop lourde', async () => {
  const lecon = async (html, files) => path.join(path.dirname(await leconOnDisk(html, files)), 'lecon.html');
  await assert.rejects(readLesson(await lecon('<img src="a/x.png" alt="1"><img src="b/x.png" alt="2">',
    { 'a/x.png': png('a'), 'b/x.png': png('b') })), /image « b\/x\.png » : même nom que « a\/x\.png » pour un autre fichier/);
  await assert.rejects(readLesson(await lecon('<img src="images/mon%20schema.png" alt="1">',
    { 'images/mon schema.png': png('a') })), /nom refusé par le pont/);
  await assert.rejects(readLesson(await lecon('<img src="images/x.bmp" alt="1">', { 'images/x.bmp': png('a') })),
    /nom refusé par le pont/);
  await assert.rejects(readLesson(await lecon('<img src="images/gros.png" alt="1">',
    { 'images/gros.png': Buffer.alloc(LESSON_IMAGES.maxFileBytes + 1) })), /2,0 Mo au plus par image/);
  const three = { 'i/a.png': Buffer.alloc(1.5 * 1024 * 1024), 'i/b.png': Buffer.alloc(1.5 * 1024 * 1024),
    'i/c.png': Buffer.alloc(1.5 * 1024 * 1024) };
  await assert.rejects(readLesson(await lecon('<img src="i/a.png"><img src="i/b.png"><img src="i/c.png">', three)),
    /3 image\(s\), 4,5 Mo ; le pont en reçoit 40 et 4,0 Mo au plus par leçon/);
  // Même fichier par deux chemins, nom accentué encodé : une seule image, sous son nom décodé.
  const ok = await readLesson(await lecon('<img src="images/sch%C3%A9ma.png"><img src="./images/schéma.png?v=2">',
    { 'images/schéma.png': png('s') }));
  assert.deepEqual(ok.files.map((f) => f.filename), ['schéma.png']);
});

test('deployModule refuse de créer en double une activité déjà présente dans la section', async () => {
  const manifest = await moduleOnDisk();
  const moodle = fakeMoodle({ existing: [{ cmid: 9, name: 'TP5.1' }] });
  await assert.rejects(deployModule(moodle, manifest), /Déjà présent dans la section 5 : TP5.1/);
  assert.equal(moodle.calls.length, 1);
});

test('rôle td : interactif, une seule page, tout montré, non noté, sans EFE', () => {
  const td = roleSettings({ role: 'td' }, { course: { teacher_id: 2 } });
  assert.equal(td.preferredbehaviour, 'interactive');
  assert.equal(td.questionsperpage, 0);
  assert.equal(td.attempts, 0);
  assert.equal(td.timelimit, 0);
  assert.equal(td.grade, 0);
  assert.equal(td.rightanswerduring, 1);
  assert.equal(td.generalfeedbackimmediately, 1);
  assert.equal(td.completionminattempts, 1);
  assert.equal(td.efenotes_enabled, undefined);
});

/** TD placé après la leçon, un TP évalué, et le bonus réservé à ceux qui l'ont rendu. */
async function tdEtBonusOnDisk() {
  const dir = await fs.mkdtemp(path.join(os.tmpdir(), 'deploy-td-'));
  await fs.writeFile(path.join(dir, 'td.xml'), quiz(q('description', 'CPP-M5-TD-T1', 0), q('multichoice', 'CPP-M5-TD-Q1'),
    q('description', 'CPP-M5-TD-T2', 0), q('coderunner', 'CPP-M5-TD-Q2', 2)));
  for (const tp of ['tpe1', 'tpb']) {
    await fs.mkdir(path.join(dir, tp));
    for (const [file, text] of [['consigne.html', '<p>Mission</p>'], ['enonce.txt', 'Énoncé'], ['corrige.txt', 'Noter sur 100.'],
      ['competences.txt', 'C08 — Coder']]) {
      await fs.writeFile(path.join(dir, tp, file), text);
    }
  }
  const evalue = {
    role: 'tp', submission: { type: 'file', filetypes: '.cpp', maxfiles: 1 }, efe: { competences: ['C08.3.D2.T3'], after_due: false },
    supervised: { sessioncode: true, exammode: false },
    overrides: { aichatenabled: 0, assignfeedback_comments_enabled: 0, assignfeedback_file_enabled: 0 },
  };
  const manifest = {
    format: 'moodle-ciel/module@1', course: { id: 74, teacher_id: 2 }, section: { number: 5, name: 'M5 - Mémoire' }, prefix: 'CPP',
    activities: [
      { key: 'td', role: 'td', name: 'TD M5 - La mémoire', questions: { file: 'td.xml', category: 'M5 - TD' }, before: 2990 },
      { key: 'tpe1', ...evalue, name: 'TP évalué M5.1 - Pointeurs', dir: 'tpe1', before: 2999 },
      { key: 'tpb', ...evalue, name: 'TP bonus M5 - Mémoire de jeu', dir: 'tpb', requires: ['tpe1', 2995], before: 2999 },
    ],
  };
  await fs.writeFile(path.join(dir, 'td.deploy.json'), JSON.stringify(manifest));
  return path.join(dir, 'td.deploy.json');
}

test('deployModule : TD à questions fixes non mélangées, position « before », bonus avec activités à achever', async () => {
  const moodle = fakeMoodle();
  const report = await deployModule(moodle, await tdEtBonusOnDisk());
  const creations = moodle.calls.filter((c) => c.fn.endsWith('create_module')).map((c) => c.params);
  assert.deepEqual(creations.map((c) => c.beforecmid), [2990, 2999, 2999]);

  const layout = JSON.parse(moodle.calls.find((c) => c.fn.endsWith('set_quiz_layout')).params.layout);
  assert.deepEqual(layout.slots.map((s) => s.maxmark), [0, 1, 0, 2]);
  assert.equal(layout.shufflequestions, false);

  const extras = moodle.calls.filter((c) => c.fn.endsWith('set_module_extras')).map((c) => JSON.parse(c.params.extras));
  assert.deepEqual(extras[2].requires, [3002, 2995]);
  assert.deepEqual(extras[2].supervised, { sessioncode: true, exammode: false });
  assert.equal(extras[0].requires, undefined);

  assert.deepEqual(report.differences, []);
});

test('deployModule refuse une référence à une activité inconnue ou placée plus bas', async () => {
  const file = await tdEtBonusOnDisk();
  const manifest = JSON.parse(await fs.readFile(file, 'utf8'));
  manifest.activities[1].requires = ['tpb'];
  await fs.writeFile(file, JSON.stringify(manifest));
  const moodle = fakeMoodle();
  await assert.rejects(deployModule(moodle, file), /tpe1 : « tpb » n'est ni un cmid ni la clé d'une activité placée plus haut/);
  assert.equal(moodle.calls.length, 0);
});

test('cmLinks et resolveCmLinks : clés et cmid, un lien inconnu reste tel quel', () => {
  const html = '<a href="@@CM:tp1@@">TP</a> <a href="@@CM:2264@@">M4</a> <a href="@@CM:tp1@@">encore</a> @@CM:video@@';
  assert.deepEqual(cmLinks(html), ['tp1', '2264', 'video']);
  assert.equal(resolveCmLinks(html, (ref) => ({ tp1: 'u/1', 2264: 'u/2264' })[ref]),
    '<a href="u/1">TP</a> <a href="u/2264">M4</a> <a href="u/1">encore</a> @@CM:video@@');
});

/**
 * Module dont les contenus se lient entre eux : résumé de section → leçon ; leçon → TP créé après elle, activité d'une
 * autre section (cmid 2264), vidéo (non créée par le pont) et elle-même ; consigne du TP → leçon ; page → TP.
 */
async function liensOnDisk(files = {}) {
  const dir = await fs.mkdtemp(path.join(os.tmpdir(), 'deploy-liens-'));
  const contents = {
    'resume.html': '<p>Commencer par <a href="@@CM:lecon@@">la leçon</a>.</p>',
    'lecon.html': block('<h1>Leçon</h1><p><a href="@@CM:tp1@@">le TP</a>, <a href="@@CM:2264@@">la leçon de M4</a>, '
      + '<a href="@@CM:video@@">la vidéo</a>, <a href="@@CM:lecon@@">cette leçon</a>.</p>'),
    'tp1/consigne.html': '<p>Relis <a href="@@CM:lecon@@">la leçon</a>.</p>',
    'tp1/enonce.txt': 'Énoncé', 'tp1/corrige.txt': 'Noter sur 100.', 'tp1/competences.txt': 'C08 — Coder',
    'page.html': block('<p>Avant <a href="@@CM:tp1@@">le TP</a>.</p>'),
    ...files,
  };
  for (const [name, text] of Object.entries(contents)) {
    await fs.mkdir(path.dirname(path.join(dir, name)), { recursive: true });
    await fs.writeFile(path.join(dir, name), text);
  }
  const manifest = {
    format: 'moodle-ciel/module@1', course: { id: 74 }, section: { number: 5, name: 'M5', summary_file: 'resume.html' },
    activities: [
      { key: 'lecon', role: 'lecon', name: 'Leçon', file: 'lecon.html' },
      { key: 'video', role: 'video', name: 'Vidéo', file: 'video.json' },
      { key: 'tp1', role: 'tp', name: 'TP5.1', dir: 'tp1', submission: { type: 'onlinetext' } },
      { key: 'fiche', role: 'page', name: 'Fiche', file: 'page.html' },
    ],
  };
  await fs.writeFile(path.join(dir, 'module.deploy.json'), JSON.stringify(manifest));
  return path.join(dir, 'module.deploy.json');
}

test('deployModule remplace les liens @@CM par l\'URL des activités, leçon et résumé en fin de déploiement', async () => {
  const moodle = fakeMoodle({ elsewhere: [{ cmid: 2264, modname: 'lesson', name: 'Leçon M4' }] });
  const report = await deployModule(moodle, await liensOnDisk());
  const url = (modname, cmid) => `${WWWROOT}/mod/${modname}/view.php?id=${cmid}`;
  const fns = moodle.calls.map((c) => c.fn.replace('local_aibridge_', ''));
  assert.ok(fns.indexOf('set_lesson_pages') > fns.lastIndexOf('create_module'), 'pages de la leçon après la création du TP');

  const [page] = JSON.parse(moodle.calls.find((c) => c.fn.endsWith('set_lesson_pages')).params.pages);
  assert.equal(page.contents, `<h1>Leçon</h1><p><a href="${url('assign', 3002)}">le TP</a>, <a href="${url('lesson', 2264)}">`
    + `la leçon de M4</a>, <a href="@@CM:video@@">la vidéo</a>, <a href="${url('lesson', 3001)}">cette leçon</a>.</p>`);
  const settings = (modname) => JSON.parse(moodle.calls.find((c) => c.fn.endsWith('create_module')
    && c.params.modname === modname).params.settings);
  assert.equal(settings('assign').activity, `<p>Relis <a href="${url('lesson', 3001)}">la leçon</a>.</p>`);
  assert.equal(settings('page').content, `<p>Avant <a href="${url('assign', 3002)}">le TP</a>.</p>`);

  const sections = moodle.calls.filter((c) => c.fn.endsWith('upsert_section')).map((c) => c.params);
  assert.equal(sections.length, 2);
  assert.equal('summary' in sections[0], false, 'résumé envoyé seulement quand la leçon existe');
  assert.equal(sections[1].summary, `<p>Commencer par <a href="${url('lesson', 3001)}">la leçon</a>.</p>`);
  assert.equal('name' in sections[1], false);

  assert.deepEqual(report.activities[0].links, ['tp1', '2264', 'video', 'lecon']);
  assert.match(report.warnings.join('\n'), /lecon : lien @@CM:video@@ vers une activité que le pont ne crée pas/);
  assert.ok(report.steps.some((s) => s.what === 'lecon : 1 page(s) (liens vers la suite du module)'));
  assert.deepEqual(report.differences, []);
});

test('deployModule en simulation : liens contrôlés, rien de remplacé, résumé simulé dès le début', async () => {
  const moodle = fakeMoodle({ elsewhere: [{ cmid: 2264, modname: 'lesson', name: 'Leçon M4' }] });
  const report = await deployModule(moodle, await liensOnDisk(), { dryRun: true });
  assert.ok(moodle.calls.every((c) => c.fn === 'local_aibridge_get_course' || c.params.dryrun === true));
  assert.match(moodle.calls.find((c) => c.fn.endsWith('upsert_section')).params.summary, /@@CM:lecon@@/);
  assert.deepEqual(report.activities.map((a) => a.links), [['tp1', '2264', 'video', 'lecon'], ['lecon'], ['tp1']]);
});

test('deployModule refuse, avant toute création, un lien en avant hors leçon, une clé ou un cmid inconnus', async () => {
  const forward = fakeMoodle();
  await assert.rejects(deployModule(forward, await liensOnDisk({ 'tp1/consigne.html': '<a href="@@CM:fiche@@">fiche</a>' })),
    /tp1 : lien @@CM:fiche@@ vers une activité créée après elle \(ou elle-même\)/);
  assert.equal(forward.calls.length, 0);

  const unknown = fakeMoodle();
  await assert.rejects(deployModule(unknown, await liensOnDisk({ 'page.html': block('<a href="@@CM:inconnue@@">?</a>') })),
    /fiche : lien @@CM:inconnue@@ vers une activité absente du manifeste/);
  assert.equal(unknown.calls.length, 0);

  const missing = fakeMoodle();
  await assert.rejects(deployModule(missing, await liensOnDisk()), /Lien @@CM:2264@@ : aucune activité 2264 dans le cours 74/);
  assert.deepEqual(missing.calls.map((c) => c.fn), ['local_aibridge_get_course']);
});

test('rôle tp : vision imposée au devoir seulement quand le manifeste la donne', () => {
  const manifest = { course: { teacher_id: 2 } };
  const efe = { competences: ['C04.1.D4.T5'] };
  const site = roleSettings({ role: 'tp', efe }, manifest);
  assert.equal(site.assignfeedback_ai_vision_enabled_override, 0);
  const on = roleSettings({ role: 'tp', efe, vision: true }, manifest);
  assert.equal(on.assignfeedback_ai_vision_enabled, 1);
  assert.equal(on.assignfeedback_ai_vision_enabled_override, 1);
  const off = roleSettings({ role: 'tp', efe, vision: false }, manifest);
  assert.equal(off.assignfeedback_ai_vision_enabled, 0);
  assert.equal(off.assignfeedback_ai_vision_enabled_override, 1);
});
