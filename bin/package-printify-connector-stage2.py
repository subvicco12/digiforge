#!/usr/bin/env python3
"""Reproducible TEST-ONLY installed-lineage connector port; never deploys or accesses secrets."""
import argparse
import hashlib
import io
import json
import pathlib
import stat
import subprocess
import zipfile

ROOT = pathlib.Path(__file__).resolve().parents[1]
BASELINE_SHA256 = '5303ef7eb5ede7dab770599f5cfd279022c77d3d8665a3d5ab1d2fce5ef83dac'
BASELINE_SOURCE = 'e957ba096751dc34254cdbcbefed13b625f5e507'
BASELINE_COUNT = 741
BASELINE_SIZE = 3201579
CANDIDATE_VERSION = '1.0.103.17-connector.1'
CLIENT = 'includes/POD/PrintifyCatalogClient.php'
ANCHOR = b'    private function id(int $id): int\n'
METHOD_START = b'    /** One admitted attempt; the caller owns all execution and budget policy. */\n'


def sha(raw):
    return hashlib.sha256(raw).hexdigest()


def connector_method(source):
    if source.count(METHOD_START) != 1 or source.count(ANCHOR) != 1:
        raise ValueError('Exact reviewed connector method and anchor required')
    method = source[source.index(METHOD_START):source.index(ANCHOR)]
    if method.count(b'public function connectorRead(') != 1:
        raise ValueError('Exact additive method required')
    return method


def port_client(baseline, source):
    if baseline.count(ANCHOR) != 1 or b'function connectorRead(' in baseline:
        raise ValueError('Installed client preimage is incompatible')
    method = connector_method(source)
    candidate = baseline.replace(ANCHOR, method + ANCHOR, 1)
    if candidate.replace(method, b'', 1) != baseline:
        raise ValueError('Non-additive client composition')
    return candidate


def port_header(raw):
    old = b'1.0.103.17'
    if raw.count(old) != 2 or b' * Version: ' + old not in raw or b"const DIGIFORGE_VERSION = '" + old + b"';" not in raw:
        raise ValueError('Exact installed version preimage required')
    return raw.replace(old, CANDIDATE_VERSION.encode())


def read_archive(archive):
    data = {}
    for entry in archive.infolist():
        path = pathlib.PurePosixPath(entry.filename)
        mode = entry.external_attr >> 16
        if str(path) != entry.filename or entry.is_dir() or not entry.filename.startswith('digiforge/') or path.is_absolute() or '..' in path.parts or '\\' in entry.filename or stat.S_ISLNK(mode) or not stat.S_ISREG(mode) or entry.filename in data:
            raise ValueError('Unsafe or duplicate archive member')
        data[entry.filename] = archive.read(entry)
    if archive.testzip() is not None:
        raise ValueError('Archive CRC failure')
    return data


def write_zip(path, members):
    with zipfile.ZipFile(path, 'w') as archive:
        for name, raw in sorted(members.items()):
            entry = zipfile.ZipInfo(name, (2026, 11, 12, 0, 0, 0))
            entry.create_system = 3
            entry.external_attr = (stat.S_IFREG | 0o644) << 16
            entry.compress_type = zipfile.ZIP_DEFLATED
            archive.writestr(entry, raw, compresslevel=9)
    with zipfile.ZipFile(path) as archive:
        if archive.testzip() is not None or set(archive.namelist()) != set(members):
            raise ValueError('Generated archive inventory failure')
        for name, raw in members.items():
            if archive.read(name) != raw:
                raise ValueError('Generated archive readback failure')


def selected_paths():
    files = {p.relative_to(ROOT).as_posix() for p in (ROOT / 'includes/Connector/Printify').glob('*.php')}
    files |= {'companion/digiforge-printify-connector/' + name for name in ['Plugin.php', 'digiforge-printify-connector.php']}
    files |= {CLIENT, 'includes/POD/PrintifyConnectorReadContract.php', 'docs/printify-connector/STAGE2_PACKAGE_CONTRACT.json', 'bin/package-printify-connector-stage2.py'}
    return files


