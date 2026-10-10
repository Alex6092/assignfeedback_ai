// Client REST des services web Moodle. Le jeton part dans le corps d'une
// requête POST, jamais dans l'URL (qui finit dans les journaux du serveur).

export class MoodleError extends Error {
  /**
   * @param {string} message
   * @param {{errorcode?: string, exception?: string, debuginfo?: string, status?: number}} [details]
   */
  constructor(message, details = {}) {
    super(message);
    this.name = 'MoodleError';
    this.errorcode = details.errorcode;
    this.exception = details.exception;
    this.debuginfo = details.debuginfo;
    this.status = details.status;
  }
}

/** Messages clairs pour les erreurs de service web les plus courantes. */
const HINTS = {
  invalidtoken: 'Jeton invalide ou expiré : régénérez-le (Administration > Serveur > Services web > Gérer les jetons).',
  accessexception: 'Accès refusé : le service « local_aibridge » doit être activé, et le compte autorisé sur ce service.',
  servicerequireslogin: 'Le service exige une connexion : vérifiez que le compte du jeton est actif.',
  webservicesnotenabled: 'Les services web sont désactivés sur ce site.',
  nopermissions: 'Droit manquant pour le compte du jeton (local/aibridge:read dans ce cours ?).',
  requireloginerror: 'Le compte du jeton n\'a pas accès à ce cours (inscription ou rôle manquant).',
  invalidrecord: 'Élément introuvable (identifiant erroné, ou supprimé).',
  invalidrecordunknown: 'Élément introuvable (identifiant erroné, ou supprimé).',
  invalidparameter: 'Paramètre refusé par Moodle.',
};

/**
 * Aplatit des paramètres au format attendu par le protocole REST de Moodle :
 * tableaux en nom[0], objets en nom[clé], booléens en 0/1.
 *
 * @param {Record<string, unknown>} params
 * @param {URLSearchParams} [target]
 * @param {string} [prefix]
 */
export function flattenParams(params, target = new URLSearchParams(), prefix = '') {
  for (const [key, value] of Object.entries(params ?? {})) {
    const name = prefix ? `${prefix}[${key}]` : key;
    if (value === undefined || value === null) {
      continue;
    }
    if (Array.isArray(value)) {
      value.forEach((item, index) => {
        if (item !== null && typeof item === 'object') {
          flattenParams(item, target, `${name}[${index}]`);
        } else {
          target.append(`${name}[${index}]`, scalar(item));
        }
      });
    } else if (typeof value === 'object') {
      flattenParams(value, target, name);
    } else {
      target.append(name, scalar(value));
    }
  }
  return target;
}

function scalar(value) {
  if (typeof value === 'boolean') {
    return value ? '1' : '0';
  }
  return String(value);
}

export class MoodleClient {
  /**
   * @param {{url: string, token: string, timeoutMs?: number, fetchImpl?: typeof fetch}} options
   */
  constructor({ url, token, timeoutMs = 120000, fetchImpl = globalThis.fetch }) {
    this.url = url.replace(/\/+$/, '');
    this.token = token;
    this.timeoutMs = timeoutMs;
    this.fetchImpl = fetchImpl;
  }

  /**
   * Appelle une fonction de service web et renvoie sa réponse décodée.
   *
   * @param {string} wsfunction
   * @param {Record<string, unknown>} [params]
   */
  async call(wsfunction, params = {}) {
    const body = new URLSearchParams();
    body.set('wstoken', this.token);
    body.set('wsfunction', wsfunction);
    body.set('moodlewsrestformat', 'json');
    flattenParams(params, body);

    let response;
    try {
      response = await this.fetchImpl(`${this.url}/webservice/rest/server.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
        body,
        signal: AbortSignal.timeout(this.timeoutMs),
      });
    } catch (error) {
      const reason = error?.name === 'TimeoutError' ? `pas de réponse en ${this.timeoutMs / 1000} s` : error?.message;
      throw new MoodleError(`Moodle injoignable (${this.url}) : ${reason}`);
    }

    const text = await response.text();
    if (!response.ok) {
      throw new MoodleError(`Moodle a répondu HTTP ${response.status} à ${wsfunction}.`, { status: response.status });
    }
    let data;
    try {
      data = JSON.parse(text);
    } catch {
      throw new MoodleError(`Réponse non JSON de Moodle à ${wsfunction} (page d'erreur ou maintenance ?) : `
        + text.slice(0, 200));
    }
    if (data && typeof data === 'object' && !Array.isArray(data) && data.exception) {
      const hint = HINTS[data.errorcode];
      const message = hint ? `${hint} (${data.errorcode} : ${data.message})` : `${data.message} (${data.errorcode})`;
      throw new MoodleError(`${wsfunction} : ${message}`, data);
    }
    return data;
  }

  /**
   * Télécharge un fichier d'une zone de fichiers (images d'une page de leçon...)
   * par webservice/pluginfile.php. Le jeton part dans le corps POST.
   *
   * @param {string} filepath chemin après pluginfile.php : /contextid/composant/zone/itemid/fichier
   * @returns {Promise<Buffer>}
   */
  async download(filepath) {
    const encoded = filepath.split('/').map((part) => encodeURIComponent(part)).join('/');
    let response;
    try {
      response = await this.fetchImpl(`${this.url}/webservice/pluginfile.php${encoded}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ token: this.token }),
        signal: AbortSignal.timeout(this.timeoutMs),
      });
    } catch (error) {
      throw new MoodleError(`Fichier injoignable (${filepath}) : ${error?.message}`);
    }
    if (!response.ok) {
      throw new MoodleError(`Fichier ${filepath} : HTTP ${response.status}.`, { status: response.status });
    }
    return Buffer.from(await response.arrayBuffer());
  }

  /**
   * Appelle une fonction du pont (réponse { json: "..." }) et décode le JSON.
   *
   * @param {string} wsfunction
   * @param {Record<string, unknown>} [params]
   */
  async callJson(wsfunction, params = {}) {
    const data = await this.call(wsfunction, params);
    if (!data || typeof data.json !== 'string') {
      throw new MoodleError(`${wsfunction} : réponse inattendue (champ « json » absent).`);
    }
    return JSON.parse(data.json);
  }
}
