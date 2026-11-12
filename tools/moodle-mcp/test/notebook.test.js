import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readNotebookBundle, validateNotebookBundle } from '../src/notebook.js';
import { deployModule } from '../src/deploy.js';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';

const template = new URL('../../../skills/moodle-ciel/templates/notebook/bundle.json', import.meta.url);
test('notebook template validates and preserves the default weighting', async () => {
  const { fileURLToPath } = await import('node:url');
  const bundle = await readNotebookBundle(fileURLToPath(template));
  assert.equal(bundle.tests_weight, 70);
  assert.equal(bundle.profile, 'python');
  const bad = structuredClone(bundle);
  bad.files = { '../secret.py': 'eA==' };
  assert.throws(() => validateNotebookBundle(bad), /Nom de fichier/);
  bad.files = {};
  bad.master.cells.at(-1).metadata.nbgrader.grade_id = 'answer_add';
  assert.throws(() => validateNotebookBundle(bad), /dupliqué/);
});

test('an invalid notebook prevents every Moodle call, including section creation', async () => {
  const dir = await fs.mkdtemp(path.join(os.tmpdir(), 'notebook-preflight-'));
  try {
    await fs.writeFile(path.join(dir, 'bad.json'), JSON.stringify({ master: { nbformat: 3 } }));
    const file = path.join(dir, 'module.deploy.json');
    await fs.writeFile(file, JSON.stringify({ format: 'moodle-ciel/module@1', course: { id: 2 },
      section: { number: 1 }, activities: [{ key: 'tp1', role: 'tp', notebook: { bundle_file: 'bad.json' } }] }));
    let calls = 0;
    const client = { callJson: async () => { calls++; } };
    await assert.rejects(deployModule(client, file), /nbformat 4/);
    assert.equal(calls, 0);
  } finally {
    await fs.rm(dir, { recursive: true, force: true });
  }
});
