#!/usr/bin/env node
// Contrôle d'un module de parcours avant déploiement : manifeste, HTML, dossiers de TP, questions
// XML, vidéo. Node >= 18, aucune dépendance.
//
//   node check-module.mjs <dossier du module> [--efe efe-competences.json]
//
// Sortie : ERREUR (bloque le déploiement), AVERT (à justifier), INFO. Code de sortie 1 s'il y a
// au moins une erreur.

import fs from 'node:fs';
import path from 'node:path';

const args = process.argv.slice(2);
const dir = args.find((a) => !a.startsWith('--'));
const efeIndex = args.indexOf('--efe');
const efeFile = efeIndex >= 0 ? args[efeIndex + 1] : null;
if (!dir) {
  console.error('Usage : node check-module.mjs <dossier du module> [--efe efe-competences.json]');
  process.exit(2);
}

const report = { ERREUR: [], AVERT: [], INFO: [] };
const err = (where, msg) => report.ERREUR.push(`${where} : ${msg}`);
const warn = (where, msg) => report.AVERT.push(`${where} : ${msg}`);
const info = (where, msg) => report.INFO.push(`${where} : ${msg}`);
const file = (name) => path.join(dir, name);
const read = (name) => fs.readFileSync(file(name), 'utf8');
const exists = (name) => !!name && fs.existsSync(file(name));

const ROLES = ['lecon', 'video', 'entrainement', 'tp', 'quiz-ia', 'sommatif', 'finale', 'page', 'label', 'resource', 'url'];
const EFE_ROLES = ['tp', 'quiz-ia', 'sommatif', 'finale'];
const QUIZ_ROLES = ['entrainement', 'quiz-ia', 'sommatif', 'finale'];
const HTML_ROLES = ['lecon', 'page', 'label'];
const AI_QTYPES = ['aiessay', 'aishortanswer'];
const THEME_CLASSES = ['objectif', 'astuce', 'danger', 'suite', 'formule', 'comp', 'carte', 'carte-item', 'icon',
  'cdc', 'rendre', 'phase', 'install', 'sev-crit', 'sev-high', 'sev-med', 'sev-low', 'modcode', 'img-fluid'];
const N1 = /^C(0[1-9]|1[01])$/;
const N2N3 = /^C(0[1-9]|1[01])\.\d+(\.([RD]\d\.T\d+|\d+))?$/;
const DATE = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/;
const BEGIN = /<!--\s*▶\s*DÉBUT CONTENU MOODLE\s*◀\s*-->/g;
const END = /<!--\s*▶\s*FIN CONTENU MOODLE\s*◀\s*-->/g;

const textOf = (html) => html.replace(/<!\[CDATA\[|\]\]>/g, '').replace(/<[^>]+>/g, ' ')
  .replace(/&nbsp;/g, ' ').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&')
  .replace(/\s+/g, ' ').trim();
const normalize = (s) => s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, ' ').trim();

// ---------------------------------------------------------------------------------------------
// Manifeste
// ---------------------------------------------------------------------------------------------

let manifest = null;
if (!exists('module.deploy.json')) {
  err('module.deploy.json', 'absent');
} else {
  try {
    manifest = JSON.parse(read('module.deploy.json'));
  } catch (e) {
    err('module.deploy.json', `JSON invalide (${e.message})`);
  }
}

let efeCodes = null;
if (efeFile) {
  try {
    const efe = JSON.parse(fs.readFileSync(efeFile, 'utf8'));
    efeCodes = new Set([...(efe.n2 ?? []), ...(efe.n3 ?? [])].map((c) => c.code));
  } catch (e) {
    err(efeFile, `référentiel EFE illisible (${e.message})`);
  }
}

const activities = Array.isArray(manifest?.activities) ? manifest.activities : [];
const keys = new Set();
if (manifest) {
  const m = 'module.deploy.json';
  if (manifest.format !== 'moodle-ciel/module@1') err(m, 'format attendu « moodle-ciel/module@1 »');
  if (!manifest.section?.name) err(m, 'section.name manquant');
  if (!Number.isInteger(manifest.section?.number)) err(m, 'section.number manquant');
  if (!Number.isInteger(manifest.course?.id) || manifest.course.id <= 0) warn(m, 'course.id à renseigner (cours bac à sable cible)');
  if (!manifest.prefix) warn(m, 'prefix manquant (préfixe des idnumbers : CY, IA…)');
  if (!activities.length) err(m, 'aucune activité');
}

