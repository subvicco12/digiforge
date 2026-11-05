"""Apply existing gates plus an exact-byte, exact-line Drive sender review."""
import hashlib
import json
import pathlib
import re
import shlex
import subprocess
import sys
import tempfile

root = pathlib.Path(sys.argv[1]).resolve()
workflow = pathlib.Path(__file__).resolve().parents[2] / '.github/workflows/digiforge-foundation-audit.yml'
client = root / 'includes/DigitalFactory/DriveWordPressGoogleHttp.php'
expected = 'b4d7ce286d8ada27278b169213b48e1bd526a754d2bceaaa5eb1db5b4b6c7458'
if hashlib.sha256(client.read_bytes()).hexdigest() != expected:
    raise ValueError('Drive client changed; new digest-specific review required')
section = workflow.read_text().split('      - name: External HTTP client scan\n', 1)[1]
body = section.split('        run: |\n', 1)[1].split('\n      - name:', 1)[0]
script = '\n'.join(line[10:] for line in body.splitlines() if line.startswith('          '))
# Only the single reviewed sender line in the exact pinned client can be admitted.
line = 'includes/DigitalFactory/DriveWordPressGoogleHttp.php:13:        $response=wp_remote_request($url,$args);'
if script.count('digiforge.php includes modules automation admin; then') != 1:
    raise ValueError('Upstream scan changed; audit maintainer reconciliation required')
script = script.replace('digiforge.php includes modules automation admin; then', 'digiforge.php includes modules automation admin | grep -vFx ' + shlex.quote(line) + '; then')
with tempfile.TemporaryDirectory() as folder:
    path = pathlib.Path(folder) / 'http-policy.sh'
    path.write_text(script)
    subprocess.run(['bash', '-e', '-o', 'pipefail', str(path)], cwd=root, check=True)
pattern = re.compile(r'''(?i)(api[_-]?key|secret|password|access[_-]?token|refresh[_-]?token|client[_-]?secret)\s*[:=]\s*["'][^"']{20,}["']''')
findings = []
for path in root.rglob('*.php'):
    for number, text in enumerate(path.read_text().splitlines(), 1):
        if pattern.search(text):
            findings.append({'path': str(path.relative_to(root)), 'line': number})
    if re.search(rb'-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----|AKIA[0-9A-Z]{16}|gh[pousr]_[A-Za-z0-9]{30,}', path.read_bytes()):
        findings.append({'path': str(path.relative_to(root)), 'kind': 'high_confidence_secret'})
if findings:
    raise ValueError('Credential scan findings require review: ' + json.dumps(findings))
print(json.dumps({'http_gate': 'PASS', 'secret_gate': 'PASS', 'drive_client_sha256': expected, 'policy_scope': 'Exact pinned file and single sender line only; Mode-specific approval required; no independent human review implied'}))
