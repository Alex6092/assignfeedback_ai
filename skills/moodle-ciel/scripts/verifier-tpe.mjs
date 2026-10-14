#!/usr/bin/env node
// Contrôle les corrigés d'un module de TP évalués (parcours de programmation C / C++) :
//   node verifier-tpe.mjs <dossier du module> [--questions td.questions.mjs]
//
// Avec --questions (fichier de questions de build-questions.mjs, relatif au dossier du module : TD, QCM, sommatif), il
// compile aussi la solution de chaque question CodeRunner et l'exécute sur ses tests, comme CodeRunner : cpp_program
// lit `stdin`, cpp_function exécute `code` dans main() après la fonction (en-têtes usuels et `using namespace std;`
// fournis) ; la sortie doit être exactement `expected` (espaces de fin de ligne ignorés). Sans tp-evalues.mjs dans le
// dossier, seules les questions sont contrôlées.
//
// Principe : un corrigé qui n'a jamais été compilé est un corrigé qui contient des erreurs ; la Correction IA s'appuie
// dessus pour noter. Pour chaque TP de <dossier>/tp-evalues.mjs, le script :
//   - compile le corrigé (g++ -std=c++17 -Wall -Wextra : aucun avertissement toléré) et le fait passer par les `tests`
//     { stdin, attendu: [sous-chaînes à retrouver dans la sortie] } ;
//   - compile le programme fourni aux élèves (`buggy`, `buggyFichiers`) : il doit compiler ET échouer à au moins un test ;
//   - vérifie les `verifs` annexes : { nom, code | fichiers, compile: true|false, tests?, avertissementsOk? }
//     (squelette à trous qui doit compiler ou non, programme qui ne compile pas fourni aux élèves et sa version réparée…).
// Champs du TP utiles : solution | fichiers ({ 'Nom.h': '…', 'Nom.cpp': '…' }), solutionCompile (code à compiler quand
// `solution` regroupe plusieurs programmes indépendants), sansCompilation (TP sans code : analyse, UML…).
//
// Modules Qt (meta.qt = true, ou qt: true sur un TP) : simple contrôle de SYNTAXE (-fsyntax-only), sans exécution ni
// édition de liens. Il faut les en-têtes Qt : variable d'environnement QT_INCLUDE (dossier « include » d'un kit Qt), sinon
// C:/Qt/5.14.2/msvc2017_64/include s'il existe. Avec un Qt 5.14, `errorOccurred` (Qt 5.15) est remplacé pour le contrôle.
// Aucune dépendance à Qt n'est nécessaire pour les autres TP ; g++ doit être dans le PATH.

import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { pathToFileURL } from 'node:url';

const args = process.argv.slice(2);
const qIndex = args.indexOf('--questions');
const questionsFile = qIndex >= 0 ? args.splice(qIndex, 2)[1] : null;
const dir = args[0];
if (!dir || (qIndex >= 0 && !questionsFile)) {
  console.error('Usage : node verifier-tpe.mjs <dossier du module> [--questions td.questions.mjs]');
  process.exit(2);
}
const tpFile = path.resolve(dir, 'tp-evalues.mjs');
const { meta = {}, tps = [] } = fs.existsSync(tpFile) ? await import(pathToFileURL(tpFile).href) : {};
if (!tps.length && !questionsFile) {
  console.error(`Pas de tp-evalues.mjs dans ${dir} : précise --questions <fichier de questions>.`);
  process.exit(2);
}
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'tpe-'));
let erreurs = 0;
const ko = (msg) => { erreurs++; console.log('  ✗ ' + msg); };

const QT_INCLUDE = [process.env.QT_INCLUDE, 'C:/Qt/5.14.2/msvc2017_64/include'].find((p) => p && fs.existsSync(p));
const gpp = spawnSync('g++', ['--version'], { encoding: 'utf8' });
if (gpp.error) { console.error('g++ introuvable dans le PATH : impossible de compiler les corrigés.'); process.exit(2); }

