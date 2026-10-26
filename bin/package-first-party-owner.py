#!/usr/bin/env python3
"""Build/verify an exact three-file OWNER source overlay. Never installs or authorizes it."""
import argparse
import hashlib
import io
import json
from pathlib import Path
import re
import subprocess
import zipfile

ROOT = Path(__file__).resolve().parents[1]
SOURCE_COMMIT = '007da8b9d563567e5811f8ceb43b8732b48a5558'
SOURCE_FILES = (
    {
        'path': 'includes/Security/FirstPartyOwnerPolicy.php',
        'sha256': '9b308fb2f1b0b6f01dcd42cdc3ba7813a4c26c2aeaeda080b6fdce70b3739416',
    },
    {
        'path': 'includes/Security/ShopAccessAuthority.php',
        'sha256': '075c431a3106bdd0d3e4e6979fe7ea858f147c24c356ce34fb4cd770c03424e0',
    },
    {
        'path': 'includes/Portal/ShopAccessPanel.php',
        'sha256': '171d9e97d250c987332bb6e72156a1d9fd0f453942de9b630ff0e2fc08124105',
    },
)
SOURCE_HASHES = {row['path']: row['sha256'] for row in SOURCE_FILES}
PAYLOADS = tuple(SOURCE_HASHES)
DEPENDENCIES = (
    'digiforge.php', 'includes/Core/Plugin.php', 'includes/Core/Capabilities.php',
    'includes/Security/ShopAccess.php', 'includes/Security/ShopAccessPolicy.php',
    'includes/Security/ShopAccessRepository.php', 'includes/Security/ShopAccessAdminGuard.php',
    'includes/Security/Logger.php', 'includes/Database/Tables.php',
    'includes/Portal/ReviewConfirmation.php', 'includes/Orders/Validator.php',
    'includes/REST/ShopAccessController.php', 'includes/Portal/Portal.php',
)
LIMIT = 1048576
INSTRUCTIONS = b'''# OWNER source overlay: REVIEW ONLY, DEPLOYMENT BLOCKED

This is NOT a standalone WordPress plugin or cumulative DigiForge release.
No installer, credential, wp-config.php, grant, database migration or activation hook is included.
Do not upload it through WordPress's plugin installer or extract it over a live site.
The three payload paths are relative to the existing digiforge plugin root.
Current committed payload/dependency drift refuses packaging; dirty checkout bytes
are never packaged. Source updates require independently reviewed new source pins.

ADD FirstPartyOwnerPolicy.php; REPLACE ShopAccessAuthority.php and ShopAccessPanel.php
only after separately authorized exact-scope installation and complete prerequisites.
The manifest lists existing dependency hashes for review; their code is NOT shipped here.
These reference hashes are NOT verified production hashes or complete installed dependency proof.

Prerequisites: fresh exact raw file preimages/hashes, proven prior absence of the new
file, complete boot/portal dependency comparison, compatible 1.0.103 architecture,
WordPress/PHP support, genuine user1 mailbox verification, privately verified account
and installation pin, protected configuration custody and legacy OWNER/DELEGATE review,
matched protected database/artifact checkpoint and authorized isolated restore evidence.
Masked/line-numbered connector output is not a rollback preimage.

Protected DIGIFORGE_FIRST_PARTY_OWNER has exactly version=integer1, account_sha256,
installation_sha256, genuine email_verified_at and bounded expires_at (at most365days).
Never generate these from profile email alone; do not export passwords, user_pass,
reset links, tokens or keys. Resetting a password invalidates an existing pin.
Preserve current-role/all15-capability checks, explicit enrollment, nonce/confirmation,
approval hash, revision/CAS/replay and immutable audits. No reads create grants.

Rollback requirements (NOT EXECUTED): freeze writes; retain exact two-file preimages
and protected authority/configuration state. Restore the coherent approved preimage set;
remove the added policy only if its prior absence is proven. Do not resurrect retired
signed OWNER authority or discard new audit/revocation history with a blind DB restore.
If grants/writes occurred, stop for separately reviewed history-preserving recovery.
No automatic rollback, retention, erasure or cleanup is provided.

STOP ALL ON; external lock ON; automation OFF; Finance operationally excluded;
Standard POD/Jewelry inactive; RELEASE HOLD / NO-GO. Architecture scopes are not activation.
No merge, deployment, password reset, protected configuration write, OWNER grant,
provider operation, publishing, order or payment is authorized by this package.
'''


def _git(*args):
    try:
        return subprocess.run(['git', '-C', str(ROOT), *args], check=True,
                              stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                              timeout=15).stdout
    except (subprocess.SubprocessError, OSError):
        raise ValueError('Pinned Git source unavailable; no checkout fallback') from None


def _source(path, commit):
    entry = _git('ls-tree', commit, '--', path).decode().split('\t', 1)
    if len(entry) != 2 or not entry[0].startswith('100644 blob ') or entry[1].rstrip('\n') != path:
        raise ValueError('Regular pinned Git blob required')
    data = _git('cat-file', 'blob', commit + ':' + path)
    if not data or len(data) > LIMIT:
        raise ValueError('Bounded pinned source required')
    return data


def _json(value):
    return (json.dumps(value, sort_keys=True, indent=2, ensure_ascii=False) + '\n').encode()


