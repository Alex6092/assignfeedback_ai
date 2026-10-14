#!/usr/bin/env node
// Génère un fichier Moodle XML (banque d'entraînement, test sommatif, évaluation finale) à partir de
// questions décrites dans un module JavaScript. Node >= 18, aucune dépendance.
//
//   node build-questions.mjs <questions.mjs> <sortie.xml>
//
// Le module exporte `header` (commentaire placé en tête du XML) et `questions`, une liste d'objets :
//
//   champs communs : type, id (idnumber), name (« T5-Q1 — Titre »), label (type lisible, pour le
//                    bandeau), grade (points, 1 par défaut), tags (['IA-T5', 'C08']), text (HTML),
//                    general (feedback général, HTML), hints (indices, HTML : un par essai supplémentaire en mode
//                    interactif ; surtout pour le TD), files ({ 'nom.png': 'chemin relatif au fichier de questions' } :
//                    images embarquées, à appeler par <img src="@@PLUGINFILE@@/nom.png" alt="…"> dans text ou dans
//                    le html d'une réponse de multichoice, par exemple un diagramme PlantUML exporté en PNG ; chaque
//                    image part avec le texte qui l'appelle)
//   multichoice    : answers: [[fraction, html, feedback], …] ; multi: true pour plusieurs bonnes
//                    réponses (fractions positives totalisant 100, négatives pour les fausses)
//   numerical      : answers: [[fraction, valeur, feedback, tolérance], …] ; « * » pour toute autre
//   shortanswer    : answers: [[fraction, texte, feedback], …] (« * » : toute autre réponse) ; usecase: true pour
//                    respecter la casse
//   matching       : pairs: [[html, réponse], …] ; html vide = réponse en trop (distracteur) ; la réponse est du
//                    texte brut affiché dans une liste : pas de < ni de > (Moodle les prendrait pour des balises)
//   ordering       : items: ['…', …] dans le bon ordre, en HTML : échapper < > & (&lt;iostream&gt;), <code> admis
//   aishortanswer  : systemprompt, expected (RÉPONSE TYPE / PONDÉRATION / ERREURS TYPIQUES), lines
//   aiessay        : systemprompt, expected, competencies (une ligne « Cxx — Libellé : critère »), lines
//   description    : text seulement (dossier de mise en situation, sans points)
//   coderunner     : crtype ('cpp_function' | 'cpp_program'), solution
//                    (code de référence), preload (squelette proposé à l'élève), lines (hauteur de la
//                    zone de saisie), tests: [{ code, stdin, expected, example (true = montré dans
//                    l'énoncé), mark }] ; code = instruction exécutée dans main() pour cpp_function,
//                    stdin = entrée standard pour cpp_program
//
// Pas de <category> : le déploiement importe dans la catégorie du manifeste. Pénalité toujours 0.

import fs from 'node:fs';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const [src, out] = process.argv.slice(2);
if (!src || !out) {
  console.error('Usage : node build-questions.mjs <questions.mjs> <sortie.xml>');
  process.exit(2);
}
const { header = '', questions } = await import(pathToFileURL(path.resolve(src)).href);

const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
const cdata = (html) => `<![CDATA[${String(html).replace(/]]>/g, ']]]]><![CDATA[>')}]]>`;
const text = (html) => (html ? `<text>${cdata(html)}</text>` : '<text></text>');
const num = (n) => Number(n).toFixed(7);
const block = (tag, html, indent = '    ') => `${indent}<${tag} format="html">\n${indent}  ${text(html)}\n${indent}</${tag}>`;
const ai = ['apiurl', 'apiurl_override', 'model', 'model_override', 'apikey', 'apikey_override']
  .map((k) => `    <${k}>${k.endsWith('_override') ? 0 : ''}</${k}>`);

// Images embarquées (base64), servies par Moodle sous @@PLUGINFILE@@/nom : chacune part avec le texte qui l'appelle.
const calls = (html, name) => String(html ?? '').includes(`@@PLUGINFILE@@/${name}`);
function embedded(q, html) {
  return Object.entries(q.files ?? {}).filter(([name]) => calls(html, name)).map(([name, rel]) => {
    const data = fs.readFileSync(path.resolve(path.dirname(path.resolve(src)), rel)).toString('base64');
    return `      <file name="${esc(name)}" path="/" encoding="base64">${data}</file>`;
  });
}
function checkFiles(q) {
  const answers = q.type === 'multichoice' ? q.answers.map((a) => a[1]) : [];
  for (const name of Object.keys(q.files ?? {})) {
    if (!calls(q.text, name) && !answers.some((html) => calls(html, name))) {
      throw new Error(`${q.name} : l'image ${name} n'est appelée ni dans text ni dans une réponse (@@PLUGINFILE@@/${name})`);
    }
  }
}

