#!/usr/bin/env python3
"""Fail-closed candidate-history credential gate; never publish finding contents."""
import argparse
import collections
import json
import os
from pathlib import Path
import subprocess
import tempfile

VERSION = '8.30.1'


def check(repository, scanner):
    evidence = {'schema': 'digiforge-repository-credential-evidence-v1',
                'status': 'SCAN_UNAVAILABLE', 'scanner_version': VERSION,
                'commit_sha': None, 'reachable_commits': None, 'finding_count': None,
                'scope': 'candidate_HEAD_reachable_history_default_rules',
                'other_refs_certified': False, 'external_actions_performed': False}

    def git(*args):
        return subprocess.run(['git', '-C', str(repository), *args], check=True,
                              capture_output=True, text=True, timeout=30).stdout.strip()

    try:
        evidence['commit_sha'] = git('rev-parse', '--verify', 'HEAD^{commit}')
        if git('rev-parse', '--is-shallow-repository') != 'false':
            evidence['status'] = 'HISTORY_INCOMPLETE'
            return evidence
        if git('status', '--porcelain', '--untracked-files=no'):
            evidence['status'] = 'TRACKED_TREE_CHANGED'
            return evidence
        evidence['reachable_commits'] = int(git('rev-list', '--count', 'HEAD'))
        version = subprocess.run([scanner, 'version'], check=True, capture_output=True,
                                 text=True, timeout=10).stdout.strip()
        if version != VERSION:
            return evidence
        with tempfile.TemporaryDirectory(prefix='df-credential-scan-') as temp:
            root = Path(temp)
            # Explicit embedded default rules: repository config, ignore files and
            # inline allow directives must not suppress this mandatory gate.
            config = root / 'rules.toml'
            config.write_text('[extend]\nuseDefault = true\n')
            ignore = root / 'empty.ignore'
            ignore.write_text('')
            report = root / 'redacted-findings.json'
            # Gitleaks also reads source/.gitleaksignore even with an explicit
            # ignore path. Scan the real Git history from an empty worktree so
            # repository-controlled suppression files cannot participate.
            worktree = root / 'empty-worktree'
            worktree.mkdir()
            environment = os.environ.copy()
            environment.pop('GIT_COMMON_DIR', None)
            environment['GIT_DIR'] = git('rev-parse', '--absolute-git-dir')
            environment['GIT_WORK_TREE'] = str(worktree)
            result = subprocess.run([scanner, 'git', '--no-banner', '--redact=100',
                                     '--ignore-gitleaks-allow', '--config=' + str(config),
                                     '--gitleaks-ignore-path=' + str(ignore),
                                     '--report-format=json', '--report-path=' + str(report),
                                     '--log-opts=HEAD', str(worktree)],
                                    capture_output=True, timeout=240, env=environment)
            if result.returncode not in (0, 1) or not report.is_file():
                return evidence
            findings = json.loads(report.read_text())
            if not isinstance(findings, list) or any(not isinstance(row, dict) for row in findings):
                return evidence
            if (result.returncode == 0) != (len(findings) == 0):
                return evidence
            evidence['finding_count'] = len(findings)
            evidence['rules_with_findings'] = dict(collections.Counter(
                str(row.get('RuleID', 'unknown')) for row in findings))
            evidence['status'] = 'PASS' if not findings else 'FINDINGS_REVIEW_REQUIRED'
        # Bind to the same clean candidate after the scan, not a concurrent edit.
        if git('rev-parse', '--verify', 'HEAD^{commit}') != evidence['commit_sha'] or git('status', '--porcelain', '--untracked-files=no'):
            evidence['status'] = 'TRACKED_TREE_CHANGED'
    except (OSError, ValueError, subprocess.SubprocessError):
        evidence['status'] = 'SCAN_UNAVAILABLE'
    return evidence


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--scanner', required=True)
    parser.add_argument('--repository', default='.')
    parser.add_argument('--report', required=True)
    args = parser.parse_args()
    evidence = check(Path(args.repository).resolve(), args.scanner)
    Path(args.report).write_text(json.dumps(evidence, indent=2, sort_keys=True) + '\n')
    print(json.dumps(evidence, sort_keys=True))
    return 0 if evidence['status'] == 'PASS' else 1


if __name__ == '__main__':
    raise SystemExit(main())