def build():
    head = _git('rev-parse', '--verify', 'HEAD').decode().strip()
    if re.fullmatch('[a-f0-9]{40}', head) is None:
        raise ValueError('Exact current commit required')
    files = {path: _source(path, SOURCE_COMMIT) for path in PAYLOADS}
    for path, data in files.items():
        if hashlib.sha256(data).hexdigest() != SOURCE_HASHES[path]:
            raise ValueError('OWNER source changed; independent scope review required')
    dependencies = {p: _source(p, SOURCE_COMMIT) for p in DEPENDENCIES}
    for path, data in {**files, **dependencies}.items():
        if _source(path, head) != data:
            raise ValueError('Current committed OWNER dependency drift; review new source pins')
    declaration = re.search(rb'public const ALL\s*=\s*\[([^\]]+)\]', dependencies['includes/Core/Capabilities.php'])
    caps = re.findall(rb"'([a-z_]+)'", declaration[1]) if declaration else []
    if len(caps) != 15 or len(set(caps)) != 15:
        raise ValueError('Existing administrative capability contract changed')
    manifest = {
        'schema': 'digiforge-first-party-owner-source-overlay-v1',
        'source_commit': SOURCE_COMMIT,
        'source_reference_audit': 'https://github.com/subvicco12/digiforge/actions/runs/40267811278',
        'state': 'REVIEW_ONLY_DEPLOYMENT_BLOCKED', 'standalone_plugin': False,
        'files': [{'path': p, 'sha256': SOURCE_HASHES[p], 'bytes': len(files[p]),
                   'operation': 'ADD' if p == PAYLOADS[0] else 'REPLACE',
                   'raw_installed_preimage_sha256': None} for p in PAYLOADS],
        'existing_runtime_dependency_references_not_payload': [
            {'path': p, 'sha256': hashlib.sha256(data).hexdigest(), 'bytes': len(data)}
            for p, data in dependencies.items()],
        'installed_dependency_closure_verified': False,
        'current_committed_dependencies_must_match_source': True,
        'raw_preimages_verified': False, 'rollback_tested': False,
        'mailbox_possession_verified': False, 'deployment_authorized': False,
        'protected_configuration_included': False, 'grants_included': False,
        'migrations_included': False, 'provider_authority_granted': False,
        'architecture_shops': ['digital', 'personalized_pod', 'standard_pod', 'jewelry'],
        'required_administrative_capabilities': [c.decode() for c in caps],
        'shop_activation_authorized': False, 'finance_operationally_excluded': True,
        'stop_all': True, 'external_lock': True, 'automation': False, 'release_hold': True,
    }
    files['MANIFEST.json'] = _json(manifest)
    files['INSTALL-REVIEW.md'] = INSTRUCTIONS
    files['SHA256SUMS'] = ''.join(hashlib.sha256(data).hexdigest() + '  ' + path + '\n'
                                for path, data in sorted(files.items())).encode()
    output = io.BytesIO()
    with zipfile.ZipFile(output, 'w', compression=zipfile.ZIP_STORED) as archive:
        for path, data in sorted(files.items()):
            entry = zipfile.ZipInfo(path, date_time=(1980, 1, 1, 0, 0, 0))
            entry.create_system = 3
            entry.external_attr = 0o100644 << 16
            archive.writestr(entry, data)
    return output.getvalue(), manifest


def verify(blob):
    if not isinstance(blob, bytes) or not blob or len(blob) > LIMIT:
        raise ValueError('Bounded narrow OWNER archive required')
    expected_names = set(PAYLOADS) | {'MANIFEST.json', 'INSTALL-REVIEW.md', 'SHA256SUMS'}
    try:
        with zipfile.ZipFile(io.BytesIO(blob)) as archive:
            items = archive.infolist()
            if len(items) != len(expected_names) or {i.filename for i in items} != expected_names:
                raise ValueError('Extra, missing, duplicate or cumulative package members')
            if any(i.external_attr >> 16 != 0o100644 or i.flag_bits & 1 for i in items):
                raise ValueError('Only unencrypted regular source files permitted')
            if sum(i.file_size for i in items) > LIMIT or any(i.compress_type != zipfile.ZIP_STORED for i in items):
                raise ValueError('Unsupported archive framing or expanded size')
            if archive.testzip() is not None:
                raise ValueError('Corrupt OWNER archive')
    except (zipfile.BadZipFile, RuntimeError, OSError):
        raise ValueError('Invalid OWNER archive') from None
    expected, manifest = build()
    if blob != expected:
        raise ValueError('Payload, manifest, checksums or framing differs from pinned source')
    return manifest


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument('--output', type=Path, help='New local review .zip; never overwrites')
    mode.add_argument('--verify', type=Path, help='Validate only; never extracts or installs')
    args = parser.parse_args()
    try:
        if args.verify:
            with args.verify.open('rb') as source:
                manifest = verify(source.read(LIMIT + 1))
        else:
            if args.output.suffix != '.zip':
                raise ValueError('Local .zip output required')
            blob, manifest = build()
            verify(blob)
            with args.output.open('xb') as target:
                if target.write(blob) != len(blob):
                    raise ValueError('Incomplete local package write')
        print(json.dumps({'state': manifest['state'], 'source_commit': SOURCE_COMMIT,
                          'deployment_authorized': False, 'rollback_tested': False}))
    except (ValueError, OSError) as error:
        parser.exit(1, 'OWNER package unavailable: ' + str(error) + '\n')


if __name__ == '__main__':
    main()