// `code` : une chaîne (un seul .cpp) ou un objet { 'Nom.h': '…', 'Nom.cpp': '…', 'main.cpp': '…' } (plusieurs fichiers)
function compiler(code, nom, qt) {
  const exe = path.join(tmp, nom + '.exe');
  const dossier = path.join(tmp, nom);
  fs.mkdirSync(dossier, { recursive: true });
  const fichiers = typeof code === 'string' ? { 'main.cpp': code } : code;
  const sources = [];
  // errorOccurred n'existe qu'à partir de Qt 5.15 : on le remplace pour le contrôle avec des en-têtes Qt 5.14
  const compat = (t) => t.replace(/&(?:QAbstractSocket|QTcpSocket)::errorOccurred/g, 'QOverload<QAbstractSocket::SocketError>::of(&QAbstractSocket::error)');
  for (const [f, contenu] of Object.entries(fichiers)) {
    fs.writeFileSync(path.join(dossier, f), (qt ? compat(contenu) : contenu) + '\n');
    if (f.endsWith('.cpp')) sources.push(path.join(dossier, f));
  }
  if (qt) {
    if (!QT_INCLUDE) return { ok: true, log: '', exe: null, ignore: true };
    const args = ['-fsyntax-only', '-std=c++17', '-Wall', '-Wextra', '-Wno-sign-compare', '-I', dossier];
    for (const d of ['', '/QtCore', '/QtGui', '/QtWidgets', '/QtNetwork']) args.push('-isystem', QT_INCLUDE + d);
    let ok = true, log = '';
    for (const src of sources) {
      const r = spawnSync('g++', [...args, src], { encoding: 'utf8' });
      if (r.status !== 0) ok = false;
      log += (r.stderr || '') + (r.stdout || '');
    }
    return { ok, log, exe: null };
  }
  const r = spawnSync('g++', ['-std=c++17', '-Wall', '-Wextra', '-Wno-sign-compare', '-I', dossier, '-o', exe, ...sources], { encoding: 'utf8' });
  return { ok: r.status === 0, log: (r.stderr || '') + (r.stdout || ''), exe };
}
function lancer(exe, stdin) {
  const r = spawnSync(exe, [], { input: stdin, encoding: 'utf8', timeout: 5000 });
  return (r.stdout || '').replace(/\r/g, '');
}
const reussit = (sortie, t) => t.attendu.every((a) => sortie.includes(a));

