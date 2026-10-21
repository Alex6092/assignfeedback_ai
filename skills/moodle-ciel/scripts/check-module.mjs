#!/usr/bin/env node
// Contrôle d'un module de parcours avant déploiement : manifeste, HTML, dossiers de TP, questions
// XML, vidéo. Node >= 18, aucune dépendance.
//
//   node check-module.mjs <dossier du module> [--efe efe-competences.json] [--manifest tp-evalues.deploy.json]
//
// Sans --manifest, le manifeste contrôlé est module.deploy.json. Les TP évalués et le TP bonus générés par make-tpe.mjs
// ont leur propre manifeste (tp-evalues.deploy.json, ou tp-bonus.deploy.json pour un bonus seul), de même qu'un TD
// ajouté à un module déjà déployé (td.deploy.json) : contrôle-les avec --manifest.
//
// Sortie : ERREUR (bloque le déploiement), AVERT (à justifier), INFO. Code de sortie 1 s'il y a
// au moins une erreur.

import fs from 'node:fs';
import path from 'node:path';

const args = process.argv.slice(2);
const dir = args.find((a) => !a.startsWith('--'));
const efeIndex = args.indexOf('--efe');
const efeFile = efeIndex >= 0 ? args[efeIndex + 1] : null;
const manifestIndex = args.indexOf('--manifest');
const manifestName = manifestIndex >= 0 ? args[manifestIndex + 1] : 'module.deploy.json';
if (!dir) {
  console.error('Usage : node check-module.mjs <dossier du module> [--efe efe-competences.json] [--manifest fichier.deploy.json]');
  process.exit(2);
}

const report = { ERREUR: [], AVERT: [], INFO: [] };
const err = (where, msg) => report.ERREUR.push(`${where} : ${msg}`);
const warn = (where, msg) => report.AVERT.push(`${where} : ${msg}`);
const info = (where, msg) => report.INFO.push(`${where} : ${msg}`);
const file = (name) => path.join(dir, name);
const read = (name) => fs.readFileSync(file(name), 'utf8');
const exists = (name) => !!name && fs.existsSync(file(name));

const ROLES = ['lecon', 'video', 'td', 'entrainement', 'tp', 'quiz-ia', 'sommatif', 'finale', 'page', 'label', 'resource', 'url'];
const EFE_ROLES = ['tp', 'quiz-ia', 'sommatif', 'finale'];
const QUIZ_ROLES = ['td', 'entrainement', 'quiz-ia', 'sommatif', 'finale'];
const UNGRADED_QUIZ_ROLES = ['td', 'entrainement'];
const HTML_ROLES = ['lecon', 'page', 'label'];
const NOT_DEPLOYED = ['video', 'resource']; // rôles que le pont ne crée pas (à créer à la main)
const AI_QTYPES = ['aiessay', 'aishortanswer'];
const THEME_CLASSES = ['objectif', 'astuce', 'danger', 'suite', 'formule', 'comp', 'carte', 'carte-item', 'icon',
  'cdc', 'rendre', 'phase', 'install', 'sev-crit', 'sev-high', 'sev-med', 'sev-low', 'modcode', 'img-fluid', 'capture', 'reponse'];
// Nom d'une leçon qui contient encore des captures d'écran suggérées (encadrés class="capture") à faire par l'enseignant
const TODO_CAPTURES = /\(TODO : Captures\)\s*$/;
const N1 = /^C(0[1-9]|1[01])$/;
const N2N3 = /^C(0[1-9]|1[01])\.\d+(\.([RD]\d\.T\d+|\d+))?$/;
const DATE = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/;
const BEGIN = /<!--\s*▶\s*DÉBUT CONTENU MOODLE\s*◀\s*-->/g;
const END = /<!--\s*▶\s*FIN CONTENU MOODLE\s*◀\s*-->/g;
// Images d'une leçon envoyées par le pont : noms admis et limites de local/aibridge (lesson_writer.php).
const LESSON_IMAGE = /^[\p{L}\p{N}][\p{L}\p{N}._-]{0,99}\.(png|jpe?g|gif|svg)$/iu;
const MO = 1024 * 1024;
const mo = (bytes) => `${(bytes / MO).toFixed(1).replace('.', ',')} Mo`;

const textOf = (html) => html.replace(/<!\[CDATA\[|\]\]>/g, '').replace(/<[^>]+>/g, ' ')
  .replace(/&nbsp;/g, ' ').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&')
  .replace(/\s+/g, ' ').trim();
