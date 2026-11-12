#!/usr/bin/env node
import fs from 'node:fs/promises';

// Self-contained so the installed skill does not depend on the repository's MCP package.
const file = process.argv[2];
if (!file) { console.error('Usage: node check-notebook.mjs bundle.json'); process.exit(2); }
try {
  const b = JSON.parse(await fs.readFile(file, 'utf8'));
  if (b.master?.nbformat !== 4 || !b.master.cells?.length || !b.rubric?.trim()
      || (b.profile ?? 'python') !== 'python') throw new Error('Notebook, profil ou barème invalide.');
  if (!Number.isInteger(b.tests_weight ?? 70) || (b.tests_weight ?? 70) < 0 || (b.tests_weight ?? 70) > 100) {
    throw new Error('Pondération invalide.');
  }
  const ids = new Set();
  let points = 0;
  for (const c of b.master.cells) {
    const m = c.metadata?.nbgrader ?? {};
    if (m.solution || m.grade) {
      if (!m.grade_id || ids.has(m.grade_id)) throw new Error('Identifiant de cellule manquant ou dupliqué.');
      ids.add(m.grade_id);
    }
    if (m.grade && c.cell_type === 'code') {
      if (!Number.isFinite(m.points) || m.points <= 0) throw new Error('Points invalides.');
      points += m.points;
    }
  }
  if (!points) throw new Error('Aucun test noté.');
  console.log(`Structure valide : ${b.master.cells.length} cellules, ${points} points de tests. Validation exécutée requise sur Moodle.`);
} catch (error) { console.error(error.message); process.exit(1); }
