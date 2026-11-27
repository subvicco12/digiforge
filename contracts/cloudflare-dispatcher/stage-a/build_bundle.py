#!/usr/bin/env python3
"""Build a frozen source candidate only. Never runs Wrangler, npm, fetch or deployment."""
import argparse
import hashlib
import json
import re
import subprocess
import zipfile
from pathlib import Path

PREFIX = 'contracts/cloudflare-dispatcher/'
RUNTIME = ['protocol.mjs', 'sqlite-state.mjs'] + ['stage-a/' + n + '.mjs' for n in ['entrypoint', 'factory', 'egress', 'state', 'suite']]

def digest(data):
    return hashlib.sha256(data).hexdigest()

def build(root, output):
    sha = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=root, text=True).strip()
    paths = subprocess.check_output(['git', 'ls-tree', '-r', '--name-only', sha, PREFIX], cwd=root, text=True).splitlines()
    paths += ['includes/POD/PrintifyAcceptanceContract.php', 'contracts/printify-authority/Authority.php', 'docs/superpowers/plans/2026-11-10-stage-a-factory.md']
    data = {}
    for path in paths:
        committed = subprocess.check_output(['git', 'show', sha + ':' + path], cwd=root)
        if (root / path).read_bytes().replace(b'\r\n', b'\n') != committed.replace(b'\r\n', b'\n'):
            raise ValueError('UNCOMMITTED_SOURCE:' + path)
        data[path] = committed
    for name in RUNTIME:
        if PREFIX + name not in data:
            raise ValueError('MISSING_RUNTIME:' + name)
        text = data[PREFIX + name].decode()
        if re.search(r'\b(?:eval|require|Function)\s*\(|\bimport\s*\(', text):
            raise ValueError('DYNAMIC_CODE_DENIED:' + name)
        for spec in re.findall(r'(?:from\s*|import\s*)[\'\"]([^\'\"]+)[\'\"]', text):
            if spec == 'cloudflare:workers' and name == 'stage-a/entrypoint.mjs':
                continue
            resolved = (Path(name).parent / spec)
            import posixpath
            if not spec.startswith('.') or posixpath.normpath(str(resolved)) not in RUNTIME:
                raise ValueError('IMPORT_DENIED:' + name + ':' + spec)
        if 'api.printify.com' in text or 'cloudflare:sockets' in text:
            raise ValueError('PROVIDER_OR_SOCKET_IMPORT_DENIED')
    config = json.loads(data[PREFIX + 'stage-a/wrangler.template.json'])
    if config['workers_dev'] or config['preview_urls'] or config['routes'] or config['vars']['DF_QUAL_MODE'] != 'DISABLED':
        raise ValueError('PUBLIC_OR_ACTIVE_CONFIGURATION_DENIED')
    runtime_hashes = {n: digest(data[PREFIX + n]) for n in sorted(RUNTIME)}
    build_id = digest(json.dumps(runtime_hashes, sort_keys=True, separators=(',', ':')).encode())
    config['vars']['DF_QUAL_BUILD_ID'] = build_id
    members = {'source/' + p: b for p, b in data.items()}
    for name in RUNTIME:
        members['deployment/' + name] = data[PREFIX + name]
    members['deployment/wrangler.json'] = (json.dumps(config, indent=2) + '\n').encode()
    manifest = {'source_sha': sha, 'type': 'STAGE_A_SYNTHETIC_SOURCE_NOT_WORDPRESS_INSTALLER', 'build_id': build_id,
                'source_sha256': {p: digest(b) for p, b in sorted(data.items())}, 'runtime_sha256': runtime_hashes,
                'member_sha256': {p: digest(b) for p, b in sorted(members.items())},
                'hosted_status': 'HOSTED_NOT_EXECUTED', 'recovery': 'RECOVERY_BLOCKED', 'credentials': 'NONE'}
    members['source-manifest.json'] = (json.dumps(manifest, indent=2, sort_keys=True) + '\n').encode()
    output.parent.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(output, 'w', compression=zipfile.ZIP_STORED) as z:
        for path, content in sorted(members.items()):
            i = zipfile.ZipInfo(path, date_time=(1980, 1, 1, 0, 0, 0))
            i.external_attr = 0o100644 << 16
            i.create_system = 3
            z.writestr(i, content)
    with zipfile.ZipFile(output) as z:
        assert z.testzip() is None
        for path, h in manifest['member_sha256'].items():
            assert digest(z.read(path)) == h
    result = {'source_sha': sha, 'build_id': build_id, 'size': output.stat().st_size, 'sha256': digest(output.read_bytes())}
    output.with_suffix(output.suffix + '.sha256').write_text(result['sha256'] + '  ' + output.name + '\n')
    print(json.dumps(result, sort_keys=True))

if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    repo = Path(__file__).resolve().parents[3]
    build(repo, args.output.resolve())
