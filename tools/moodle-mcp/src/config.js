// Configuration du serveur, lue dans l'environnement. Le jeton n'est jamais
// écrit dans le dépôt : il est passé par le client MCP (claude mcp add -e,
// [mcp_servers.moodle.env] de Codex) ou par l'environnement de l'utilisateur.

const LOCAL_HOSTS = new Set(['localhost', '127.0.0.1', '[::1]']);

export class ConfigError extends Error {}

/**
 * @param {Record<string, string|undefined>} env
 * @returns {{url: string, token: string, readonly: boolean, timeoutMs: number}}
 */
export function loadConfig(env = process.env) {
  const rawUrl = (env.MOODLE_URL || '').trim();
  const token = (env.MOODLE_TOKEN || '').trim();
  if (!rawUrl) {
    throw new ConfigError('MOODLE_URL manquant : adresse du site Moodle (ex. https://moodle.exemple.fr).');
  }
  if (!token) {
    throw new ConfigError('MOODLE_TOKEN manquant : jeton du service « local_aibridge ».');
  }
  let url;
  try {
    url = new URL(rawUrl);
  } catch {
    throw new ConfigError(`MOODLE_URL invalide : ${rawUrl}`);
  }
  // Le jeton circule dans le corps de la requête : en clair, il serait lisible sur le réseau.
  const local = LOCAL_HOSTS.has(url.hostname) || url.hostname.endsWith('.localhost') || url.hostname.endsWith('.test');
  if (url.protocol !== 'https:' && !local && env.MOODLE_ALLOW_HTTP !== '1') {
    throw new ConfigError('MOODLE_URL doit être en https (ou MOODLE_ALLOW_HTTP=1 pour un réseau de confiance).');
  }
  const timeoutMs = Number.parseInt(env.MOODLE_TIMEOUT_MS || '120000', 10);
  return {
    url: url.toString().replace(/\/+$/, ''),
    token,
    readonly: env.MOODLE_MCP_READONLY !== '0',
    timeoutMs: Number.isFinite(timeoutMs) && timeoutMs > 0 ? timeoutMs : 120000,
  };
}
