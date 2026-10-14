#!/usr/bin/env node
// Génère, pour un module, les fichiers des « TP évalués en classe » et du « TP bonus » à partir de
// <dossier du module>/tp-evalues.mjs :
//   tpe<k>/ et tpb/        consigne.html, enonce.txt, corrige.txt, competences.txt  (un dossier par TP)
//   tp-evalues.deploy.json manifeste des TP évalués puis du TP bonus : devoirs surveillés (code de séance, sans mode
//                          examen), report EFE actif, bonus réservé à ceux qui ont rendu les TP évalués (requires)
//   tp-bonus.deploy.json   (avec --bonus-seul) manifeste du seul TP bonus, quand les TP évalués existent déjà
//   fiche-tp-evalues.html  fiche enseignant : sujets, corrigés, barèmes, erreurs typiques, vigilance
//
//   node make-tpe.mjs <dossier du module> [--dans-module] [--avant <cmid>] [--bonus-seul <cmid,cmid>]
//
//   --dans-module           module neuf : place aussi les TP évalués et le bonus dans module.deploy.json, juste avant
//                           le test sommatif (en remplaçant les activités tpe*/tpb d'une génération précédente) ;
//                           tout le module se déploie alors d'un seul manifeste, dans l'ordre
//   --avant <cmid>          module déjà déployé : crée les TP avant cette activité de la section (le test sommatif)
//                           au lieu de les ajouter en fin de section
//   --bonus-seul <cmids>    cmid des TP évalués déjà créés : seul le bonus est à (re)créer, avec ces activités à achever
//
// Le fichier de données exporte :
//   meta = {
//     n,                          numéro du module (Mx)
//     section, sectionName,       numéro et nom de la section Moodle (sinon repris de module.deploy.json)
//     titre,                      titre du module (fiche)
//     course?, prefix?,           { id, teacher_id } et préfixe des idnumbers (sinon repris de module.deploy.json)
//     filetypes?, maxfiles?,      remise de fichiers (« .cpp,.h,.png,.jpg,.jpeg,.pdf ») ; sans filetypes : texte en ligne
//     onlinetext?,                true : remise en texte en ligne
//     vision?,                    true : la Correction IA lit les images rendues (diagramme exporté en PNG, image
//                                 collée dans le texte en ligne) ; à mettre dès qu'un TP fait rendre un diagramme
//     qt?                         contrôle de syntaxe Qt (voir verifier-tpe.mjs)
//   }
//   tps = [{
//     num, titre,                 numéro (1, 2…) et titre ; bonus: true pour le TP bonus (num libre)
//     code, codeLibelle,          code EFE N2/N3 et son libellé
//     duree,                      durée INDICATIVE en minutes (fiche enseignant seulement, jamais dans la consigne)
//     competences,                « Cxx — Libellé » (champ « compétences évaluées » de la Correction IA)
//     consigne,                   HTML vu par l'élève (niveaux progressifs, dernier niveau = défi)
//     solution | fichiers,        corrigé (texte) ou { 'Nom.h': '…', 'Nom.cpp': '…' } ; solutionCompile? (voir verifier-tpe)
//     bareme: [{ titre, pts, items: [[texte, pts], …] }],   total = 100, chaque bloc = somme de ses lignes
//     erreurs: [texte, …], vigilance?: [html, …],
//     vision?,                    comme meta.vision, pour ce TP seulement
//     tests?, buggy?, buggyFichiers?, verifs?, sansCompilation?, qt?    (contrôles de verifier-tpe.mjs)
//   }]
//
// Un diagramme attendu en corrigé s'écrit en PlantUML dans fichiers (« 'uml.puml': `@startuml …` ») : la Correction
// IA le lit comme du texte et l'élève, lui, rend une image ; jamais de dessin en caractères (+---+, ┌─┐).

import fs from 'node:fs';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const args = process.argv.slice(2);
const option = (nom) => { const i = args.indexOf(nom); return i >= 0 ? args.splice(i, 2)[1] : undefined; };
const dansModule = args.includes('--dans-module') && !!args.splice(args.indexOf('--dans-module'), 1);
const avant = option('--avant');
const bonusSeul = option('--bonus-seul');
const dir = args[0];
if (!dir || (avant !== undefined && !/^\d+$/.test(avant)) || (bonusSeul !== undefined && !/^\d+(,\d+)*$/.test(bonusSeul))) {
  console.error('Usage : node make-tpe.mjs <dossier du module> [--avant <cmid>] [--bonus-seul <cmid,cmid>]');
  process.exit(2);
}
const { meta, tps } = await import(pathToFileURL(path.resolve(dir, 'tp-evalues.mjs')).href);

