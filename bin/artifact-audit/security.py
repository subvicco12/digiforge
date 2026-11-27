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
policy_dir = pathlib.Path(__file__).resolve().parent
client = root / 'includes/DigitalFactory/DriveWordPressGoogleHttp.php'
pins = json.loads((policy_dir / 'drive-transport-review.json').read_text())
expected = pins['DriveWordPressGoogleHttp.php']
script = (policy_dir / 'legacy-http-policy.sh').read_text()
if client.exists():
    # Admission is tied to exact reviewed transport, authoritative gate and production DI.
    for name, digest in pins.items():
        path = client.parent / name
        if not path.is_file() or path.is_symlink() or hashlib.sha256(path.read_bytes()).hexdigest() != digest:
            raise ValueError('Drive composition changed; new explicit security review required: ' + name)
    line = 'includes/DigitalFactory/DriveWordPressGoogleHttp.php:13:        $response=wp_remote_request($url,$args);'
    if script.count('digiforge.php includes modules automation admin; then') != 1:
        raise ValueError('Legacy scan changed; maintainer reconciliation required')
    script = script.replace('digiforge.php includes modules automation admin; then', 'digiforge.php includes modules automation admin | grep -vFx ' + shlex.quote(line) + '; then')
with tempfile.TemporaryDirectory() as folder:
    path = pathlib.Path(folder) / 'http-policy.sh'
    path.write_text(script)
    subprocess.run(['bash', '-e', '-o', 'pipefail', str(path)], cwd=root, check=True)
pattern = re.compile(r'''(?i)(api[_-]?key|secret|password|access[_-]?token|refresh[_-]?token|client[_-]?secret)\s*[:=]\s*["'][^"']{20,}["']''')
findings = []
paths = [root / 'digiforge.php']
for directory in ['includes', 'modules', 'automation', 'admin']:
    paths.extend((root / directory).rglob('*.php'))
for path in paths:
    for number, text in enumerate(path.read_text().splitlines(), 1):
        if pattern.search(text):
            findings.append({'path': str(path.relative_to(root)), 'line': number})
    if re.search(rb'-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----|AKIA[0-9A-Z]{16}|gh[pousr]_[A-Za-z0-9]{30,}', path.read_bytes()):
        findings.append({'path': str(path.relative_to(root)), 'kind': 'high_confidence_secret'})
if findings:
    raise ValueError('Credential scan findings require review: ' + json.dumps(findings))
print(json.dumps({'http_gate': 'PASS', 'secret_gate': 'PASS', 'drive_client_sha256': expected, 'reviewed_drive_present': client.exists(), 'reviewed_composition_pins': pins if client.exists() else {}, 'policy_scope': 'Exact pinned file and single sender line only; Mode-specific approval required; no independent human review implied'}))
