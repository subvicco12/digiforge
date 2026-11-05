"""Fail-closed exact-artifact admission. No package execution or outbound calls."""
import hashlib
import json
import pathlib
import stat
import zipfile

DIGEST = 'aba29f64dd0a874635098492c4073012d219a404c676e2b237a346c6495b43e1'


def safe_name(name):
    path = pathlib.PurePosixPath(name)
    if path.is_absolute() or '..' in path.parts or '\\' in name or not path.parts or path.parts[0] != 'digiforge':
        raise ValueError('Unsafe plugin member')
    return path


def reviewers(config, actor):
    if config.get('can_admins_bypass') is not False:
        raise ValueError('Admin bypass must be explicitly disabled')
    for rule in config.get('protection_rules', []):
        if rule.get('type') == 'required_reviewers' and rule.get('prevent_self_review') is True:
            if any(r.get('type') != 'User' for r in rule.get('reviewers', [])):
                raise ValueError('Only named independent users are supported; no Teams')
            users = [r['reviewer']['login'] for r in rule.get('reviewers', [])]
            if users and actor not in users:
                return users
    raise ValueError('Independent named required reviewers and prevent_self_review required')


def approved(config, actor, author, approvals, name):
    users = reviewers(config, actor)
    if author in users:
        raise ValueError('PR author cannot approve audit')
    accepted = [r['user']['login'] for r in approvals if r.get('state') == 'approved' and r['user']['login'] in users and any(e.get('name') == name for e in r.get('environments', []))]
    if not accepted:
        raise ValueError('Recorded independent named approval required: ' + name)
    return accepted


def owner_attestation(attestation, source_sha, intake_run, intake_artifact):
    expected = {'artifact_sha256': DIGEST, 'audit_source_sha': source_sha,
                'intake_run_id': intake_run, 'intake_artifact_id': intake_artifact,
                'accept_reduced_independence': True, 'approve_controls': True,
                'drive_client_sha256': 'b4d7ce286d8ada27278b169213b48e1bd526a754d2bceaaa5eb1db5b4b6c7458'}
    if attestation != expected or attestation.get('accept_reduced_independence') is not True or attestation.get('approve_controls') is not True:
        raise ValueError('Explicit owner controls/reduced-independence attestation must bind source, digest and intake')
    return True


def verify(source, manifest, output):
    raw = pathlib.Path(source).read_bytes()
    if len(raw) != 3060845 or hashlib.sha256(raw).hexdigest() != DIGEST:
        raise ValueError('Exact ZIP digest/size mismatch before extraction')
    data = json.loads(pathlib.Path(manifest).read_text())
    expected = {f['path']: f for f in data['files']}
    if len(expected) != 691 or data['artifact_sha256'] != DIGEST:
        raise ValueError('Wrong manifest')
    counts = {'RETAIN_IDENTICAL': 0, 'MODIFY_REVIEWED': 0, 'ADD': 0}
    with zipfile.ZipFile(source) as archive:
        infos = archive.infolist()
        if len(infos) != len(expected) or {i.filename for i in infos} != set(expected):
            raise ValueError('Duplicate, missing or unexpected member')
        for info in infos:
            safe_name(info.filename)
            mode = info.external_attr >> 16
            if stat.S_IFMT(mode) != stat.S_IFREG or mode & 0o7111 or info.file_size > 16000000:
                raise ValueError('Unsafe mode/size')
            record = expected[info.filename]
            if info.file_size != record['size'] or hashlib.sha256(archive.read(info)).hexdigest() != record['sha256']:
                raise ValueError('Member mismatch')
            counts[record['action']] += 1
            if record['action'] == 'RETAIN_IDENTICAL' and record['baseline_sha256'] != record['sha256']:
                raise ValueError('Preservation mismatch')
        if counts != {'RETAIN_IDENTICAL': 671, 'MODIFY_REVIEWED': 7, 'ADD': 13}:
            raise ValueError('Preservation counts mismatch')
        if pathlib.Path(output).exists():
            raise ValueError('Extraction target must be new')
        pathlib.Path(output).mkdir(mode=0o700, parents=True)
        archive.extractall(output)
    return {'artifact_sha256': DIGEST, 'counts': counts, 'production_activation_authorized': False}


if __name__ == '__main__':
    import sys
    print(json.dumps(verify(*sys.argv[1:])))
