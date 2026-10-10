// Lecture des fichiers sources d'un parcours (HTML, texte) pour les envoyer à
// Moodle. Dans un fichier HTML du skill, seul le contenu entre les marqueurs
// « ▶ DÉBUT / FIN CONTENU MOODLE ◀ » est déployé : le reste (bloc de style
// d'aperçu, conteneurs, notes de l'enseignant) ne part jamais.

import fs from 'node:fs/promises';
import path from 'node:path';

const BLOCK = /<!--\s*▶\s*DÉBUT CONTENU MOODLE\s*◀\s*-->([\s\S]*?)<!--\s*▶\s*FIN CONTENU MOODLE\s*◀\s*-->/g;

/**
 * Blocs entre marqueurs d'un document HTML (un bloc = une page de leçon).
 *
 * @param {string} html
 * @returns {string[]} blocs, sans espaces de bord ; [] s'il n'y a pas de marqueur
 */
export function extractBlocks(html) {
  return [...String(html).matchAll(BLOCK)].map((match) => match[1].trim());
}

/**
 * Contenu d'un fichier pour un champ unique (page, consigne, corrigé...).
 * HTML avec marqueurs : le seul bloc entre marqueurs. Sinon : tout le fichier.
 *
 * @param {string} file chemin du fichier
 * @param {string} [baseDir] dossier de référence des chemins relatifs
 */
export async function readField(file, baseDir = process.cwd()) {
  const full = path.resolve(baseDir, file);
  const text = (await fs.readFile(full, 'utf8')).replace(/^﻿/, '');
  const blocks = extractBlocks(text);
  if (blocks.length > 1) {
    throw new Error(`${file} : ${blocks.length} blocs entre marqueurs ; un champ n'en reçoit qu'un (plusieurs pages = une leçon).`);
  }
  return blocks.length === 1 ? blocks[0] : text.trim();
}

/**
 * Remplace, dans des réglages, les champs dont la valeur vient d'un fichier.
 *
 * @param {Record<string, unknown>} settings
 * @param {Record<string, string>} files champ → chemin
 * @param {string} [baseDir]
 */
export async function withFiles(settings, files, baseDir) {
  const out = { ...settings };
  for (const [field, file] of Object.entries(files ?? {})) {
    out[field] = await readField(file, baseDir);
  }
  return out;
}