const normalize = (s) => s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, ' ').trim();
// src relatifs des images d'un fragment, commentaires exclus (ceux que le pont envoie avec une leçon).
const relativeImages = (html) => [...html.replace(/<!--[\s\S]*?-->/g, '').matchAll(/<img\b[^>]*>/gi)]
  .map((m) => (m[0].match(/\ssrc\s*=\s*(["'])([\s\S]*?)\1/i) ?? [])[2]?.trim())
  .filter((src) => src && !/^([a-z][a-z0-9+.-]*:|\/|#|@@)/i.test(src));
// Fichier désigné par un src relatif : sans ?… ni #…, %xx décodés.
const localPath = (src) => {
  const p = src.replace(/[?#][\s\S]*$/, '');
  try { return decodeURIComponent(p); } catch { return p; }
};

// ---------------------------------------------------------------------------------------------
// Manifeste
// ---------------------------------------------------------------------------------------------

let manifest = null;
if (!exists(manifestName)) {
  err(manifestName, 'absent');
} else {
  try {
    manifest = JSON.parse(read(manifestName));
  } catch (e) {
    err(manifestName, `JSON invalide (${e.message})`);
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
  const m = manifestName;
  if (manifest.format !== 'moodle-ciel/module@1') err(m, 'format attendu « moodle-ciel/module@1 »');
  if (!manifest.section?.name) err(m, 'section.name manquant');
  if (!Number.isInteger(manifest.section?.number)) err(m, 'section.number manquant');
  if (!Number.isInteger(manifest.course?.id) || manifest.course.id <= 0) warn(m, 'course.id à renseigner (cours bac à sable cible)');
  if (!manifest.prefix) warn(m, 'prefix manquant (préfixe des idnumbers : CY, IA…)');
  if (!activities.length) err(m, 'aucune activité');
  if (activities.some((a) => a.efe) && !(manifest.course?.teacher_id > 0)) {
    warn(m, 'course.teacher_id manquant : EFE désignerait le compte de service du pont comme enseignant');
  }
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
  if (a.role === 'td') {
    // TD : travail dirigé non évalué, fait à son rythme (pas de séance surveillée, pas d'EFE, questions fixes dans l'ordre)
    if (a.supervised) err(where, 'TD surveillé : un TD est un travail guidé non évalué, sans séance surveillée');
    if (a.questions?.draw) err(where, 'questions.draw sur un TD : ses textes et questions se suivent dans l\'ordre, sans tirage');
  }
  // Références à d'autres activités : cmid, ou clé d'une activité placée plus haut dans le manifeste
  for (const ref of [...(a.requires ?? []), ...(a.before === undefined ? [] : [a.before])]) {
    if (!Number.isInteger(ref) && !activities.slice(0, i).some((b) => b.key === ref)) {
      err(where, `« ${ref} » (requires / before) n'est ni un cmid ni la clé d'une activité placée plus haut`);
    }
  }
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
    if (a.supervised) {
      // TP évalué en classe (ou TP bonus) : devoir fermé avant la séance, surveillé, noté par la Correction IA seule
      const bonus = /bonus/i.test(a.name ?? '');
      if (!a.supervised.sessioncode) warn(where, 'code de séance attendu sur un TP évalué ou bonus');
      if (a.supervised.exammode) warn(where, 'mode examen sur un TP évalué ou bonus : il est désactivé (la leçon et le TD restent consultables) ; le mode examen est réservé au test sommatif');
      if (a.supervised.planned && !DATE.test(a.supervised.planned)) err(where, 'supervised.planned : date « AAAA-MM-JJTHH:MM » attendue');
      if (a.overrides?.aichatenabled !== 0) warn(where, 'Tuteur IA actif sur un TP évalué : ajouter overrides.aichatenabled = 0 (aucune aide en séance évaluée)');
      if (!a.efe || a.overrides?.efenotes_enabled === 0) err(where, `${bonus ? 'TP bonus' : 'TP évalué'} sans report EFE : tout ce qui est évalué remonte à EFE (code N2/N3)`);
      if (bonus && !a.requires?.length) err(where, 'TP bonus sans « requires » : il n\'ouvre qu\'à ceux qui ont rendu les TP évalués du module (leurs clés ou cmid)');
    } else if (a.efe && a.overrides?.efenotes_enabled !== 0) {
      warn(where, "TP d'entraînement (non surveillé) avec report EFE actif : seuls les TP évalués en classe et les tests sommatifs remontent à EFE (ajouter overrides.efenotes_enabled = 0 ou retirer efe)");
    }
  }
  if (a.duedate && !DATE.test(a.duedate)) err(where, `duedate : date « AAAA-MM-JJTHH:MM » attendue`);

  // EFE
  if (a.efe) {
    if (!EFE_ROLES.includes(a.role)) err(where, `pas d'EFE pour le rôle « ${a.role} » (TD, entraînement et vidéo restent hors EFE)`);
    const codes = a.efe.competences ?? [];
    if (!codes.length) err(where, 'efe.competences vide');
    if (codes.length > 1) warn(where, 'plusieurs compétences EFE : une seule par activité notée, en principe');
    for (const code of [...codes, ...(a.efe.ponctualite ?? [])]) {
      if (N1.test(code)) err(where, `code EFE « ${code} » de niveau N1 : N2 au minimum`);
      else if (!N2N3.test(code)) err(where, `code EFE « ${code} » mal formé`);
      else if (efeCodes && !efeCodes.has(code)) err(where, `code EFE « ${code} » absent du référentiel EFE`);
    }
    if (a.efe.ponctualite?.length && a.role !== 'tp') warn(where, 'ponctualité EFE sur un test : réservée aux TP');
    if (a.efe.after_due === false) {
      if (a.role === 'tp' && a.supervised) info(where, 'TP évalué : report EFE immédiat, sans date de rendu (à reprendre si tu poses des dates : after_due + duedate)');
      else warn(where, 'report EFE immédiat (after_due: false) : la règle est de reporter après la date de rendu');
    }
    if (a.efe.after_due !== false && !a.duedate) err(where, 'report après la date de rendu sans duedate');
  } else if (a.role === 'tp' && !a.supervised) {
    info(where, "TP d'entraînement : à la maison, sans EFE");
  } else if (EFE_ROLES.includes(a.role)) {
    warn(where, 'activité notée sans EFE');
  }
}

// ---------------------------------------------------------------------------------------------
// HTML
// ---------------------------------------------------------------------------------------------

function checkHtmlFragment(where, html, owner = null) {
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
  for (const m of html.replace(/<!--[\s\S]*?-->/g, '').matchAll(/<img\b[^>]*>/gi)) {
    const src = (m[0].match(/src="([^"]*)"/) ?? [])[1];
    if (!/alt="[^"]+"/.test(m[0])) warn(where, `image sans alt (${src})`);
    if (src && !/^(https?:|@@PLUGINFILE@@)/.test(src) && !exists(localPath(src))) err(where, `image « ${src} » introuvable`);
  }
  checkCmLinks(where, html, owner);
  for (const m of html.matchAll(/<!--\s*IMAGE À FOURNIR\s*:?([^>]*)-->/g)) info(where, `image à fournir par l'enseignant :${m[1]}`);
  checkDrawings(where, html);
}

// Diagrammes en caractères (boîtes ┌─┐, lignes de vie │, flèches ──▶ ou ----->) : à faire en PlantUML ; consignes qui font
// dessiner un diagramme en texte : à remplacer par une image (devoir) ou la source PlantUML (question IA). Les sources
// PlantUML (@startuml … @enduml) sont écartées ; une sortie console encadrée de +---+ ne déclenche rien.
const DRAWN_LINE = /[┌┐└┘├┤┬┴┼│◆◇▷◁▶◀]|-{3,}>|<-{3,}|<>-{2,}|─{2,}/;
const DRAW_IN_TEXT = /dessine[^.<\n]{0,80}en texte|(?:diagramme|schéma)[^.<\n]{0,60}(?:en texte|textuel)|description textuelle|uml\.txt/i;
function checkDrawings(where, text) {
  const lines = text.replace(/@startuml[\s\S]*?@enduml/g, '').split(/\r?\n|\\n/);
  let run = 0;
  for (const line of lines) {
    run = DRAWN_LINE.test(line) ? run + 1 : 0;
    if (run === 3) {
      warn(where, 'diagramme dessiné en caractères : le faire en PlantUML (image dans le texte, source dans un corrigé), voir style-html.md § 3 ; une sortie console reste en texte');
      break;
    }
  }
  const asked = textOf(text).match(DRAW_IN_TEXT);
  if (asked) warn(where, `« ${asked[0]} » : ne pas faire dessiner un diagramme en texte ; demander une image (devoir, vision: true) ou la source PlantUML (question IA)`);
}

// Liens @@CM:clé@@ (activité du manifeste) et @@CM:cmid@@ (activité existante du cours, vérifiée au déploiement). Une
// page, une étiquette ou une consigne de TP reçoit son contenu à sa création : sa cible doit être placée plus haut dans
// le manifeste. Une leçon et le résumé de la section (owner null) reçoivent le leur en fin de déploiement si besoin.
function checkCmLinks(where, html, owner) {
  const index = owner ? activities.indexOf(owner) : -1;
  const deferrable = !owner || owner.role === 'lecon';
  // Un lien mal fermé (« @@CM:td" ») n'est remplacé ni par le déploiement ni par les outils à l'unité : il reste cassé.
  for (const m of html.matchAll(/@@CM:([^@"'\s<>]*)(@@)?/g)) {
    if (!m[2] || !/^[A-Za-z0-9_-]+$/.test(m[1])) err(where, `lien « ${m[0]} » mal formé : écrire @@CM:clé@@ (deux @ de chaque côté), sinon il n'est pas remplacé et reste cassé`);
  }
  for (const ref of new Set([...html.matchAll(/@@CM:([A-Za-z0-9_-]+)@@/g)].map((m) => m[1]))) {
    const target = activities.findIndex((a) => a.key === ref);
    if (/^\d+$/.test(ref)) info(where, `lien @@CM:${ref}@@ vers l'activité ${ref} du cours (vérifiée au déploiement)`);
    else if (target < 0) err(where, `lien @@CM:${ref}@@ vers une activité absente du manifeste`);
    else if (NOT_DEPLOYED.includes(activities[target].role)) warn(where, `lien @@CM:${ref}@@ vers une activité que le pont ne crée pas (rôle « ${activities[target].role} ») : à remplacer à la main après le déploiement`);
    else if (!deferrable && target >= index) err(where, `lien @@CM:${ref}@@ vers une activité placée après elle (ou elle-même) : son contenu part à sa création ; placer la cible plus haut dans le manifeste (before règle la position dans la section), ou mettre le lien dans la leçon`);
  }
}

function checkLesson(name, owner) {
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
    checkHtmlFragment(where, block, owner);
    // Exercices « À toi ! » : le résultat attendu se cache derrière un bouton dépliant (<details class="reponse">).
    for (const d of block.matchAll(/<details\b[^>]*>[\s\S]*?<\/details>/gi)) {
      if (/class="[^"]*\breponse\b/.test(d[0]) && !/<summary[\s>]/i.test(d[0])) warn(where, '<details class="reponse"> sans <summary> : le bouton n\'a pas de libellé (« 👀 Voir le résultat attendu »)');
    }
    // Seuls les encadrés d'exercice comptent (un tableau de recette peut avoir une colonne « Résultat attendu »).
    for (const s of block.matchAll(/<div class="suite">([\s\S]*?)<\/div>/g)) {
      if (!/À toi/i.test(s[1])) continue;
      const visible = s[1].replace(/<details\b[^>]*class="[^"]*\breponse\b[^"]*"[^>]*>[\s\S]*?<\/details>/gi, '');
      if (/résultat attendu/i.test(textOf(visible))) {
        warn(where, 'résultat attendu d\'un exercice « À toi ! » visible d\'emblée : le mettre dans <details class="reponse"><summary>👀 Voir le résultat attendu</summary>…</details> (style-html.md § 4)');
        break;
      }
    }
    if (/⏱|durée\s+(estimée|de lecture)|<strong>\s*durée\s*:/i.test(block)) {
      warn(where, 'durée affichée dans la leçon : elle va dans la fiche enseignant (un élève qui la lit prend son temps)');
    }
    sizes.push(block.length);
  });
  // Images envoyées avec la leçon : le pont les range sous leur seul nom (images/a.png → a.png).
  const images = new Map();
  let imageBytes = 0;
  blocks.forEach((block, i) => {
    for (const src of relativeImages(block)) {
      const where = `${name} page ${i + 1}`;
      const relative = localPath(src);
      const base = relative.split(/[\\/]/).pop();
      if (!LESSON_IMAGE.test(base)) {
        err(where, `image « ${src} » : nom refusé par le pont (lettres, chiffres, point, tiret, tiret bas ; png, jpg, jpeg, gif ou svg)`);
        continue;
      }
      if (!exists(relative)) continue; // déjà signalée introuvable
      const seen = images.get(base);
      if (seen !== undefined) {
        if (path.normalize(seen) !== path.normalize(relative)) err(where, `images « ${seen} » et « ${relative} » de même nom : le pont range les images d'une leçon sous leur seul nom, renommer l'une des deux`);
        continue;
      }
      images.set(base, relative);
      const size = fs.statSync(file(relative)).size;
      imageBytes += size;
      if (size > 2 * MO) err(where, `image « ${src} » de ${mo(size)} : 2 Mo au plus par image (réduire ou compresser)`);
    }
  });
  if (images.size > 40 || imageBytes > 4 * MO) err(name, `${images.size} image(s), ${mo(imageBytes)} : le pont en reçoit 40 et 4 Mo au plus par leçon`);
  else if (images.size) info(name, `${images.size} image(s) envoyée(s) avec la leçon (${mo(imageBytes)})`);
  const total = sizes.reduce((a, b) => a + b, 0);
  info(name, `${blocks.length} page(s), ${total} caractères (${sizes.join(' / ')})`);
  if (blocks.length === 1 && total > 12000) info(name, 'page unique de plus de 12 000 caractères : envisager un découpage par notion');
  if (blocks.length > 1 && total < 6000) info(name, 'leçon courte sur plusieurs pages : une seule page suffirait sans doute');
  if (!/class="objectif"/.test(blocks[0] ?? '')) warn(`${name} page 1`, 'pas d\'encadré objectif');
  // Captures d'écran suggérées : le nom de la leçon l'annonce tant qu'il en reste
  const captures = blocks.reduce((n, b) => n + (b.match(/class="capture"/g) ?? []).length, 0);
  if (owner?.role === 'lecon') {
    const todo = TODO_CAPTURES.test(owner.name ?? '');
    if (captures && !todo) warn(name, `${captures} capture(s) suggérée(s) : terminer le nom de la leçon par « (TODO : Captures) » dans le manifeste`);
    else if (!captures && todo) warn(name, 'nom « (TODO : Captures) » sans capture suggérée dans la leçon : retirer la mention du nom');
  }
  if (captures) info(name, `${captures} capture(s) d'écran suggérée(s), à faire par l'enseignant`);
}

// Hors leçon (page, étiquette, consigne de TP), le pont n'envoie pas les images citées en relatif.
function checkUnsentImages(where, html) {
  const unsent = relativeImages(html);
  if (unsent.length) warn(where, `image(s) en relatif non envoyée(s) par le pont (seules celles d'une leçon le sont), à déposer à la main dans l'éditeur après le déploiement : ${unsent.join(', ')}`);
}

for (const a of activities.filter((x) => HTML_ROLES.includes(x.role) && exists(x.file))) {
  if (a.role === 'lecon') checkLesson(a.file, a);
  else {
    checkHtmlFragment(a.file, read(a.file), a);
    checkUnsentImages(a.file, read(a.file));
  }
}
if (manifest?.section?.summary_file) {
  if (exists(manifest.section.summary_file)) checkCmLinks(manifest.section.summary_file, read(manifest.section.summary_file), null);
  else err(manifestName, `section.summary_file « ${manifest.section.summary_file} » introuvable`);
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
  checkHtmlFragment(p('consigne.html'), consigne, a);
  checkUnsentImages(p('consigne.html'), consigne);
  if (a.supervised) {
    const texte = textOf(consigne);
    const duree = texte.match(/(dur[ée]e?s?|dure|temps)[^.]{0,40}\d+\s*(min\b|minutes|h\b|heures?)|\ben\s+\d+\s*(minutes|min\b|heures?)/i);
    if (duree) {
      warn(p('consigne.html'), `durée évoquée : « ${duree[0]} » ; si c'est la durée du TP, la retirer (les durées d'un TP évalué sont indicatives, dans la fiche enseignant seulement)`);
    }
    const niveaux = (consigne.match(/<strong>\s*Niveau\s+\d/gi) ?? []).length;
    if (niveaux < 3) warn(p('consigne.html'), `${niveaux} niveau(x) « Niveau n » : un TP évalué en compte 3 ou 4 (le dernier est un défi), pour que les élèves rapides restent occupés`);
    if (/bonus/i.test(a.name) && !/déjà rendu/i.test(texte)) warn(p('consigne.html'), "TP bonus : la consigne doit dire qu'il se fait après avoir déjà rendu les TP évalués");
  }
  const corrige = read(p('corrige.txt'));
  checkDrawings(p('corrige.txt'), corrige);
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
  if (leak && a.supervised) {
    // TP évalué : la consigne fournit souvent une signature de fonction ou un programme de départ, qui se retrouvent dans le corrigé
    warn(p('consigne.html'), `reprend un passage du corrigé : « ${leak} » (normal pour une signature ou un programme fourni ; sinon, c'est une fuite)`);
  } else if (leak) {
    err(p('consigne.html'), `reprend un passage du corrigé : « ${leak} »`);
  }
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
  const graded = !UNGRADED_QUIZ_ROLES.includes(a.role);
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
    checkDrawings(where, q.body);
    if (q.type === 'matching' && (q.body.match(/<subquestion\b/g) ?? []).length < 3) warn(where, 'appariement de moins de 3 paires');
    // Réponse d'appariement : texte brut dans une liste ; un < ou > y serait pris pour une balise et supprimé par Moodle
    if (q.type === 'matching' && /<answer>\s*<text>[^<]*&lt;/.test(q.body)) warn(where, 'réponse d\'appariement avec « < » : Moodle la tronquerait, reformuler sans chevrons');
    // Élément d'ordre : HTML ; un chevron non échappé (CDATA « <iostream> ») serait pris pour une balise
    if (q.type === 'ordering' && /<answer[^>]*>\s*<text><!\[CDATA\[[^\]]*<(?!\/?(code|strong|em|b|i|span|sub|sup)\b)[a-z]/i.test(q.body)) {
      warn(where, 'élément d\'ordre avec un chevron non échappé (ex. <iostream>) : écrire &lt; et &gt;');
    }
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
  if (a.role === 'td') {
    checkTd(name, qs);
  } else if (a.role === 'entrainement') {
    if (qs.length < 15) warn(name, `${qs.length} questions : une banque d'entraînement en compte 15 à 25`);
    const drawAi = a.questions.draw_ai ?? 0;
    if (aiCount < drawAi) err(name, `draw_ai = ${drawAi} mais seulement ${aiCount} question(s) IA`);
    if (qs.length - aiCount < a.questions.draw) err(name, `draw = ${a.questions.draw} mais seulement ${qs.length - aiCount} question(s) hors IA`);
  } else if (a.points > 0 && Math.abs(points - a.points) > 0.01) {
    err(name, `total de ${points} points, ${a.points} attendus`);
  }
}

// TD : un cas pratique déroulé pas à pas, des textes de guidage intercalés entre les questions, 20 à 30 minutes.
// Durée estimée par élément (minutes) : lecture d'un texte de guidage, question courte, programme à écrire.
const TD_MINUTES = { description: 1.5, coderunner: 7, matching: 2.5, ordering: 2.5, numerical: 2, shortanswer: 2, multichoice: 1.5 };
function checkTd(name, qs) {
  const texts = qs.filter((q) => q.type === 'description');
  const questions = qs.filter((q) => q.type !== 'description');
  if (qs[0]?.type !== 'description') warn(name, 'le TD commence par une question : il s\'ouvre sur un texte qui pose le cas pratique');
  if (texts.length < 2) err(name, `${texts.length} texte(s) de guidage : un TD intercale des explications (questions « description ») entre les questions`);
  else if (!qs.some((q, k) => q.type === 'description' && qs.slice(0, k).some((x) => x.type !== 'description'))) {
    warn(name, 'tous les textes de guidage sont en tête : intercale-les entre les questions (expliquer, puis faire faire)');
  }
  if (questions.length < 6 || questions.length > 15) warn(name, `${questions.length} question(s) : un TD en compte 6 à 15`);
  const sansIndice = questions.filter((q) => q.type !== 'coderunner' && !/<hint\b/.test(q.body)).length;
  if (sansIndice > questions.length / 2) warn(name, `${sansIndice} question(s) sans indice (hints) : en mode interactif, l'indice guide l'essai suivant`);
  const ia = questions.filter((q) => AI_QTYPES.includes(q.type)).length;
  if (ia) warn(name, `${ia} question(s) corrigée(s) par IA dans un TD : correction différée et coûteuse, préférer des questions à correction immédiate`);
  const minutes = qs.reduce((m, q) => m + (TD_MINUTES[q.type] ?? 2), 0);
  if (minutes < 18 || minutes > 35) warn(name, `durée estimée ${Math.round(minutes)} min : un TD se fait en 20 à 30 minutes`);
  info(name, `TD : ${texts.length} texte(s) de guidage, ${questions.length} question(s), environ ${Math.round(minutes)} min`);
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
