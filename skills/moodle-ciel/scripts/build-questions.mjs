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
//                    general (feedback général, HTML)
//   multichoice    : answers: [[fraction, html, feedback], …] ; multi: true pour plusieurs bonnes
//                    réponses (fractions positives totalisant 100, négatives pour les fausses)
//   numerical      : answers: [[fraction, valeur, feedback, tolérance], …] ; « * » pour toute autre
//   matching       : pairs: [[html, réponse], …] ; html vide = réponse en trop (distracteur)
//   ordering       : items: ['…', …] dans le bon ordre
//   aishortanswer  : systemprompt, expected (RÉPONSE TYPE / PONDÉRATION / ERREURS TYPIQUES), lines
//   aiessay        : systemprompt, expected, competencies (une ligne « Cxx — Libellé : critère »), lines
//   description    : text seulement (dossier de mise en situation, sans points)
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

function render(q) {
  const grade = q.type === 'description' ? 0 : (q.grade ?? 1);
  const lines = [
    `    <name>\n      <text>${esc(q.name)}</text>\n    </name>`,
    block('questiontext', q.text),
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
        lines.push(`    <answer fraction="${fraction}" format="html">\n      ${text(html)}\n${block('feedback', feedback ?? '', '      ')}\n    </answer>`);
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
      q.items.forEach((item, i) => lines.push(`    <answer fraction="${i + 1}" format="moodle_auto_format">\n      <text>${esc(item)}</text>\n    </answer>`));
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
    default:
      throw new Error(`${q.name} : type « ${q.type} » non géré`);
  }
  lines.push(`    <tags>\n${(q.tags ?? []).map((t) => `      <tag><text>${esc(t)}</text></tag>`).join('\n')}\n    </tags>`);
  const comps = (q.tags ?? []).filter((t) => /^C\d\d$/.test(t)).join(' + ');
  const banner = `<!-- ═══════════════ ${q.name.split(' — ')[0]} — ${q.label ?? q.type} — ${grade} pt${grade > 1 ? 's' : ''}${comps ? ` — ${comps}` : ''} ═══════════════ -->`;
  return `${banner}\n  <question type="${q.type}">\n${lines.join('\n')}\n  </question>`;
}

const comment = header ? `<!--\n${header.replace(/--/g, '—')}\n-->\n` : '';
fs.writeFileSync(out, `<?xml version="1.0" encoding="UTF-8"?>\n${comment}<quiz>\n\n${questions.map(render).join('\n\n')}\n\n</quiz>\n`);
const points = questions.reduce((sum, q) => sum + (q.type === 'description' ? 0 : (q.grade ?? 1)), 0);
console.log(`${questions.length} questions, ${points} points → ${out}`);