// Valeurs communes reprises du manifeste du module quand il existe (cours cible, préfixe, section)
let base = {};
try { base = JSON.parse(fs.readFileSync(path.join(dir, 'module.deploy.json'), 'utf8')); } catch { /* pas de manifeste de module */ }
const course = meta.course ?? base.course ?? {};
const prefix = meta.prefix ?? base.prefix ?? '';
const section = { number: meta.section ?? base.section?.number, name: meta.sectionName ?? base.section?.name };
if (!Number.isInteger(section.number) || !section.name) { console.error('Section inconnue : renseigne meta.section et meta.sectionName.'); process.exit(2); }

const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
const unesc = (s) => s.replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&amp;/g, '&');
function htmlToText(h) {
  return unesc(h
    .replace(/<pre[^>]*><code[^>]*>/g, '\n').replace(/<\/code><\/pre>/g, '\n')
    .replace(/<li[^>]*>/g, '\n • ').replace(/<\/(p|div|ul|ol|h[1-4])>/g, '\n')
    .replace(/<br\s*\/?>/g, '\n').replace(/<[^>]+>/g, ''))
    .replace(/[ \t]+\n/g, '\n').replace(/\n\n( • )/g, '\n$1').replace(/\n{3,}/g, '\n\n').trim();
}

const nomTp = (tp) => (tp.bonus ? `TP bonus M${meta.n} - ${tp.titre}` : `TP évalué M${meta.n}.${tp.num} - ${tp.titre}`);
const solutionTexte = (tp) => (tp.fichiers
  ? Object.entries(tp.fichiers).map(([f, c]) => `// ===== ${f} =====\n${c.trim()}`).join('\n\n')
  : tp.solution);
const cleTp = (tp) => (tp.bonus ? 'tpb' : `tpe${tp.num}`);
const submission = (meta.onlinetext || !meta.filetypes)
  ? { type: 'onlinetext' }
  : { type: 'file', filetypes: meta.filetypes, maxfiles: meta.maxfiles ?? 1 };
const acts = [];

for (const tp of tps) {
  const sub = path.join(dir, cleTp(tp));
  fs.mkdirSync(sub, { recursive: true });
  const total = tp.bareme.reduce((s, b) => s + b.pts, 0);
  if (total !== 100) throw new Error(`${nomTp(tp)} : barème = ${total} points (attendu 100)`);
  for (const b of tp.bareme) {
    const s = b.items.reduce((x, i) => x + i[1], 0);
    if (s !== b.pts) throw new Error(`${nomTp(tp)} : « ${b.titre} » vaut ${b.pts} mais ses lignes totalisent ${s}`);
  }
  fs.writeFileSync(path.join(sub, 'consigne.html'), tp.consigne.trim() + '\n');
  fs.writeFileSync(path.join(sub, 'enonce.txt'), htmlToText(tp.consigne) + '\n');
  const corrige = [
    'Corrigé type :', '', solutionTexte(tp).trim(), '',
    'Noter sur 100. Répartition :', '',
    ...tp.bareme.flatMap((b) => [`[${b.pts} pts] ${b.titre}`, ...b.items.map((i) => ` • ${i[0]} [${i[1]}]`), '']),
    'ERREURS TYPIQUES À SIGNALER :',
    ...tp.erreurs.map((e) => ` - ${e}`),
    'Consigne de feedback bienveillant : valoriser ce qui est réussi, nommer l\'erreur et la ligne concernée, expliquer posément pourquoi, sans réécrire le code à la place de l\'élève. Toute solution correcte différente du corrigé type reçoit tous les points.',
  ].join('\n');
  fs.writeFileSync(path.join(sub, 'corrige.txt'), corrige + '\n');
  fs.writeFileSync(path.join(sub, 'competences.txt'), tp.competences + '\n');

  acts.push({
    key: cleTp(tp), role: 'tp', name: nomTp(tp), dir: cleTp(tp), submission,
    // Images rendues (diagramme) : vision imposée au devoir, quel que soit le réglage du site.
    ...((tp.vision ?? meta.vision) !== undefined ? { vision: tp.vision ?? meta.vision } : {}),
    // Tout ce qui est évalué remonte à EFE, bonus compris ; sans date de rendu : la séance fermée tient lieu d'échéance.
    efe: { competences: [tp.code], after_due: false },
    // Séance surveillée avec code, sans mode examen : la leçon et le TD restent consultables pendant le TP.
    supervised: { sessioncode: true, exammode: false },
    ...(avant ? { before: Number(avant) } : {}),
    // Pas de Tuteur IA, pas de commentaires ni de fichier de retour : le devoir est noté par la Correction IA seule.
    overrides: { assignfeedback_comments_enabled: 0, assignfeedback_file_enabled: 0, aichatenabled: 0 },
  });
}

