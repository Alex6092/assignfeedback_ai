import fs from 'node:fs/promises';
import path from 'node:path';

/** Portable bundle; all auxiliary files are explicitly supplied as base64. */
export function validateNotebookBundle(bundle) {
  if (!bundle || bundle.master?.nbformat !== 4 || !Array.isArray(bundle.master.cells)
      || !bundle.master.cells.length || bundle.master.cells.length > 300) {
    throw new Error('Notebook nbformat 4 requis (1 à 300 cellules).');
  }
  if (typeof bundle.rubric !== 'string' || !bundle.rubric.trim()) throw new Error('Barème IA requis.');
  const weight = bundle.tests_weight ?? 70;
  if (!Number.isInteger(weight) || weight < 0 || weight > 100) throw new Error('Pondération invalide.');
  if ((bundle.profile ?? 'python') !== 'python') throw new Error('Seul Python est disponible.');
  const ids = new Set();
  let points = 0;
  for (const cell of bundle.master.cells) {
    const meta = cell.metadata?.nbgrader ?? {};
    if (meta.grade || meta.solution) {
      if (!/^[a-zA-Z0-9_-]{1,80}$/.test(meta.grade_id ?? '') || ids.has(meta.grade_id)) {
        throw new Error('grade_id manquant ou dupliqué.');
      }
      ids.add(meta.grade_id);
    }
    if (cell.cell_type === 'code' && meta.grade) {
      if (!Number.isFinite(meta.points) || meta.points <= 0) throw new Error('Points de test invalides.');
      points += meta.points;
    }
  }
  if (points <= 0) throw new Error('Au moins un test noté est requis.');
  const files = bundle.files ?? {};
  if (!files || Array.isArray(files) || typeof files !== 'object' || Object.keys(files).length > 100) {
    throw new Error('Fichiers invalides.');
  }
  let size = Buffer.byteLength(JSON.stringify(bundle));
  for (const [name, value] of Object.entries(files)) {
    if (!/^[A-Za-z0-9][A-Za-z0-9_./-]{0,179}$/.test(name) || name.split('/').some(p => !p || p.startsWith('.'))
        || !/\.(py|csv|json|txt|png|jpg|npy|ipynb)$/i.test(name) || name === 'assignment.ipynb'
        || typeof value !== 'string' || !/^(?:[A-Za-z0-9+/]{4})*(?:[A-Za-z0-9+/]{2}==|[A-Za-z0-9+/]{3}=)?$/.test(value)) {
      throw new Error('Nom de fichier ou encodage base64 invalide.');
    }
  }
  if (size > 20 * 1024 * 1024) throw new Error('Dossier supérieur à 20 Mio.');
  return { ...bundle, files, profile: 'python', tests_weight: weight };
}

export async function readNotebookBundle(file, baseDir = process.cwd()) {
  return validateNotebookBundle(JSON.parse(await fs.readFile(path.resolve(baseDir, file), 'utf8')));
}
