"""Build only the reviewed installed-lineage schema correction; never deploy it."""
import argparse
import hashlib
import json
import pathlib
import stat
import subprocess
import zipfile

BASELINE = '2f651b5ad7ada503267256e2ba4ef1ef1000e706a1e4e4a13a20082784eb086e'
PARENT = 'aba29f64dd0a874635098492c4073012d219a404c676e2b237a346c6495b43e1'
OVERLAYS = ['includes/Database/ComponentSchema.php', 'includes/Database/ScopedPolicySchema.php', 'includes/Database/CatalogAcceptanceSchema.php']
ROOT = pathlib.Path(__file__).resolve().parent.parent


def digest(raw):
    return hashlib.sha256(raw).hexdigest()


def read_archive(path, expected):
    raw = path.read_bytes()
    if digest(raw) != expected:
        raise ValueError('Input archive digest mismatch')
    with zipfile.ZipFile(path) as archive:
        if archive.testzip() is not None:
            raise ValueError('Archive integrity failure')
        result = {}
        for info in archive.infolist():
            name = pathlib.PurePosixPath(info.filename)
            if name.is_absolute() or '..' in name.parts or '\\' in info.filename or stat.S_ISLNK(info.external_attr >> 16):
                raise ValueError('Unsafe member')
            if info.is_dir():
                continue
            # Owner baseline has no enclosing directory; the previous update has one.
            rel = info.filename.removeprefix('digiforge/')
            if rel in result:
                raise ValueError('Duplicate member')
            result[rel] = archive.read(info)
        return result


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--baseline', type=pathlib.Path, required=True)
    parser.add_argument('--parent', type=pathlib.Path, required=True)
    parser.add_argument('--output', type=pathlib.Path, required=True)
    args = parser.parse_args()
    baseline = read_archive(args.baseline, BASELINE)
    payload = read_archive(args.parent, PARENT)
    if len(baseline) != 678 or len(payload) != 691 or not baseline.keys() <= payload.keys():
        raise ValueError('Unexpected installed lineage')
    for rel in OVERLAYS:
        payload[rel] = (ROOT / rel).read_bytes()
    header = payload['digiforge.php']
    if header.count(b'1.0.103.1') != 2:
        raise ValueError('Unexpected plugin identity/version')
    payload['digiforge.php'] = header.replace(b'1.0.103.1', b'1.0.103.2')
    source = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=ROOT, text=True).strip()
    for rel in OVERLAYS + ['bin/package-printify-schema-candidate.py']:
        if subprocess.check_output(['git', 'show', source + ':' + rel], cwd=ROOT) != (ROOT / rel).read_bytes():
            raise ValueError('Runtime overlay or builder differs from recorded source commit')
    records = []
    for rel, raw in sorted(payload.items()):
        before = baseline.get(rel)
        records.append({'path': 'digiforge/' + rel, 'sha256': digest(raw), 'size': len(raw),
                        'baseline_sha256': digest(before) if before is not None else None,
                        'action': 'ADD' if before is None else 'RETAIN_IDENTICAL' if raw == before else 'MODIFY_REVIEWED'})
    counts = {action: sum(r['action'] == action for r in records) for action in ['RETAIN_IDENTICAL', 'MODIFY_REVIEWED', 'ADD']}
    if counts != {'RETAIN_IDENTICAL': 669, 'MODIFY_REVIEWED': 9, 'ADD': 14}:
        raise ValueError('Unapproved preservation delta')
    args.output.mkdir(parents=True, exist_ok=True)
    target = args.output / 'digiforge-1.0.103.2-printify-update.zip'
    with zipfile.ZipFile(target, 'w') as archive:
        for record in records:
            info = zipfile.ZipInfo(record['path'], (2026, 11, 6, 0, 0, 0))
            info.create_system = 3
            info.external_attr = (stat.S_IFREG | 0o644) << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            archive.writestr(info, payload[record['path'].removeprefix('digiforge/')], compresslevel=9)
    with zipfile.ZipFile(target) as archive:
        assert archive.testzip() is None
        assert len(archive.infolist()) == len(records)
        for record in records:
            assert digest(archive.read(record['path'])) == record['sha256']
    manifest = {'artifact': target.name, 'artifact_sha256': digest(target.read_bytes()), 'artifact_size_bytes': target.stat().st_size,
                'version': '1.0.103.2', 'plugin_identity': 'digiforge/digiforge.php', 'baseline_sha256': BASELINE,
                'parent_zip_sha256': PARENT, 'feature_source_sha': '602c3b048a8dddf2df2aaca852520ff39f7f9d43',
                'correction_and_builder_source_sha': source, 'composition_is_exact_git_checkout': False,
                'counts': {**counts, 'DELETE': 0}, 'installation_authorized': False, 'files': records}
    (args.output / 'preservation-manifest.json').write_text(json.dumps(manifest, indent=2) + '\n')
    (args.output / (target.name + '.sha256')).write_text(manifest['artifact_sha256'] + '  ' + target.name + '\n')
    print(json.dumps({k: v for k, v in manifest.items() if k != 'files'}, indent=2))


if __name__ == '__main__':
    main()