const manifest = { format: 'moodle-ciel/module@1', course, section, prefix };
const evalActs = acts.filter((a) => a.key !== 'tpb');
const bonusAct = acts.find((a) => a.key === 'tpb');
// Le bonus n'ouvre qu'à ceux qui ont rendu les TP évalués de la séance (condition d'achèvement « remise faite »).
if (bonusSeul) {
  if (!bonusAct) throw new Error('--bonus-seul : aucun TP bonus (bonus: true) dans tp-evalues.mjs');
  const requires = bonusSeul.split(',').map(Number);
  fs.writeFileSync(path.join(dir, 'tp-bonus.deploy.json'),
    JSON.stringify({ ...manifest, activities: [{ ...bonusAct, requires }] }, null, 2) + '\n');
} else {
  const activities = [...evalActs, ...(bonusAct ? [{ ...bonusAct, requires: evalActs.map((a) => a.key) }] : [])];
  fs.writeFileSync(path.join(dir, 'tp-evalues.deploy.json'), JSON.stringify({ ...manifest, activities }, null, 2) + '\n');
  if (dansModule) {
    const fichier = path.join(dir, 'module.deploy.json');
    if (!base.activities) throw new Error('--dans-module : module.deploy.json absent ou sans activités');
    const autres = base.activities.filter((a) => !/^tpe\d+$|^tpb$/.test(a.key));
    const i = autres.findIndex((a) => a.role === 'sommatif');
    const sansAvant = activities.map(({ before, ...a }) => a);
    base.activities = i >= 0 ? [...autres.slice(0, i), ...sansAvant, ...autres.slice(i)] : [...autres, ...sansAvant];
    fs.writeFileSync(fichier, JSON.stringify(base, null, 2) + '\n');
    console.log(`module.deploy.json : ${sansAvant.length} TP placés ${i >= 0 ? 'avant le test sommatif' : 'en fin de module'}`);
  }
  if (fs.existsSync(path.join(dir, 'tp-bonus.deploy.json'))) {
    console.log('Note : tp-bonus.deploy.json d\'une génération précédente est toujours là ; le bonus est maintenant dans tp-evalues.deploy.json.');
  }
}

// ---------- fiche enseignant ----------
const remise = meta.onlinetext || !meta.filetypes ? 'texte saisi en ligne' : `<code>${meta.filetypes}</code>`;
const blocs = tps.map((tp) => {
  const lignes = tp.bareme.map((b) => `  <tr><td><strong>${esc(b.titre)}</strong></td><td>${b.pts}</td><td><ul>${b.items.map((i) => `<li>${esc(i[0])} (${i[1]})</li>`).join('')}</ul></td></tr>`).join('\n');
  return `<h2>${esc(nomTp(tp))}${tp.bonus ? ' <small>(bonus, pour ceux qui sont en avance)</small>' : ''}</h2>
<p><strong>Code EFE :</strong> ${tp.code} (${esc(tp.codeLibelle)}) · <strong>Durée indicative :</strong> environ ${tp.duree} min (non communiquée aux élèves) · <strong>Remise :</strong> ${remise}</p>
<h3>Sujet vu par l'élève</h3>
<div class="sujet">${tp.consigne}</div>
<h3>Corrigé type</h3>
<pre><code>${esc(solutionTexte(tp).trim())}</code></pre>
<h3>Barème (sur 100, repris dans le champ « Corrigé attendu » de la Correction IA)</h3>
<table>
  <tr><th>Bloc</th><th>Pts</th><th>Détail</th></tr>
${lignes}
</table>
<h3>Erreurs typiques</h3>
<ul>
${tp.erreurs.map((e) => `  <li>${esc(e)}</li>`).join('\n')}
</ul>
${(tp.vigilance ?? []).length ? `<h3>Points de vigilance</h3>\n<ul>\n${tp.vigilance.map((v) => `  <li>${v}</li>`).join('\n')}\n</ul>` : ''}`;
}).join('\n<hr>\n');

