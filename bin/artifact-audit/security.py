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
image_review = None
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
# Image admission is an additional exact-source review, never a legacy-policy rewrite.
image_client = root / 'includes/AI/ImageGenerationHttpClient.php'
if image_client.exists() or (root / 'includes/AI/ImageGenerationExecution.php').exists() or (root / 'includes/Portal/ImageGenerationPanel.php').exists():
    image_review = json.loads((policy_dir / 'image-transport-review.json').read_text())
    if image_review.get('version') != 1 or not image_review.get('source_sha256'):
        raise ValueError('Image review manifest invalid')
    for name, digest in image_review['source_sha256'].items():
        relative = pathlib.PurePosixPath(name)
        if relative.is_absolute() or '..' in relative.parts:
            raise ValueError('Image reviewed path invalid')
        path = root.joinpath(*relative.parts)
        parents = [root.joinpath(*relative.parts[:i]) for i in range(1, len(relative.parts) + 1)]
        if (not path.is_file() or any(item.is_symlink() for item in parents)
                or hashlib.sha256(path.read_bytes()).hexdigest() != digest):
            raise ValueError('Image authority source changed; new explicit review required: ' + name)
    sender = image_review['sender']
    if sender.get('path') != 'includes/AI/ImageGenerationHttpClient.php':
        raise ValueError('Image sender path changed')
    matches = [(number, text) for number, text in enumerate(image_client.read_text().splitlines(), 1)
               if 'wp_remote_post(' in text]
    if matches != [(sender['line'], sender['code'])]:
        raise ValueError('Image sender line changed or duplicated')
    line = sender['path'] + ':' + str(sender['line']) + ':' + sender['code']
    original_scan = 'digiforge.php includes modules automation admin'
    # Drive's existing exact-line filter, when present, stays intact.
    if script.count(original_scan) != 1:
        raise ValueError('Legacy scan changed; maintainer reconciliation required')
    script = script.replace(original_scan, original_scan + ' | grep -vFx ' + shlex.quote(line))
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
print(json.dumps({'http_gate': 'PASS', 'secret_gate': 'PASS', 'drive_client_sha256': expected, 'reviewed_drive_present': client.exists(), 'reviewed_image_source_pins': image_review,  'reviewed_composition_pins': pins if client.exists() else {}, 'policy_scope': 'Exact pinned file and single sender line only; Mode-specific approval required; no independent human review implied'}))
