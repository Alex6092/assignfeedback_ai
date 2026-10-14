import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { test } from 'node:test';
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { InMemoryTransport } from '@modelcontextprotocol/sdk/inMemory.js';
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { extractBlocks, readField } from '../src/content.js';
import { localMidnight, registerTools } from '../src/tools.js';

const WRITE_TOOLS = ['moodle_create_course', 'moodle_create_module', 'moodle_delete_draft', 'moodle_upsert_section'];

async function connect({ readonly }) {
  const calls = [];
  const moodle = {
    async callJson(fn, params = {}) {
      calls.push({ fn, params });
      return { ok: true, fn };
    },
  };
  const server = new McpServer({ name: 'moodle', version: 'test' });
  registerTools(server, moodle, { readonly });
  const [clientTransport, serverTransport] = InMemoryTransport.createLinkedPair();
  const client = new Client({ name: 'test', version: '0' });
  await Promise.all([server.connect(serverTransport), client.connect(clientTransport)]);
  return { client, calls };
}

const html = (blocks) => `<!DOCTYPE html><html><head><style>.x{}</style></head><body>
<div class="contents"><div class="no-overflow">
${blocks.map((b) => `<!-- ▶ DÉBUT CONTENU MOODLE ◀ -->\n${b}\n<!-- ▶ FIN CONTENU MOODLE ◀ -->`).join('\n')}
</div></div></body></html>`;

test('en lecture seule, aucun outil d\'écriture n\'est proposé', async () => {
  const { client } = await connect({ readonly: true });
  const names = (await client.listTools()).tools.map((t) => t.name);
  for (const name of WRITE_TOOLS) {
    assert.ok(!names.includes(name), name);
  }
});

test('en écriture, les outils sont proposés ; seule la suppression est destructive', async () => {
  const { client } = await connect({ readonly: false });
  const tools = (await client.listTools()).tools.filter((t) => WRITE_TOOLS.includes(t.name));
  assert.equal(tools.length, WRITE_TOOLS.length);
  for (const tool of tools) {
    assert.equal(tool.annotations?.readOnlyHint, false, tool.name);
    assert.equal(tool.annotations?.destructiveHint, tool.name === 'moodle_delete_draft', tool.name);
  }
});

test('extractBlocks ne garde que le contenu entre les marqueurs', () => {
  assert.deepEqual(extractBlocks(html(['<h1>Un</h1>', '<h1>Deux</h1>'])), ['<h1>Un</h1>', '<h1>Deux</h1>']);
  assert.deepEqual(extractBlocks('<p>sans marqueur</p>'), []);
});

test('readField : un bloc HTML, un fichier texte entier, et refus de plusieurs blocs', async () => {
  const dir = await fs.mkdtemp(path.join(os.tmpdir(), 'moodle-mcp-'));
  await fs.writeFile(path.join(dir, 'page.html'), html(['<h1>Page</h1>']));
  await fs.writeFile(path.join(dir, 'lecon.html'), html(['<h1>A</h1>', '<h1>B</h1>']));
  await fs.writeFile(path.join(dir, 'corrige.txt'), '﻿Noter sur 100.\n');
  assert.equal(await readField('page.html', dir), '<h1>Page</h1>');
  assert.equal(await readField('corrige.txt', dir), 'Noter sur 100.');
  await assert.rejects(readField('lecon.html', dir), /2 blocs/);
});

test('moodle_create_module envoie les réglages en JSON, champs lus dans les fichiers compris', async () => {
  const dir = await fs.mkdtemp(path.join(os.tmpdir(), 'moodle-mcp-'));
  const consigne = path.join(dir, 'consigne.html');
  await fs.writeFile(consigne, html(['<p><strong>Mission.</strong> …</p>']));
  const { client, calls } = await connect({ readonly: false });
  const result = await client.callTool({
    name: 'moodle_create_module',
    arguments: {
      courseid: 80, section: 5, modname: 'assign', name: 'TP5.1', dry_run: true,
      settings: { duedate: '2026-11-09T12:00', efenotes_competence: ['C08.4.D2.T3'] },
      settings_files: { activity: consigne },
    },
  });
  assert.ok(!result.isError, result.content?.[0]?.text);
  assert.equal(calls[0].fn, 'local_aibridge_create_module');
  assert.equal(calls[0].params.dryrun, true);
  assert.deepEqual(JSON.parse(calls[0].params.settings), {
    duedate: '2026-11-09T12:00',
    efenotes_competence: ['C08.4.D2.T3'],
    activity: '<p><strong>Mission.</strong> …</p>',
  });
});

test('moodle_create_course convertit la date de début en horodatage', async () => {
  const { client, calls } = await connect({ readonly: false });
  await client.callTool({
    name: 'moodle_create_course',
    arguments: { categoryid: 9, fullname: 'Bac à sable M5', shortname: 'BAS-M5', startdate: '2026-11-02' },
  });
  assert.equal(calls[0].params.startdate, localMidnight('2026-11-02'));
  assert.equal(calls[0].params.dryrun, false);
});

test('moodle_set_lesson_pages envoie les images citées en relatif, lues à côté de lecon.html', async () => {
  const dir = await fs.mkdtemp(path.join(os.tmpdir(), 'moodle-mcp-'));
  await fs.mkdir(path.join(dir, 'images'));
  await fs.writeFile(path.join(dir, 'images', 'classes.png'), Buffer.from('PNG'));
  await fs.writeFile(path.join(dir, 'lecon.html'), html(['<h1>📘 Module 3 — Classes</h1><img src="images/classes.png" alt="c">',
    '<h1>Suite</h1>']));
  const { client, calls } = await connect({ readonly: false });
  const result = await client.callTool({
    name: 'moodle_set_lesson_pages', arguments: { cmid: 3001, file: path.join(dir, 'lecon.html'), dry_run: true },
  });
  assert.ok(!result.isError, result.content?.[0]?.text);
  assert.equal(calls[0].fn, 'local_aibridge_set_lesson_pages');
  assert.deepEqual(JSON.parse(calls[0].params.pages).map((p) => p.title), ['Classes', 'Suite']);
  assert.deepEqual(JSON.parse(calls[0].params.files), [{ filename: 'classes.png', content: Buffer.from('PNG').toString('base64') }]);
  assert.equal(calls[0].params.dryrun, true);
});
