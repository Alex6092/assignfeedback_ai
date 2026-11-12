"""Build local, deterministic installation archives from explicit source directories."""
import hashlib
import json
from pathlib import Path
import re
import zipfile

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / 'dist' / 'notebooks-0.1.0'
EXCLUDE = {'.git', '.env', '.venv', '.pytest_cache', '__pycache__', 'node_modules',
           '.build-config', '.build-data', 'exemples-ia', 'exemples-cpp', 'exemples-video'}
GENERATED = ROOT / 'services/notebook/labextension/labextension'


def sources(directory):
    for file in sorted(directory.rglob('*')):
        if not file.is_file():
            continue
        if any(p in EXCLUDE or p.endswith('.egg-info') for p in file.relative_to(ROOT).parts):
            continue
        if file.is_relative_to(GENERATED) or file.suffix in {'.pyc', '.log', '.zip'}:
            continue
        if file.is_symlink() or not file.resolve().is_relative_to(ROOT):
            raise ValueError(f'Unexpected source link: {file}')
        yield file


def archive(name, trees):
    target = OUT / name
    with zipfile.ZipFile(target, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as package:
        for relative, prefix in trees:
            directory = ROOT / relative
            for file in sources(directory):
                member = prefix + '/' + file.relative_to(directory).as_posix()
                info = zipfile.ZipInfo(member, (2026, 11, 11, 0, 0, 0))
                info.compress_type = zipfile.ZIP_DEFLATED
                info.external_attr = (0o100755 if file.suffix == '.sh' else 0o100644) << 16
                data = file.read_bytes()
                if file.suffix == '.sh':
                    data = data.replace(b'\r\n', b'\n')
                package.writestr(info, data)
    with zipfile.ZipFile(target) as package:
        if package.testzip() is not None:
            raise RuntimeError('Invalid archive')
    return {'file': name, 'sha256': hashlib.sha256(target.read_bytes()).hexdigest(), 'bytes': target.stat().st_size}


def main():
    OUT.mkdir(parents=True, exist_ok=True)
    manifest = {'status': 'alpha - VM and Moodle acceptance pending', 'archives': []}
    for directory in ['mod/assign/feedback/ai', 'local/notebook', 'mod/assign/submission/notebook', 'local/aibridge']:
        source = ROOT / directory
        version = (source / 'version.php').read_text(encoding='utf-8-sig')
        component = re.search(r"\$plugin->component\s*=\s*'([^']+)'", version)[1]
        release = re.search(r"\$plugin->release\s*=\s*'([^']+)'", version)[1]
        manifest['archives'].append(archive(f'{component}-{release}.zip', [(directory, source.name)]))
    manifest['archives'].append(archive('notebooks-platform-0.1.0.zip', [
        ('services/notebook', 'notebooks-local/services/notebook'),
        ('tools/moodle-mcp', 'notebooks-local/tools/moodle-mcp'),
        ('skills/moodle-ciel', 'notebooks-local/skills/moodle-ciel'),
    ]))
    (OUT / 'manifest.json').write_text(json.dumps(manifest, indent=2) + '\n', encoding='utf-8')
    (OUT / 'SHA256SUMS.txt').write_text(''.join(f"{a['sha256']}  {a['file']}\n" for a in manifest['archives']), encoding='utf-8')
    print(json.dumps(manifest, indent=2))


if __name__ == '__main__':
    main()
