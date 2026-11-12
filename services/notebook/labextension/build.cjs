const { execFileSync } = require('node:child_process');
const path = require('node:path');
const core = process.env.JUPYTERLAB_CORE_PATH || execFileSync('python', ['-c',
  "import pathlib,jupyterlab; print(pathlib.Path(jupyterlab.__file__).parent / 'staging')"], { encoding: 'utf8' }).trim();
execFileSync(process.execPath, [require.resolve('@jupyterlab/builder/lib/build-labextension.js'),
  '--core-path', path.resolve(core), '.'], { stdio: 'inherit' });