for (const [i, a] of activities.entries()) {
  const where = `activité ${a.key ?? i + 1}`;
  if (!a.key) err(where, 'key manquante');
  else if (keys.has(a.key)) err(where, 'key en double');
  else keys.add(a.key);
  if (!ROLES.includes(a.role)) err(where, `rôle inconnu « ${a.role} »`);
  if (!a.name) err(where, 'name manquant');
  if (['lecon', 'page', 'label', 'video', 'resource'].includes(a.role) && !exists(a.file)) err(where, `fichier « ${a.file} » introuvable`);
  if (QUIZ_ROLES.includes(a.role)) {
    if (!exists(a.questions?.file)) err(where, `questions.file « ${a.questions?.file} » introuvable`);
    if (!a.questions?.category) warn(where, 'questions.category manquante');
  }
  if (a.role === 'entrainement' && !(a.questions?.draw > 0)) err(where, 'questions.draw (nombre de questions tirées) manquant');
  if (['sommatif', 'finale', 'quiz-ia'].includes(a.role) && !(a.points > 0)) warn(where, 'points (total attendu) manquant');
  if (['sommatif', 'finale'].includes(a.role)) {
    if (!a.supervised) err(where, 'test sommatif sans « supervised » (activité surveillée)');
    else {
      if (!a.supervised.sessioncode || !a.supervised.exammode) warn(where, 'code de séance et mode examen attendus');
      if (a.supervised.planned && !DATE.test(a.supervised.planned)) err(where, `supervised.planned : date « AAAA-MM-JJTHH:MM » attendue`);
      if (!a.supervised.planned) warn(where, 'date prévue (supervised.planned) manquante');
    }
  }
  if (a.role === 'tp') {
    if (!a.dir || !fs.existsSync(file(a.dir))) err(where, `dossier du TP « ${a.dir} » introuvable`);
    if (!a.submission?.type) warn(where, 'submission.type manquant (file ou onlinetext)');
  }
  if (a.duedate && !DATE.test(a.duedate)) err(where, `duedate : date « AAAA-MM-JJTHH:MM » attendue`);

  // EFE
  if (a.efe) {
    if (!EFE_ROLES.includes(a.role)) err(where, `pas d'EFE pour le rôle « ${a.role} » (entraînement et vidéo restent hors EFE)`);
    const codes = a.efe.competences ?? [];
    if (!codes.length) err(where, 'efe.competences vide');
    if (codes.length > 1) warn(where, 'plusieurs compétences EFE : une seule par activité notée, en principe');
    for (const code of [...codes, ...(a.efe.ponctualite ?? [])]) {
      if (N1.test(code)) err(where, `code EFE « ${code} » de niveau N1 : N2 au minimum`);
      else if (!N2N3.test(code)) err(where, `code EFE « ${code} » mal formé`);
      else if (efeCodes && !efeCodes.has(code)) err(where, `code EFE « ${code} » absent du référentiel EFE`);
    }
    if (a.efe.ponctualite?.length && a.role !== 'tp') warn(where, 'ponctualité EFE sur un test : réservée aux TP');
    if (a.efe.after_due === false) warn(where, 'report EFE immédiat (after_due: false) : la règle est de reporter après la date de rendu');
    if (a.efe.after_due !== false && !a.duedate) err(where, 'report après la date de rendu sans duedate');
  } else if (EFE_ROLES.includes(a.role)) {
    warn(where, 'activité notée sans EFE');
  }
}

// ---------------------------------------------------------------------------------------------
// HTML
// ---------------------------------------------------------------------------------------------

function checkHtmlFragment(where, html) {
  for (const tag of ['script', 'style', 'iframe', 'center', 'font']) {
    if (new RegExp(`<${tag}[\\s>]`, 'i').test(html)) err(where, `balise <${tag}> interdite`);
  }
  if (/\son[a-z]+\s*=/i.test(html)) err(where, 'attribut d\'événement (on…) interdit');
  for (const m of html.matchAll(/style="([^"]*)"/g)) {
    const style = m[1].trim();
    if (!/^text-align:\s*center;?$/i.test(style) && !/^color:\s*#[0-9a-f]{3,6};?$/i.test(style)) {
      warn(where, `style en ligne « ${style} » (seuls le centrage d'image et la couleur dans un pre sont admis)`);
    }
  }
  const unknown = new Set();
  for (const m of html.matchAll(/class="([^"]*)"/g)) {
    for (const c of m[1].split(/\s+/).filter(Boolean)) {
      if (!THEME_CLASSES.includes(c) && !c.startsWith('language-')) unknown.add(c);
    }
  }
  if (unknown.size) warn(where, `classes absentes du thème : ${[...unknown].join(', ')}`);
  for (const m of html.matchAll(/<img\b[^>]*>/gi)) {
    const src = (m[0].match(/src="([^"]*)"/) ?? [])[1];
    if (!/alt="[^"]+"/.test(m[0])) warn(where, `image sans alt (${src})`);
    if (src && !/^(https?:|@@PLUGINFILE@@)/.test(src) && !exists(src)) err(where, `image « ${src} » introuvable`);
  }
  for (const m of html.matchAll(/@@CM:([a-z0-9_-]+)@@/gi)) {
    if (!keys.has(m[1])) err(where, `lien @@CM:${m[1]}@@ vers une activité absente du manifeste`);
  }
  for (const m of html.matchAll(/<!--\s*IMAGE À FOURNIR\s*:?([^>]*)-->/g)) info(where, `image à fournir par l'enseignant :${m[1]}`);
}