const principaux = tps.filter((t) => !t.bonus);
const bonus = tps.filter((t) => t.bonus);
const duree = principaux.reduce((s, t) => s + t.duree, 0);
const html = `<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>M${meta.n} — TP évalués en classe : fiche enseignant</title>
  <style>
    body { margin: 2rem auto; max-width: 900px; padding: 0 1.2rem; font-family: system-ui, "Segoe UI", Arial, sans-serif; line-height: 1.6; color: #1d2433; }
    h1 { color: #0b7a3b; font-size: 1.7rem; }
    h2 { border-bottom: 2px solid #e2e8f0; padding-bottom: .2rem; margin-top: 2rem; font-size: 1.2rem; }
    h3 { font-size: 1rem; margin-bottom: .3rem; }
    .intro-fiche { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: .8rem 1rem; }
    .sujet { border: 1px solid #e2e8f0; border-left: 4px solid #0284c7; border-radius: 8px; padding: .4rem 1rem; background: #fff; }
    .sujet .cdc, .sujet .phase, .sujet .rendre, .sujet .danger { padding: .5rem .8rem; margin: .6rem 0; border-radius: 6px; background: #f1f5f9; }
    .sujet .rendre { background: #f0fdf4; } .sujet .danger { background: #fef2f2; }
    table { width: 100%; border-collapse: collapse; margin: 1rem 0; }
    th, td { border: 1px solid #e2e8f0; padding: .45rem .7rem; text-align: left; vertical-align: top; }
    th { background: #f1f5f9; } td ul { margin: 0; padding-left: 1.1rem; }
    code { background: #f1f5f9; padding: .1rem .3rem; border-radius: 4px; font-family: "Cascadia Code", monospace; font-size: .92em; }
    pre { background: #0f172a; color: #e2e8f0; padding: .8rem 1rem; border-radius: 8px; overflow: auto; white-space: pre-wrap; font-size: .88em; }
    pre code { background: none; color: inherit; padding: 0; }
    @media print { body { margin: 0; max-width: none; } }
  </style>
</head>
<body>
<!-- Fiche réservée à l'enseignant : jamais déployée dans Moodle. Générée par scripts/make-tpe.mjs. -->
<h1>📋 M${meta.n} — ${esc(meta.titre ?? section.name)} : TP évalués en classe</h1>
<div class="intro-fiche">
  <strong>${principaux.length} TP évalué${principaux.length > 1 ? 's' : ''}, ${duree} min au total${bonus.length ? `, plus ${bonus.length} TP bonus (${bonus.reduce((s, t) => s + t.duree, 0)} min)` : ''}</strong> (durées indicatives, jamais affichées aux élèves ; la durée réelle est celle de la séance surveillée que tu ouvres).
  Devoirs <strong>fermés avant la séance</strong> : condition « Activité surveillée » avec code de séance, <strong>sans mode examen</strong> (la leçon et le TD restent consultables), sans date prévue. Sujet en niveaux progressifs (le dernier est un défi) pour que les plus rapides restent occupés. ${meta.onlinetext || !meta.filetypes ? 'Réponse saisie en ligne' : "Remise d'un fichier"}, notation par la Correction IA sur 100 (échelle « Barème officiel »), <strong>pas de Tuteur IA</strong>, 2 tentatives (une seconde se rouvre à la main si un élève envoie trop tôt).
${bonus.length ? '  Le <strong>TP bonus</strong> est réservé aux élèves en avance : il ne s\'ouvre qu\'à ceux qui ont <strong>rendu les TP évalués</strong> du module (condition d\'accès « achèvement »), il s\'ajoute à eux sans les remplacer et il est noté lui aussi par la Correction IA.\n' : ''}  EFE : <strong>report activé</strong> sur ${bonus.length ? 'les TP évalués et le TP bonus' : 'les TP évalués'} (code N3 de chaque TP), sans date de rendu. Note directe par la Correction IA.
  Fichiers : <code>tp-evalues.mjs</code> (source), <code>tpe*/</code> et <code>tpb/</code> (consigne, énoncé, corrigé, compétences), <code>tp-evalues.deploy.json</code> (manifeste).
</div>
${blocs}
<h2>Préparation de la séance</h2>
<ul>
  <li>Publier le devoir (il reste inaccessible aux élèves tant que la séance surveillée n'est pas ouverte), poser le code de séance, puis ouvrir la séance au moment voulu pour la durée voulue.</li>
  <li>Les sujets ne doivent pas circuler avant la séance : ne les copie ni dans la leçon ni dans une annonce ; l'énoncé n'apparaît que dans l'activité.</li>
  <li>Sans mode examen, le reste du cours reste ouvert (leçon, TD : c'est voulu) ; Moodle ne ferme ni Internet ni l'IA du poste : la surveillance reste physique.</li>
  <li>Après la séance : laisser tourner le cron de la Correction IA, relire les copies douteuses (la note est appliquée directement), puis laisser l'activité fermée.</li>
${bonus.length ? '  <li>TP bonus : ouvre-le dans la même séance que les TP évalués ; il ne devient accessible à un élève qu\'une fois ses TP évalués rendus. Ceux qui n\'ont pas le temps ne perdent rien ; ceux qui le font ont une note de plus.</li>\n' : ''}  <li>Les élèves absents passent le TP à une autre séance ouverte par toi ; le sujet est alors déjà connu d'une partie de la classe : prévoir une variante pour eux si besoin.</li>
</ul>
</body>
</html>
`;
fs.writeFileSync(path.join(dir, 'fiche-tp-evalues.html'), html);
console.log(`TP évalués M${meta.n} → ${dir} (${tps.length} TP, manifestes + fiche)`);
