"""Trusted-runner controls. No package code is imported here."""
import json
import hashlib
import os
import pathlib
import subprocess
import sys
import zipfile
from verify import reviewers, approved, owner_attestation


def api(path):
    return json.loads(subprocess.check_output(['gh', 'api', 'repos/subvicco12/digiforge/' + path]))


if os.environ.get('GITHUB_RUN_ATTEMPT') != '1':
    raise ValueError('Fresh dispatch required; prior-attempt approvals cannot be reused')

if sys.argv[1] == 'owner-attestation':
    comment_id, run_id, artifact_id = sys.argv[2:5]
    if not all(value.isdecimal() for value in [comment_id, run_id, artifact_id]):
        raise ValueError('Numeric comment/run/artifact IDs required')
    if os.environ.get('GITHUB_ACTOR') != 'subvicco12' or os.environ.get('GITHUB_EVENT_NAME') != 'workflow_dispatch':
        raise ValueError('Explicit owner manual dispatch required')
    comment = api('issues/comments/' + comment_id)
    if comment['user']['login'] != 'subvicco12' or comment['user']['type'] != 'User' or not comment['issue_url'].endswith('/issues/1253'):
        raise ValueError('Owner-authored attestation on PR1253 required')
    owner_attestation(json.loads(comment['body']), os.environ['GITHUB_SHA'], run_id, artifact_id)
    print(json.dumps({'attestation_comment_id': comment_id, 'comment_url': comment['html_url'], 'comment_body': comment['body'], 'comment_body_sha256': hashlib.sha256(comment['body'].encode()).hexdigest(), 'comment_updated_at': comment.get('updated_at'), 'audit_mode': 'OWNER_ATTESTED_AUTOMATED', 'independent_human_review': False}))
elif sys.argv[1] == 'environments':
    author = api('pulls/1253')['user']['login']
    for name in ['printify-artifact-intake', 'printify-artifact-acceptance']:
        users = reviewers(api('environments/' + name), os.environ['GITHUB_ACTOR'])
        if author in users:
            raise ValueError('PR author cannot be an independent audit reviewer')
    print('Named independent reviewer protections verified; environment approval still required')
elif sys.argv[1] == 'unwrap':
    with zipfile.ZipFile(sys.argv[2]) as archive:
        members = archive.infolist()
        if len(members) != 1 or members[0].filename != 'digiforge-1.0.103.1-printify-update.zip' or members[0].file_size != 3060845:
            raise ValueError('Intake wrapper must contain only the exact plugin ZIP')
        pathlib.Path(sys.argv[3]).write_bytes(archive.read(members[0]))
elif sys.argv[1] == 'intake':
    run, artifact = sys.argv[2:4]
    if not run.isdecimal() or not artifact.isdecimal():
        raise ValueError('Numeric GitHub IDs required')
    meta = api('actions/artifacts/' + artifact)
    if meta['size_in_bytes'] > 16000000 or meta['expired'] or meta['name'] != 'digiforge-printify-update-intake' or str(meta['workflow_run']['id']) != run:
        raise ValueError('Wrong approved intake identity')
    if api('actions/runs/' + run)['conclusion'] != 'success':
        raise ValueError('Intake source run must have completed successfully')
    print(json.dumps({'intake_run_id': run, 'artifact_id': artifact, 'metadata': meta}))
elif sys.argv[1] in ['intake-approval', 'acceptance']:
    author = api('pulls/1253')['user']['login']
    approvals = api('actions/runs/' + os.environ['GITHUB_RUN_ID'] + '/approvals')
    environments = ['printify-artifact-intake'] if sys.argv[1] == 'intake-approval' else ['printify-artifact-intake', 'printify-artifact-acceptance']
    for environment in environments:
        approved(api('environments/' + environment), os.environ['GITHUB_ACTOR'], author, approvals, environment)
    print(json.dumps({'review_acceptance': 'INTAKE_RECORDED' if sys.argv[1] == 'intake-approval' else 'RECORDED', 'approvals': approvals, 'artifact_sha256': 'aba29f64dd0a874635098492c4073012d219a404c676e2b237a346c6495b43e1', 'production_activation_authorized': False}))
else:
    raise ValueError('Unsupported control action')