def verified_sources(source_sha):
    """Consume the committed snapshot, never a later reopened worktree copy."""
    if len(source_sha) != 40 or any(c not in '0123456789abcdef' for c in source_sha):
        raise ValueError('Exact source commit SHA required')
    try:
        if subprocess.check_output(['git', 'cat-file', '-t', source_sha], cwd=ROOT, stderr=subprocess.DEVNULL).strip() != b'commit':
            raise ValueError('Source identity must name a commit')
        raw_tree = subprocess.check_output(['git', 'ls-tree', '-rz', source_sha], cwd=ROOT, stderr=subprocess.DEVNULL)
        entries = {}
        for entry in raw_tree.split(b'\0'):
            if not entry:
                continue
            metadata, raw_name = entry.split(b'\t', 1)
            mode, kind, _ = metadata.split(b' ', 2)
            entries[raw_name.decode()] = (mode, kind)
        selected = selected_paths()
        committed_modules = {name for name in entries if name.startswith('includes/Connector/Printify/') and name.endswith('.php')}
        actual_modules = {name for name in selected if name.startswith('includes/Connector/Printify/')}
        if committed_modules != actual_modules:
            raise ValueError('Committed module inventory differs from worktree')
        snapshot = {}
        for name in sorted(selected):
            if entries.get(name) not in [(b'100644', b'blob'), (b'100755', b'blob')]:
                raise ValueError('Source is missing or is not an ordinary committed file')
            path = ROOT / name
            if path.is_symlink() or not path.resolve().is_relative_to(ROOT.resolve()):
                raise ValueError('Source symlinks are not admitted')
            value = subprocess.check_output(['git', 'show', source_sha + ':' + name], cwd=ROOT, stderr=subprocess.DEVNULL)
            if path.read_bytes() != value:
                raise ValueError('Worktree source does not match named commit: ' + name)
            snapshot[name] = value
        return snapshot
    except (subprocess.CalledProcessError, OSError, UnicodeError) as error:
        raise ValueError('Named source commit cannot be verified') from error


def confined_output(output):
    """Reject existing links, including dangling links, before any package write."""
    absolute = output.absolute()
    for parent in [absolute, *absolute.parents]:
        if parent.is_symlink():
            raise ValueError('Output ancestor is a symlink')
    if output.exists():
        for entry in output.rglob('*'):
            if entry.is_symlink():
                raise ValueError('Output contains a symlink')