for (const tp of tps) {
  console.log(`M${meta.n}.${tp.bonus ? 'bonus' : tp.num} ${tp.titre}`);
  if (tp.sansCompilation) { console.log('  (non compilé : sansCompilation)'); continue; }
  const qtTp = tp.qt ?? meta.qt;
  const c = compiler(tp.fichiers ?? tp.solutionCompile ?? tp.solution, `m${meta.n}_${tp.num}`, qtTp);
  if (c.ignore) { console.log('  (contrôle Qt ignoré : QT_INCLUDE non défini)'); continue; }
  if (!c.ok) { ko('le corrigé ne compile pas :\n' + c.log); continue; }
  if (c.log.trim()) ko('le corrigé compile avec des avertissements :\n' + c.log);
  if (qtTp) {
    console.log('  ✓ syntaxe Qt valide (contrôle sans édition de liens)');
    if (tp.buggy || tp.buggyFichiers) {
      const b = compiler(tp.buggyFichiers ?? tp.buggy, `m${meta.n}_${tp.num}_bug`, qtTp);
      if (b.ok) console.log('  ✓ le programme à réparer est syntaxiquement valide');
      else ko('le programme à réparer ne passe pas le contrôle de syntaxe :\n' + b.log);
    }
    continue;
  }
  const tests = tp.tests ?? [];
  if (!tests.length) ko('aucun test : ajoute `tests` ou `sansCompilation: true`');
  tests.forEach((t, i) => {
    const out = lancer(c.exe, t.stdin);
    if (reussit(out, t)) console.log(`  ✓ test ${i + 1} du corrigé`);
    else ko(`test ${i + 1} du corrigé : sortie inattendue\n${out}`);
  });
  if (tp.buggy || tp.buggyFichiers) {
    const b = compiler(tp.buggyFichiers ?? tp.buggy, `m${meta.n}_${tp.num}_bug`, false);
    if (!b.ok) { ko('le programme à réparer ne compile pas :\n' + b.log); continue; }
    const echecs = tests.filter((t) => !reussit(lancer(b.exe, t.stdin), t)).length;
    if (echecs === 0) ko('le programme à réparer passe tous les tests : aucun défaut visible');
    else console.log(`  ✓ le programme à réparer échoue à ${echecs}/${tests.length} test(s)`);
  }
}
for (const tp of tps) {
  (tp.verifs ?? []).forEach((v, i) => {
    const r = compiler(v.fichiers ?? v.code, `m${meta.n}_${tp.num}_v${i}`, v.qt ?? tp.qt ?? meta.qt);
    if (r.ignore) return;
    if (r.ok !== v.compile) { ko(`${v.nom} : ${v.compile ? 'devrait compiler' : 'devrait échouer à la compilation'}\n${r.log}`); return; }
    console.log(`  ✓ ${v.nom} : ${v.compile ? 'compile' : 'ne compile pas (voulu)'}`);
    if (v.compile && r.log.trim() && !v.avertissementsOk) ko(`${v.nom} : avertissements\n${r.log}`);
    (v.tests ?? []).forEach((t, j) => {
      const out = lancer(r.exe, t.stdin ?? '');
      if (reussit(out, t)) console.log(`  ✓ ${v.nom} : test ${j + 1}`);
      else ko(`${v.nom} : test ${j + 1}, sortie inattendue\n${out}`);
    });
  });
}
// Questions CodeRunner : la solution de référence doit passer tous ses tests, sinon CodeRunner refuse les bonnes
// réponses des élèves (ou en accepte de fausses).
if (questionsFile) {
  const { questions } = await import(pathToFileURL(path.resolve(dir, questionsFile)).href);
  const coderunner = questions.filter((q) => q.type === 'coderunner');
  console.log(`\n${questionsFile} : ${coderunner.length} question(s) CodeRunner`);
  const propre = (s) => String(s ?? '').replace(/\r/g, '').split('\n').map((l) => l.trimEnd()).join('\n').trimEnd();
  const ENTETES = ['iostream', 'fstream', 'string', 'cmath', 'vector', 'algorithm'].map((h) => `#include <${h}>`).join('\n');
  coderunner.forEach((q, i) => {
    const nom = q.name ?? q.id ?? `question ${i + 1}`;
    console.log(nom);
    if (!q.tests?.length) { ko('aucun test'); return; }
    let exe = null;
    if (q.crtype === 'cpp_program') {
      const c = compiler(q.solution, `q${i}`, false);
      if (!c.ok) { ko('la solution ne compile pas :\n' + c.log); return; }
      if (c.log.trim()) ko('la solution compile avec des avertissements :\n' + c.log);
      exe = c.exe;
    } else if (q.crtype !== 'cpp_function') {
      ko(`type CodeRunner « ${q.crtype} » non contrôlé (cpp_program ou cpp_function)`);
      return;
    }
    q.tests.forEach((t, j) => {
      let bin = exe;
      if (q.crtype === 'cpp_function') {
        const programme = `${ENTETES}\nusing namespace std;\n\n${q.solution}\n\nint main() {\n    {\n        ${t.code ?? ''};\n    }\n    return 0;\n}`;
        const c = compiler(programme, `q${i}_t${j}`, false);
        if (!c.ok) { ko(`test ${j + 1} : la solution et le code du test ne compilent pas :\n` + c.log); return; }
        if (c.log.trim()) ko(`test ${j + 1} : avertissements\n` + c.log);
        bin = c.exe;
      }
      const sortie = lancer(bin, t.stdin ?? '');
      if (propre(sortie) === propre(t.expected)) console.log(`  ✓ test ${j + 1}`);
      else ko(`test ${j + 1} : sortie obtenue\n${sortie}\n  attendue\n${t.expected}`);
    });
  });
}

fs.rmSync(tmp, { recursive: true, force: true });
console.log(erreurs ? `\n${erreurs} problème(s)` : '\nTout est conforme.');
process.exit(erreurs ? 1 : 0);
