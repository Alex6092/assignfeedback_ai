import assert from 'node:assert/strict';
import { test } from 'node:test';
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { InMemoryTransport } from '@modelcontextprotocol/sdk/inMemory.js';
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { registerTools, TRUNCATE_AT } from '../src/tools.js';

/** Serveur MCP réel branché sur un faux client Moodle, en mémoire. */
async function connect(responses, options = {}) {
  const calls = [];
  const moodle = {
    async callJson(fn, params = {}) {
      calls.push({ fn, params });
      const response = responses[fn];
      if (response instanceof Error) {
        throw response;
      }
      return structuredClone(typeof response === 'function' ? response(params) : response);
    },
  };
  const server = new McpServer({ name: 'moodle', version: 'test' });
  registerTools(server, moodle, options);
  const [clientTransport, serverTransport] = InMemoryTransport.createLinkedPair();
  const client = new Client({ name: 'test', version: '0' });
  await Promise.all([server.connect(serverTransport), client.connect(clientTransport)]);
  return { client, calls };
}

const parse = (result) => JSON.parse(result.content[0].text);

test('les outils de lecture sont déclarés en lecture seule', async () => {
  const { client } = await connect({});
  const { tools } = await client.listTools();
  const names = tools.map((tool) => tool.name).sort();
  assert.deepEqual(names, ['moodle_get_course', 'moodle_get_efe_competences', 'moodle_get_module',
    'moodle_get_questions', 'moodle_list_courses', 'moodle_site_info', 'moodle_snapshot_course']);
  for (const tool of tools.filter((t) => t.name !== 'moodle_snapshot_course')) {
    assert.equal(tool.annotations?.readOnlyHint, true, tool.name);
  }
});

test('moodle_get_module tronque les contenus longs, sauf html: "full"', async () => {
  const long = 'x'.repeat(TRUNCATE_AT + 100);
  const { client, calls } = await connect({ local_aibridge_get_module: { instance: { intro: long } } });
  const truncated = parse(await client.callTool({ name: 'moodle_get_module', arguments: { cmid: 12 } }));
  assert.match(truncated.instance.intro, /tronqué/);
  assert.deepEqual(calls[0], { fn: 'local_aibridge_get_module', params: { cmid: 12 } });
  const full = parse(await client.callTool({ name: 'moodle_get_module', arguments: { cmid: 12, html: 'full' } }));
  assert.equal(full.instance.intro, long);
});

test('moodle_get_questions renvoie le XML en entier même en mode tronqué', async () => {
  const xml = `<quiz>${'q'.repeat(TRUNCATE_AT * 2)}</quiz>`;
  const { client, calls } = await connect({ local_aibridge_get_questions: { questions: [], xml } });
  const data = parse(await client.callTool({
    name: 'moodle_get_questions', arguments: { courseid: 3, questionids: [5, 6], include_xml: true },
  }));
  assert.equal(data.xml, xml);
  assert.deepEqual(calls[0].params, { courseid: 3, categoryids: [], questionids: [5, 6], includebanks: true, includexml: true });
});

test('moodle_site_info masque le SCSS du thème par défaut', async () => {
  const { client } = await connect({ local_aibridge_get_site_info: { theme: { scsspre: '.objectif{}', scss: '' } } });
  const data = parse(await client.callTool({ name: 'moodle_site_info', arguments: {} }));
  assert.match(data.theme.scsspre, /^\[11 caractères/);
  const full = parse(await client.callTool({ name: 'moodle_site_info', arguments: { theme_css: true } }));
  assert.equal(full.theme.scsspre, '.objectif{}');
});

test('une erreur Moodle revient à l\'agent comme résultat d\'erreur', async () => {
  const { client } = await connect({ local_aibridge_get_course: new Error('Droit manquant') });
  const result = await client.callTool({ name: 'moodle_get_course', arguments: { courseid: 1 } });
  assert.equal(result.isError, true);
  assert.match(result.content[0].text, /Droit manquant/);
});

test('moodle_get_efe_competences ne renvoie que les niveaux demandés', async () => {
  const { client } = await connect({
    local_aibridge_get_efe_competences: { configured: true, n1: [{ code: 'C01' }], n2: [], n3: [{ code: 'x' }, { code: 'y' }] },
  });
  const data = parse(await client.callTool({ name: 'moodle_get_efe_competences', arguments: {} }));
  assert.deepEqual(data.n1, [{ code: 'C01' }]);
  assert.match(data.n3, /^\[2 éléments/);
});
