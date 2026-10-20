#!/usr/bin/env python3
"""Build the Joomla package from locked, installed dependencies."""
from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
import hashlib
import json
import shutil
root = Path(__file__).resolve().parents[1]
build = root / 'build'
def add(archive, path, name):
    info = ZipInfo(str(name), (1980, 1, 1, 0, 0, 0))
    info.compress_type = ZIP_DEFLATED
    info.external_attr = 0o100644 << 16
    archive.writestr(info, path.read_bytes())
if build.exists(): shutil.rmtree(build)
(build / 'packages').mkdir(parents=True)
with ZipFile(build / 'packages/plg_system_soca.zip', 'w', ZIP_DEFLATED) as archive:
    for directory in ['services', 'src', 'lib', 'vendor', 'sql']:
        for path in sorted((root / directory).rglob('*')):
            if path.is_file(): add(archive, path, path.relative_to(root))
    for name in ['bootstrap.php', 'soca.xml', 'LICENSE', 'README.md']: add(archive, root / name, name)
for group in ['authentication', 'user']:
    source = root / 'plugins' / group / 'soca'
    with ZipFile(build / f'packages/plg_{group}_soca.zip', 'w', ZIP_DEFLATED) as archive:
        for path in sorted(source.rglob('*')):
            if path.is_file(): add(archive, path, path.relative_to(source))
with ZipFile(build / 'pkg_soca-0.1.0.zip', 'w', ZIP_DEFLATED) as archive:
    add(archive, root / 'pkg_soca.xml', 'pkg_soca.xml')
    add(archive, root / 'LICENSE', 'LICENSE')
    for path in sorted((build / 'packages').glob('*.zip')): add(archive, path, path.relative_to(build))
print(build / 'pkg_soca-0.1.0.zip')

components = []
for dep in json.loads((root / 'composer.lock').read_text())['packages']:
    components.append({'type': 'library', 'name': dep['name'], 'version': dep['version'], 'purl': f"pkg:composer/{dep['name']}@{dep['version']}"})
(build / 'bom.cdx.json').write_text(json.dumps({'bomFormat': 'CycloneDX', 'specVersion': '1.5', 'version': 1, 'components': components}, indent=2) + '\n')
(build / 'SHA256SUMS').write_text(''.join(f'{hashlib.sha256(p.read_bytes()).hexdigest()}  {p.name}\n' for p in [build / 'pkg_soca-0.1.0.zip', build / 'bom.cdx.json']))
