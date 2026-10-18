"""Read PyPI's actual published advisory list for each exact offline processor pin."""
import json
import pathlib
import urllib.request
import sys

if sys.argv[1:] not in ([], ['--sam-matting']):
    raise SystemExit('Only --sam-matting is supported')
name = 'requirements-sam-matting.txt' if sys.argv[1:] else 'requirements.txt'
requirements = pathlib.Path(__file__).resolve().parents[1] / 'includes/POD/offline' / name
failed = False
for line in requirements.read_text().splitlines():
    if not line or line.startswith('#') or line.startswith('--extra-index-url '):
        continue
    package, version = line.split('==')
    # Local +cpu wheel tags share the upstream release advisory list.
    version = version.split('+')[0]
    with urllib.request.urlopen(f'https://pypi.org/pypi/{package}/{version}/json', timeout=20) as response:
        record = json.load(response)
    advisories = record['vulnerabilities']
    print(f'{package}=={version}: {len(advisories)} published PyPI advisories')
    for advisory in advisories:
        print(advisory['id'], advisory.get('link'))
    failed = failed or bool(advisories)
if failed:
    raise SystemExit('Offline processor dependency advisory audit failed')
