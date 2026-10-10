// Mise en forme des réponses pour l'agent : les longs contenus HTML sont
// tronqués par défaut, pour rester sous la limite de taille d'une réponse
// d'outil MCP. Le contenu intégral passe par html: "full" ou par le snapshot.

/**
 * Copie de `value` où chaque chaîne plus longue que `max` est tronquée.
 *
 * @param {unknown} value
 * @param {number} max
 * @returns {unknown}
 */
export function truncateStrings(value, max) {
  if (typeof value === 'string') {
    if (value.length <= max) {
      return value;
    }
    return `${value.slice(0, max)}… [tronqué : ${value.length} caractères au total]`;
  }
  if (Array.isArray(value)) {
    return value.map((item) => truncateStrings(item, max));
  }
  if (value && typeof value === 'object') {
    return Object.fromEntries(Object.entries(value).map(([key, item]) => [key, truncateStrings(item, max)]));
  }
  return value;
}

/** Réponse texte d'un outil MCP. */
export function textResult(data) {
  const text = typeof data === 'string' ? data : JSON.stringify(data, null, 2);
  return { content: [{ type: 'text', text }] };
}

/** Réponse d'erreur d'un outil MCP (l'agent voit le message). */
export function errorResult(error) {
  return { isError: true, content: [{ type: 'text', text: error?.message ?? String(error) }] };
}

/**
 * Nom de fichier lisible et portable : sans accents, minuscules, tirets.
 *
 * @param {string} text
 * @param {number} [max]
 */
export function slugify(text, max = 50) {
  const slug = String(text ?? '')
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, max)
    .replace(/-+$/g, '');
  return slug || 'sans-nom';
}