// Énoncé avec ses images
function questionText(q) {
  checkFiles(q);
  const files = embedded(q, q.text);
  return files.length
    ? `    <questiontext format="html">\n      ${text(q.text)}\n${files.join('\n')}\n    </questiontext>`
    : block('questiontext', q.text);
}

function render(q) {
  const grade = q.type === 'description' ? 0 : (q.grade ?? 1);
  const lines = [
    `    <name>\n      <text>${esc(q.name)}</text>\n    </name>`,
    questionText(q),
    block('generalfeedback', q.general ?? ''),
    `    <defaultgrade>${num(grade)}</defaultgrade>`,
    `    <penalty>${num(0)}</penalty>`,
    '    <hidden>0</hidden>',
    `    <idnumber>${esc(q.id ?? '')}</idnumber>`,
  ];
  switch (q.type) {
    case 'description':
      break;
    case 'multichoice':
      lines.push(`    <single>${!q.multi}</single>`, '    <shuffleanswers>true</shuffleanswers>',
        '    <answernumbering>abc</answernumbering>', '    <showstandardinstruction>1</showstandardinstruction>',
        block('correctfeedback', ''), block('partiallycorrectfeedback', ''), block('incorrectfeedback', ''));
      if (q.multi) lines.push('    <shownumcorrect/>');
      for (const [fraction, html, feedback] of q.answers) {
        const files = embedded(q, html);
        lines.push(`    <answer fraction="${fraction}" format="html">\n      ${text(html)}\n${files.length ? `${files.join('\n')}\n` : ''}`
          + `${block('feedback', feedback ?? '', '      ')}\n    </answer>`);
      }
      break;
    case 'shortanswer':
      lines.push(`    <usecase>${q.usecase ? 1 : 0}</usecase>`);
      for (const [fraction, value, feedback] of q.answers) {
        lines.push(`    <answer fraction="${fraction}" format="moodle_auto_format">\n      <text>${esc(value)}</text>\n${block('feedback', feedback ?? '', '      ')}\n    </answer>`);
      }
      break;
    case 'numerical':
      for (const [fraction, value, feedback, tolerance] of q.answers) {
        lines.push(`    <answer fraction="${fraction}" format="moodle_auto_format">\n      <text>${esc(value)}</text>\n${block('feedback', feedback ?? '', '      ')}\n      <tolerance>${tolerance ?? 0}</tolerance>\n    </answer>`);
      }
      lines.push('    <unitgradingtype>0</unitgradingtype>', '    <unitpenalty>0.1000000</unitpenalty>',
        '    <showunits>3</showunits>', '    <unitsleft>0</unitsleft>');
      break;
    case 'matching':
      lines.push('    <shuffleanswers>true</shuffleanswers>',
        block('correctfeedback', '<p>Toutes les associations sont justes.</p>'),
        block('partiallycorrectfeedback', '<p>Une partie des associations est juste.</p>'),
        block('incorrectfeedback', '<p>Aucune association n\'est juste.</p>'), '    <shownumcorrect/>');
      for (const [html, answer] of q.pairs) {
        lines.push(`    <subquestion format="html">\n      ${text(html)}\n      <answer>\n        <text>${esc(answer)}</text>\n      </answer>\n    </subquestion>`);
      }
      break;
    case 'ordering':
      lines.push('    <layouttype>VERTICAL</layouttype>', '    <selecttype>ALL</selecttype>', `    <selectcount>${q.items.length}</selectcount>`,
        '    <gradingtype>RELATIVE_NEXT_EXCLUDE_LAST</gradingtype>', '    <showgrading>SHOW</showgrading>', '    <numberingstyle>none</numberingstyle>',
        block('correctfeedback', '<p>L\'ordre est juste.</p>'), block('partiallycorrectfeedback', '<p>Une partie de l\'ordre est juste.</p>'),
        block('incorrectfeedback', '<p>L\'ordre est faux.</p>'), '    <shownumcorrect>1</shownumcorrect>');
      q.items.forEach((item, i) => lines.push(`    <answer fraction="${i + 1}" format="html">\n      ${text(item)}\n    </answer>`));
      break;
    case 'aishortanswer':
      lines.push(`    <responsefieldlines>${q.lines ?? 4}</responsefieldlines>`,
        `    <systemprompt>${cdata(q.systemprompt)}</systemprompt>`, `    <expectedanswer>${cdata(q.expected)}</expectedanswer>`, ...ai);
      break;
    case 'aiessay':
      lines.push('    <responseformat>editor</responseformat>', '    <responserequired>1</responserequired>',
        `    <responsefieldlines>${q.lines ?? 15}</responsefieldlines>`, '    <minwordlimit></minwordlimit>', '    <maxwordlimit></maxwordlimit>',
        '    <attachments>0</attachments>', '    <attachmentsrequired>0</attachmentsrequired>', '    <filetypeslist></filetypeslist>',
        `    <systemprompt>${cdata(q.systemprompt)}</systemprompt>`, `    <expectedanswer>${cdata(q.expected)}</expectedanswer>`,
        `    <competencies>${cdata(q.competencies)}</competencies>`, ...ai,
        '    <vision_enabled>0</vision_enabled>', '    <vision_enabled_override>0</vision_enabled_override>');
      break;
    case 'coderunner': {
      // Champs relevés par export de la question IA-T2-Q6 (cours IA-01), type python3 remplacé par C++.
      const tc = (t) => `      <testcase testtype="0" useasexample="${t.example ? 1 : 0}" hiderestiffail="0" mark="${num(t.mark ?? 1)}" >
      <testcode>
                <text>${esc(t.code ?? '')}</text>
      </testcode>
      <stdin>
                <text>${esc(t.stdin ?? '')}</text>
      </stdin>
      <expected>
                <text>${esc(t.expected)}</text>
      </expected>
      <extra>
                <text></text>
      </extra>
      <display>
                <text>SHOW</text>
      </display>
    </testcase>`;
      lines.push(
        `    <coderunnertype>${esc(q.crtype)}</coderunnertype>`, '    <prototypetype>0</prototypetype>', '    <allornothing>0</allornothing>',
        '    <penaltyregime>0, 10, 20, ...</penaltyregime>', '    <precheck>2</precheck>', '    <hidecheck>0</hidecheck>', '    <showsource>0</showsource>',
        `    <answerboxlines>${q.lines ?? 12}</answerboxlines>`, '    <answerboxcolumns>100</answerboxcolumns>',
        `    <answerpreload>${esc(q.preload ?? '')}</answerpreload>`, '    <globalextra></globalextra>', '    <useace></useace>',
        '    <resultcolumns></resultcolumns>', '    <template></template>', '    <iscombinatortemplate></iscombinatortemplate>',
        '    <allowmultiplestdins></allowmultiplestdins>', `    <answer>${cdata(q.solution)}</answer>`, '    <validateonsave>1</validateonsave>',
        '    <testsplitterre></testsplitterre>', '    <language></language>', '    <acelang></acelang>', '    <sandbox></sandbox>', '    <grader></grader>',
        '    <cputimelimitsecs></cputimelimitsecs>', '    <memlimitmb></memlimitmb>', '    <sandboxparams></sandboxparams>', '    <templateparams></templateparams>',
        '    <hoisttemplateparams>1</hoisttemplateparams>', '    <extractcodefromjson>1</extractcodefromjson>', '    <templateparamslang>twig</templateparamslang>',
        '    <templateparamsevalpertry>0</templateparamsevalpertry>', '    <templateparamsevald></templateparamsevald>', '    <twigall>0</twigall>',
        '    <uiplugin></uiplugin>', '    <uiparameters></uiparameters>', '    <attachments>0</attachments>', '    <attachmentsrequired>0</attachmentsrequired>',
        '    <maxfilesize>10240</maxfilesize>', '    <filenamesregex></filenamesregex>', '    <filenamesexplain></filenamesexplain>',
        '    <displayfeedback>1</displayfeedback>', '    <giveupallowed>0</giveupallowed>', '    <prototypeextra></prototypeextra>',
        `    <testcases>\n${q.tests.map(tc).join('\n')}\n    </testcases>`);
      break;
    }
    default:
      throw new Error(`${q.name} : type « ${q.type} » non géré`);
  }
  for (const hint of q.hints ?? []) lines.push(block('hint', hint));
  lines.push(`    <tags>\n${(q.tags ?? []).map((t) => `      <tag><text>${esc(t)}</text></tag>`).join('\n')}\n    </tags>`);
  const comps = (q.tags ?? []).filter((t) => /^C\d\d$/.test(t)).join(' + ');
  const banner = `<!-- ═══════════════ ${q.name.split(' — ')[0]} — ${q.label ?? q.type} — ${grade} pt${grade > 1 ? 's' : ''}${comps ? ` — ${comps}` : ''} ═══════════════ -->`;
  return `${banner}\n  <question type="${q.type}">\n${lines.join('\n')}\n  </question>`;
}

const comment = header ? `<!--\n${header.replace(/--/g, '—')}\n-->\n` : '';
fs.writeFileSync(out, `<?xml version="1.0" encoding="UTF-8"?>\n${comment}<quiz>\n\n${questions.map(render).join('\n\n')}\n\n</quiz>\n`);
const points = questions.reduce((sum, q) => sum + (q.type === 'description' ? 0 : (q.grade ?? 1)), 0);
console.log(`${questions.length} questions, ${points} points → ${out}`);
