"""Read PyPI's actual published advisory list for each exact offline processor pin."""
import json
import pathlib
import urllib.request

requirements = pathlib.Path(__file__).resolve().parents[1] / 'includes/POD/offline/requirements.txt'
failed = False
for line in requirements.read_text().splitlines():
    if not line or line.startswith('#'):
        continue
    package, version = line.split('==')
    with urllib.request.urlopen(f'https://pypi.org/pypi/{package}/{version}/json', timeout=20) as response:
        record = json.load(response)
    advisories = record['vulnerabilities']
    print(f'{package}=={version}: {len(advisories)} published PyPI advisories')
    for advisory in advisories:
        print(advisory['id'], advisory.get('link'))
    failed = failed or bool(advisories)
if failed:
    raise SystemExit('Offline processor dependency advisory audit failed')
