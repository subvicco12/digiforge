"""Build exact qualified .17 from committed normalized bytes. Development-only."""
import argparse
import hashlib
import json
from pathlib import Path, PurePosixPath
import platform
import subprocess
import zipfile
import zlib

MANIFEST = 'docs/normalization/QUALIFIED_PAYLOAD.json'
QUALIFIED_SHA256 = '5303ef7eb5ede7dab770599f5cfd279022c77d3d8665a3d5ab1d2fce5ef83dac'


def write_package(payload: dict, manifest: dict, output: Path) -> None:
    records = manifest['files']
    paths = [r['path'] for r in records]
    if len(paths) != len(set(paths)) or set(paths) != set(payload):
        raise ValueError('Exact unique payload inventory required')
    for r in records:
        name = r['path']
        p = PurePosixPath(name)
        if not name or p.is_absolute() or '..' in p.parts or '\\' in name or str(p) != name or name.startswith('.') and name != '.gitkeep':
            raise ValueError('Unsafe payload path')
        raw = payload[name]
        if r['git_mode'] != '100644' or len(raw) != r['size'] or hashlib.sha256(raw).hexdigest() != r['sha256']:
            raise ValueError('Payload byte, size or permission mismatch: ' + name)
    output.parent.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(output, 'w') as archive:
        for name in sorted(payload):
            item = zipfile.ZipInfo('digiforge/' + name, (2026, 11, 6, 0, 0, 0))
            item.create_system = 3
            item.external_attr = 0o100644 << 16
            item.compress_type = zipfile.ZIP_DEFLATED
            archive.writestr(item, payload[name], compresslevel=9)


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    root = Path(__file__).resolve().parent.parent
    def git(*parts):
        return subprocess.check_output(['git', *parts], cwd=root)
    sha = git('rev-parse', 'HEAD').decode().strip()
    # Pin builder and manifest to committed Git objects, never uncommitted source.
    script = Path(__file__).relative_to(root).as_posix()
    if git('show', sha + ':' + script) != Path(__file__).read_bytes():
        raise ValueError('Builder must equal committed source')
    manifest = json.loads(git('show', sha + ':' + MANIFEST))
    if manifest['qualified_zip_sha256'] != QUALIFIED_SHA256 or len(manifest['files']) != 741:
        raise ValueError('Qualified baseline pin changed')
    entries = {}
    for raw in git('ls-tree', '-r', '-z', sha).split(b'\0'):
        if raw:
            meta, name = raw.split(b'\t', 1)
            mode, kind, oid = meta.split(b' ')
            entries[name.decode()] = (mode.decode(), kind.decode(), oid.decode())
    payload = {}
    for r in manifest['files']:
        mode, kind, oid = entries.get(r['path'], ('', '', ''))
        if mode != r['git_mode'] or kind != 'blob':
            raise ValueError('Committed regular-file mode required: ' + r['path'])
        payload[r['path']] = git('cat-file', 'blob', oid)
    runtime_roots = {'assets', 'includes', 'modules', 'admin', 'automation'}
    extra = [n for n in entries if n.split('/')[0] in runtime_roots and n not in payload]
    if extra:
        raise ValueError('Uninventoried runtime path: ' + extra[0])
    args.output.mkdir(parents=True, exist_ok=True)
    target = args.output / 'digiforge.zip'
    write_package(payload, manifest, target)
    digest = hashlib.sha256(target.read_bytes()).hexdigest()
    if digest != QUALIFIED_SHA256 or target.stat().st_size != manifest['qualified_zip_size']:
        target.unlink()
        raise ValueError('Whole ZIP mismatch; check declared Python/zlib compression environment')
    with zipfile.ZipFile(target) as archive:
        if archive.testzip() is not None:
            raise ValueError('ZIP CRC failure')
    inventory = []
    for r in manifest['files']:
        inventory.append({**r, 'source_git_blob': entries[r['path']][2]})
    receipt = {'schema': 'digiforge-normalized-source-artifact-v1', 'source_sha': sha,
               'artifact': target.name, 'sha256': digest, 'bytes': target.stat().st_size,
               'files': inventory, 'regular_files': len(inventory),
               'python': platform.python_version(), 'zlib': zlib.ZLIB_RUNTIME_VERSION,
               'qualified_archive_byte_identical': True, 'historical_authorship_recovered': False,
               'pod_integration_performed': False, 'installation_authorized': False}
    (args.output / 'source-to-artifact.json').write_text(json.dumps(receipt, indent=2) + '\n')
    (args.output / 'digiforge.zip.sha256').write_text(digest + '  digiforge.zip\n')
    print(json.dumps({k: v for k, v in receipt.items() if k != 'files'}, indent=2))


if __name__ == '__main__':
    main()
