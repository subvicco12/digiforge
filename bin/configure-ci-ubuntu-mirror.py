#!/usr/bin/env python3
"""Use official HTTPS Ubuntu archives in disposable GitHub Ubuntu runners.

Only transport URIs change; suites, components, signing keys and APT verification
remain untouched. No retry, timeout, package, test or repository-trust exceptions.
"""
import os
from pathlib import Path

REPLACEMENTS = {
    'mirror+file:/etc/apt/apt-mirrors.txt': 'https://archive.ubuntu.com/ubuntu',
    'mirror+file:/etc/apt/apt-security-mirrors.txt': 'https://security.ubuntu.com/ubuntu',
    'http://azure.archive.ubuntu.com/ubuntu': 'https://archive.ubuntu.com/ubuntu',
    'https://azure.archive.ubuntu.com/ubuntu': 'https://archive.ubuntu.com/ubuntu',
}


def rewrite(source):
    for original, replacement in REPLACEMENTS.items():
        source = source.replace(original, replacement)
    return source


def configure(root):
    found = False
    for relative in ('sources.list', 'sources.list.d/ubuntu.sources'):
        path = root / relative
        if not path.is_file():
            continue
        original = path.read_text(encoding='utf-8')
        updated = rewrite(original)
        if 'https://archive.ubuntu.com/ubuntu' in updated:
            found = True
        if updated != original:
            path.write_text(updated, encoding='utf-8')
    if not found:
        raise RuntimeError('Expected Ubuntu archive source unavailable; refusing CI setup')


if __name__ == '__main__':
    if os.environ.get('GITHUB_ACTIONS') != 'true' or os.environ.get('RUNNER_OS') != 'Linux':
        raise SystemExit('Disposable GitHub Linux runner required')
    if 'ID=ubuntu' not in Path('/etc/os-release').read_text().splitlines():
        raise SystemExit('Ubuntu runner required')
    configure(Path('/etc/apt'))
    print('Official HTTPS Ubuntu archives selected; APT trust configuration unchanged')