function checkLesson(name) {
  const html = read(name);
  const begins = [...html.matchAll(BEGIN)].length;
  const ends = [...html.matchAll(END)].length;
  if (!begins) { err(name, 'aucun marqueur ▶ DÉBUT CONTENU MOODLE ◀'); return; }
  if (begins !== ends) { err(name, `marqueurs déséquilibrés (${begins} début, ${ends} fin)`); return; }
  const blocks = [...html.matchAll(/<!--\s*▶\s*DÉBUT CONTENU MOODLE\s*◀\s*-->([\s\S]*?)<!--\s*▶\s*FIN CONTENU MOODLE\s*◀\s*-->/g)].map((m) => m[1]);
  const sizes = [];
  blocks.forEach((block, i) => {
    const where = `${name} page ${i + 1}`;
    if (BEGIN.test(block)) err(where, 'marqueur de début imbriqué');
    BEGIN.lastIndex = 0;
    const h1 = (block.match(/<h1[\s>]/gi) ?? []).length;
    if (h1 !== 1) warn(where, `${h1} titre(s) <h1> : un seul par page (il donne le titre de la page)`);
    checkHtmlFragment(where, block);
    if (/⏱|durée\s+(estimée|de lecture)|<strong>\s*durée\s*:/i.test(block)) {
      warn(where, 'durée affichée dans la leçon : elle va dans la fiche enseignant (un élève qui la lit prend son temps)');
    }
    sizes.push(block.length);
  });
  const total = sizes.reduce((a, b) => a + b, 0);
  info(name, `${blocks.length} page(s), ${total} caractères (${sizes.join(' / ')})`);
  if (blocks.length === 1 && total > 12000) info(name, 'page unique de plus de 12 000 caractères : envisager un découpage par notion');
  if (blocks.length > 1 && total < 6000) info(name, 'leçon courte sur plusieurs pages : une seule page suffirait sans doute');
  if (!/class="objectif"/.test(blocks[0] ?? '')) warn(`${name} page 1`, 'pas d\'encadré objectif');
}

for (const a of activities.filter((x) => HTML_ROLES.includes(x.role) && exists(x.file))) {
  if (a.role === 'lecon') checkLesson(a.file);
  else checkHtmlFragment(a.file, read(a.file));
}

// ---------------------------------------------------------------------------------------------
// TP
// ---------------------------------------------------------------------------------------------

for (const a of activities.filter((x) => x.role === 'tp' && x.dir && fs.existsSync(file(x.dir)))) {
  const p = (n) => path.join(a.dir, n);
  for (const n of ['consigne.html', 'enonce.txt', 'corrige.txt', 'competences.txt']) {
    if (!exists(p(n)) || !read(p(n)).trim()) err(p(n), 'absent ou vide');
  }
  if (!exists(p('consigne.html')) || !exists(p('corrige.txt'))) continue;
  const consigne = read(p('consigne.html'));
  checkHtmlFragment(p('consigne.html'), consigne);
  const corrige = read(p('corrige.txt'));
  if (!/sur 100/i.test(corrige)) warn(p('corrige.txt'), 'le barème doit être noté sur 100 (la Correction IA renvoie un score sur 100)');
  const blocks = [...corrige.matchAll(/^\s*\[(\d+(?:[.,]\d+)?)\s*pts?\]/gim)].map((m) => Number(m[1].replace(',', '.')));
  if (blocks.length && Math.abs(blocks.reduce((x, y) => x + y, 0) - 100) > 0.01) {
    warn(p('corrige.txt'), `les blocs [xx pts] totalisent ${blocks.reduce((x, y) => x + y, 0)} et non 100`);
  }
  // Fuite du corrigé : une suite de 8 mots du corrigé retrouvée telle quelle dans la consigne.
  const words = (s) => normalize(s).split(' ').filter(Boolean);
  const grams = (list, n) => list.slice(0, Math.max(0, list.length - n + 1)).map((_, k) => list.slice(k, k + n).join(' '));
  const consigneGrams = new Set(grams(words(textOf(consigne)), 8));
  const leak = grams(words(corrige), 8).find((g) => consigneGrams.has(g));
  if (leak) err(p('consigne.html'), `reprend un passage du corrigé : « ${leak} »`);
  if (exists(p('competences.txt'))) {
    for (const line of read(p('competences.txt')).split(/\r?\n/).filter((l) => l.trim())) {
      if (!/^C(0[1-9]|1[01])\s*[—-]/.test(line.trim())) warn(p('competences.txt'), `ligne « ${line.trim()} » : format « Cxx — Libellé » attendu`);
    }
  }
}

