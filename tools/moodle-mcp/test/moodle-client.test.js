import assert from 'node:assert/strict';
import { test } from 'node:test';
import { loadConfig, ConfigError } from '../src/config.js';
import { flattenParams, MoodleClient, MoodleError } from '../src/moodle-client.js';

/** Faux fetch : mémorise la requête, renvoie `body` avec le statut donné. */
function fakeFetch(body, status = 200) {
  const calls = [];
  const fn = async (url, init) => {
    calls.push({ url, init });
    return {
      ok: status >= 200 && status < 300,
      status,
      text: async () => (typeof body === 'string' ? body : JSON.stringify(body)),
    };
  };
  fn.calls = calls;
  return fn;
}

test('les paramètres sont aplatis au format REST de Moodle', () => {
  const params = flattenParams({
    courseid: 7, includexml: true, includebanks: false,
    categoryids: [3, 4], options: [{ name: 'a', value: 1 }], skip: null,
  });
  assert.deepEqual(Object.fromEntries(params), {
    courseid: '7', includexml: '1', includebanks: '0',
    'categoryids[0]': '3', 'categoryids[1]': '4',
    'options[0][name]': 'a', 'options[0][value]': '1',
  });
});

test('le jeton part dans le corps POST, jamais dans l\'URL', async () => {
  const fetch = fakeFetch({ json: '{"ok":true}' });
  const client = new MoodleClient({ url: 'https://moodle.test/', token: 'SECRET', fetchImpl: fetch });
  const data = await client.callJson('local_aibridge_get_course', { courseid: 5 });
  assert.deepEqual(data, { ok: true });
  const [{ url, init }] = fetch.calls;
  assert.equal(url, 'https://moodle.test/webservice/rest/server.php');
  assert.ok(!url.includes('SECRET'));
  assert.equal(init.method, 'POST');
  const body = new URLSearchParams(init.body);
  assert.equal(body.get('wstoken'), 'SECRET');
  assert.equal(body.get('wsfunction'), 'local_aibridge_get_course');
  assert.equal(body.get('moodlewsrestformat'), 'json');
  assert.equal(body.get('courseid'), '5');
});

test('une exception Moodle devient une erreur claire', async () => {
  const fetch = fakeFetch({ exception: 'moodle_exception', errorcode: 'invalidtoken', message: 'Invalid token' });
  const client = new MoodleClient({ url: 'https://moodle.test', token: 't', fetchImpl: fetch });
  await assert.rejects(client.call('local_aibridge_get_site_info'), (error) => {
    assert.ok(error instanceof MoodleError);
    assert.equal(error.errorcode, 'invalidtoken');
    assert.match(error.message, /Jeton invalide/);
    return true;
  });
});

test('une page HTML (maintenance) est signalée comme réponse non JSON', async () => {
  const client = new MoodleClient({ url: 'https://moodle.test', token: 't', fetchImpl: fakeFetch('<html>maintenance</html>') });
  await assert.rejects(client.call('x'), /non JSON/);
});

test('une erreur HTTP est signalée avec son statut', async () => {
  const client = new MoodleClient({ url: 'https://moodle.test', token: 't', fetchImpl: fakeFetch('oops', 503) });
  await assert.rejects(client.call('x'), (error) => error.status === 503);
});

test('configuration : https exigé hors poste local, lecture seule par défaut', () => {
  assert.throws(() => loadConfig({ MOODLE_URL: 'http://moodle.lycee.fr', MOODLE_TOKEN: 't' }), ConfigError);
  assert.throws(() => loadConfig({ MOODLE_URL: 'https://moodle.lycee.fr' }), /MOODLE_TOKEN/);
  const local = loadConfig({ MOODLE_URL: 'http://localhost:8080/', MOODLE_TOKEN: 't' });
  assert.equal(local.url, 'http://localhost:8080');
  assert.equal(local.readonly, true);
  const remote = loadConfig({ MOODLE_URL: 'https://moodle.lycee.fr/moodle/', MOODLE_TOKEN: ' t ' });
  assert.equal(remote.url, 'https://moodle.lycee.fr/moodle');
  assert.equal(remote.token, 't');
  assert.equal(loadConfig({ MOODLE_URL: 'http://10.0.0.5', MOODLE_TOKEN: 't', MOODLE_ALLOW_HTTP: '1' }).url,
    'http://10.0.0.5');
});