def build(baseline_path, output, source_sha):
    raw = baseline_path.read_bytes()
    if len(raw) != BASELINE_SIZE or sha(raw) != BASELINE_SHA256:
        raise ValueError('Approved installed-lineage ZIP digest/size required')
    if len(source_sha) != 40 or any(c not in '0123456789abcdef' for c in source_sha):
        raise ValueError('Exact source SHA required')
    snapshot = verified_sources(source_sha)
    confined_output(output)
    with zipfile.ZipFile(io.BytesIO(raw)) as archive:
        original = read_archive(archive)
    if len(original) != BASELINE_COUNT:
        raise ValueError('Installed member inventory differs')
    contract = json.loads(snapshot['docs/printify-connector/STAGE2_PACKAGE_CONTRACT.json'])
    for name, expected in contract['baseline_preimages'].items():
        if sha(original.get('digiforge/' + name, b'')) != expected:
            raise ValueError('Reviewed preimage mismatch: ' + name)
    source = snapshot[CLIENT]
    if sha(connector_method(source)) != contract['connector_method_sha256']:
        raise ValueError('Unreviewed connector method bytes')
    if sha(source.replace(connector_method(source), b'', 1)) != contract['connector_source_preimage_sha256']:
        raise ValueError('Unreviewed catalog client source delta')
    candidate = dict(original)
    candidate['digiforge/' + CLIENT] = port_client(original['digiforge/' + CLIENT], source)
    candidate['digiforge/digiforge.php'] = port_header(original['digiforge/digiforge.php'])
    added = sorted(name for name in snapshot if name.startswith('includes/Connector/Printify/'))
    added += ['includes/POD/PrintifyConnectorReadContract.php']
    for name in added:
        key = 'digiforge/' + name
        if key in original:
            raise ValueError('Connector module would overwrite installed file')
        candidate[key] = snapshot[name]
    companion = {}
    for name in ['Plugin.php', 'digiforge-printify-connector.php']:
        path = 'digiforge-printify-connector/' + name
        companion[path] = snapshot['companion/' + path]
    # All pre-existing bytes except reversible version metadata and the additive method are retained.
    changed = {name for name in original if candidate[name] != original[name]}
    if changed != {'digiforge/digiforge.php', 'digiforge/' + CLIENT} or not set(original) <= set(candidate):
        raise ValueError('Preservation boundary violated')
    output.mkdir(parents=True, exist_ok=True)
    core_path = output / ('digiforge-' + CANDIDATE_VERSION + '-test-only.zip')
    companion_path = output / 'digiforge-printify-connector-0.1.0-test-only.zip'
    write_zip(core_path, candidate)
    write_zip(companion_path, companion)
    artifacts = [{'filename': p.name, 'size': p.stat().st_size, 'sha256': sha(p.read_bytes())} for p in [core_path, companion_path]]
    files = [{'path': name, 'size': len(value), 'sha256': sha(value), 'baseline_sha256': sha(original[name]) if name in original else None, 'action': 'ADD' if name not in original else 'RETAIN_IDENTICAL' if value == original[name] else 'MODIFY_REVIEWED'} for name, value in sorted(candidate.items())]
    for prefix, members in [('core', candidate), ('companion', companion)]:
        for name, value in members.items():
            destination = output / 'extracted' / prefix / name
            destination.parent.mkdir(parents=True, exist_ok=True)
            destination.write_bytes(value)
        actual = {p.relative_to(output / 'extracted' / prefix).as_posix() for p in (output / 'extracted' / prefix).rglob('*') if p.is_file()}
        if actual != set(members):
            raise ValueError('Unexpected stale extracted member')
    manifest = {'contract': 'DF-PRINTIFY-CONNECTOR-STAGE2-PACKAGE-v1', 'status': 'TEST_ONLY_NOT_INSTALLATION_READY', 'source_sha': source_sha, 'source_verified': True, 'source_files': [{'path': n, 'sha256': sha(v)} for n,v in sorted(snapshot.items())], 'baseline_source_sha': BASELINE_SOURCE, 'baseline_sha256': BASELINE_SHA256, 'composition_is_exact_checkout': False, 'version': CANDIDATE_VERSION, 'installation_authorized': False, 'live_get_authorized': False, 'schema_migration': 'NONE', 'artifacts': artifacts, 'counts': {a: sum(f['action'] == a for f in files) for a in ['RETAIN_IDENTICAL', 'MODIFY_REVIEWED', 'ADD']}, 'files': files, 'companion_files': [{'path': n, 'size': len(v), 'sha256': sha(v)} for n,v in sorted(companion.items())]}
    (output / 'preservation-manifest.json').write_text(json.dumps(manifest, indent=2) + '\n')
    (output / 'SHA256SUMS').write_text(''.join(a['sha256'] + '  ' + a['filename'] + '\n' for a in artifacts))
    return manifest


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--baseline', type=pathlib.Path, required=True)
    parser.add_argument('--output', type=pathlib.Path, required=True)
    args = parser.parse_args()
    head = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=ROOT, text=True).strip()
    result = build(args.baseline, args.output, head)
    print(json.dumps({k:v for k,v in result.items() if k not in ['files', 'companion_files']}, indent=2))


if __name__ == '__main__':
    main()
