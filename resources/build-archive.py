from pathlib import Path
import hashlib
import re
import sys
import zipfile

root = Path(__file__).resolve().parent.parent
version = sys.argv[1] if len(sys.argv) > 1 else '0.1.0-rc.1'
if not re.fullmatch(r'[0-9]+\.[0-9]+\.[0-9]+(?:-[a-z0-9.]+)?', version):
    raise SystemExit('Invalid release version')
destination = root / 'dist'
destination.mkdir(exist_ok=True)
archive = destination / f'extension-uninstaller-{version}.zip'
allowed_roots = {'src', 'bin', 'js', 'less', 'locale', 'docs', 'resources', 'tests'}
allowed_files = {'composer.json', 'extend.php', 'README.md', 'INSTALL-DE.md', 'INSTALL-EN.md', 'UPDATE-DE.md', 'CHANGELOG.md', 'LICENSE', '.gitignore', '.gitattributes'}
files = []
for file in sorted(root.rglob('*')):
    relative = file.relative_to(root)
    if not file.is_file() or file.is_symlink():
        continue
    if any(part in {'node_modules', 'vendor', '.git', '__pycache__'} for part in relative.parts):
        continue
    if len(relative.parts) == 1 and relative.name in allowed_files:
        files.append(file)
    elif relative.parts[0] in allowed_roots:
        files.append(file)
manifest = []
with zipfile.ZipFile(archive, 'w', zipfile.ZIP_DEFLATED, compresslevel=9) as output:
    for file in files:
        relative = file.relative_to(root)
        name = 'extension-uninstaller/' + relative.as_posix()
        info = zipfile.ZipInfo(name)
        info.create_system = 3
        info.compress_type = zipfile.ZIP_DEFLATED
        mode = 0o100755 if relative.parts[0] == 'bin' else 0o100644
        info.external_attr = mode << 16
        data = file.read_bytes()
        output.writestr(info, data)
        manifest.append(hashlib.sha256(data).hexdigest() + '  ' + name)
with zipfile.ZipFile(archive) as output:
    if output.testzip() is not None:
        raise SystemExit('Archive verification failed')
    for entry in output.infolist():
        relative = Path(*entry.filename.split('/')[1:])
        if output.read(entry) != (root / relative).read_bytes():
            raise SystemExit('Archive content mismatch: ' + entry.filename)
(destination / 'FILES.sha256').write_text('\n'.join(manifest) + '\n', encoding='utf-8')
archive.with_suffix('.zip.sha256').write_text(hashlib.sha256(archive.read_bytes()).hexdigest() + '  ' + archive.name + '\n', encoding='ascii')
print(str(archive))
print(f'{len(files)} files, {archive.stat().st_size} bytes; verified.')