// ---------------------------------------------------------------------------------------------
// Questions XML
// ---------------------------------------------------------------------------------------------

const idnumbers = new Map();

function questionsOf(xml) {
  return [...xml.matchAll(/<question type="([^"]+)">([\s\S]*?)<\/question>/g)].map((m) => {
    const body = m[2];
    const tagText = (tag) => (body.match(new RegExp(`<${tag}[^>]*>\\s*(?:<text>)?([\\s\\S]*?)(?:</text>)?\\s*</${tag}>`)) ?? [])[1];
    return {
      type: m[1], body,
      name: textOf(tagText('name') ?? ''),
      idnumber: textOf(tagText('idnumber') ?? ''),
      grade: Number(tagText('defaultgrade') ?? 1),
      penalty: Number(tagText('penalty') ?? 0),
      text: tagText('questiontext') ?? '',
      tags: [...body.matchAll(/<tag>\s*<text>([^<]*)<\/text>\s*<\/tag>/g)].map((t) => t[1].trim()),
    };
  });
}

function clozePoints(text) {
  return [...textOf(text).matchAll(/\{(\d*):(?:NUMERICAL|NM|MULTICHOICE[A-Z_]*|MC[A-Z]*|SHORTANSWER[A-Z_]*|SA|MW)/g)]
    .reduce((sum, m) => sum + (m[1] ? Number(m[1]) : 1), 0);
}

function checkQuestions(a) {
  const name = a.questions.file;
  const xml = read(name);
  if ((xml.match(/<question\b/g) ?? []).length !== (xml.match(/<\/question>/g) ?? []).length) err(name, 'balises <question> déséquilibrées');
  if (/<question type="category">/.test(xml)) warn(name, 'contient une <category> : le déploiement importe dans la catégorie du manifeste');
  const qs = questionsOf(xml).filter((q) => q.type !== 'category');
  const graded = a.role !== 'entrainement';
  // Défauts répétés : un seul avertissement par fichier, avec le nombre de questions concernées.
  const missing = { idnumber: 0, prefix: 0, tag: 0, penalty: 0 };
  let points = 0;
  let aiCount = 0;
  const pointsByTag = new Map();
  for (const q of qs) {
    const where = `${name} « ${q.name || '(sans nom)'} »`;
    if (!q.name) err(where, 'nom manquant');
    if (!q.text.trim()) err(where, 'énoncé manquant');
    if (!q.idnumber) missing.idnumber += 1;
    else if (idnumbers.has(q.idnumber)) err(where, `idnumber « ${q.idnumber} » en double (aussi dans ${idnumbers.get(q.idnumber)})`);
    else idnumbers.set(q.idnumber, name);
    if (manifest?.prefix && q.idnumber && !q.idnumber.startsWith(`${manifest.prefix}-`)) missing.prefix += 1;
    if (q.type !== 'description' && !q.tags.some((t) => N1.test(t))) missing.tag += 1;
    // La pénalité n'a d'effet qu'en mode interactif ; on ne la signale que sur les tests notés.
    if (graded && q.penalty !== 0 && q.type !== 'description') missing.penalty += 1;
    if (q.type === 'multichoice' && /<single>\s*true\s*<\/single>/.test(q.body)) {
      const full = (q.body.match(/<answer fraction="100(?:\.0+)?"/g) ?? []).length;
      if (full !== 1) err(where, `${full} bonne(s) réponse(s) à 100 % pour un QCM à réponse unique`);
    }
    if (q.type === 'matching' && (q.body.match(/<subquestion\b/g) ?? []).length < 3) warn(where, 'appariement de moins de 3 paires');
    if (AI_QTYPES.includes(q.type)) {
      aiCount += 1;
      if (!textOf((q.body.match(/<expectedanswer>([\s\S]*?)<\/expectedanswer>/) ?? [])[1] ?? '')) err(where, 'corrigé attendu (expectedanswer) vide');
      if (q.type === 'aiessay' && !textOf((q.body.match(/<competencies>([\s\S]*?)<\/competencies>/) ?? [])[1] ?? '')) warn(where, 'compétences évaluées vides');
      if (textOf((q.body.match(/<apikey>([\s\S]*?)<\/apikey>/) ?? [])[1] ?? '')) err(where, 'une clé d\'API est présente dans le XML');
    }
    const qPoints = q.type === 'multianswer' || q.type === 'cloze' ? clozePoints(q.text) : (q.type === 'description' ? 0 : q.grade);
    points += qPoints;
    for (const t of new Set(q.tags.filter((tag) => N1.test(tag)))) pointsByTag.set(t, (pointsByTag.get(t) ?? 0) + qPoints);
  }
  // La compétence rapportée à EFE doit être celle que le test mesure : au moins la moitié des points.
  const efeN1 = (a.efe?.competences?.[0] ?? '').split('.')[0];
  if (graded && N1.test(efeN1) && points > 0) {
    const share = (pointsByTag.get(efeN1) ?? 0) / points;
    if (share < 0.5) {
      warn(name, `le test rapporte ${a.efe.competences[0]} à EFE, mais les questions étiquetées ${efeN1} ne font que ${Math.round(share * 100)} % des points`);
    }
  }
  if (missing.idnumber) warn(name, `${missing.idnumber} question(s) sans idnumber`);
  if (missing.prefix) warn(name, `${missing.prefix} idnumber(s) sans le préfixe ${manifest.prefix}-`);
  if (missing.tag) warn(name, `${missing.tag} question(s) sans étiquette de compétence (C01…C11)`);
  if (missing.penalty) warn(name, `${missing.penalty} question(s) avec une pénalité non nulle (0 attendu)`);
  info(name, `${qs.length} question(s), ${points} point(s), ${aiCount} corrigée(s) par IA`);
  if (a.role === 'entrainement') {
    if (qs.length < 15) warn(name, `${qs.length} questions : une banque d'entraînement en compte 15 à 25`);
    const drawAi = a.questions.draw_ai ?? 0;
    if (aiCount < drawAi) err(name, `draw_ai = ${drawAi} mais seulement ${aiCount} question(s) IA`);
    if (qs.length - aiCount < a.questions.draw) err(name, `draw = ${a.questions.draw} mais seulement ${qs.length - aiCount} question(s) hors IA`);
  } else if (a.points > 0 && Math.abs(points - a.points) > 0.01) {
    err(name, `total de ${points} points, ${a.points} attendus`);
  }
}

for (const a of activities.filter((x) => QUIZ_ROLES.includes(x.role) && exists(x.questions?.file))) checkQuestions(a);

// ---------------------------------------------------------------------------------------------
// Vidéo
// ---------------------------------------------------------------------------------------------

for (const a of activities.filter((x) => x.role === 'video' && exists(x.file))) {
  let v;
  try { v = JSON.parse(read(a.file)); } catch (e) { err(a.file, `JSON invalide (${e.message})`); continue; }
  if (!/^https:\/\/(www\.)?(youtube\.com\/watch\?v=|youtu\.be\/)[\w-]{11}/.test(v.youtubeurl ?? '')) err(a.file, 'youtubeurl : lien YouTube attendu');
  if (!v.pertinence) warn(a.file, 'pertinence non justifiée : une vidéo ne se propose que pour un concept dynamique difficile');
  const checkList = (list, label, withTime) => {
    (list ?? []).forEach((q, i) => {
      const where = `${a.file} ${label} ${i + 1}`;
      if (!q.question) err(where, 'question vide');
      if (!Array.isArray(q.choices) || q.choices.length !== 4) err(where, 'exactement 4 choix attendus');
      if (!Number.isInteger(q.correct) || q.correct < 0 || q.correct > 3) err(where, 'correct : index 0 à 3 attendu');
      if (withTime && !(Number.isFinite(q.time) || q.time === 'end')) err(where, 'time : seconde ou "end" attendu');
    });
  };
  if (!v.questions?.length) err(a.file, 'aucune question pendant la vidéo');
  checkList(v.questions, 'question', true);
  checkList(v.livequestions, 'question live', false);
}

// ---------------------------------------------------------------------------------------------
// Rapport
// ---------------------------------------------------------------------------------------------

for (const level of ['ERREUR', 'AVERT', 'INFO']) {
  for (const line of report[level]) console.log(`${level.padEnd(6)} ${line}`);
}
console.log(`\n${report.ERREUR.length} erreur(s), ${report.AVERT.length} avertissement(s).`);
process.exit(report.ERREUR.length ? 1 : 0);
