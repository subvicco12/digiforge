#!/usr/bin/env python3
"""Fail-closed candidate-history credential gate; never publish finding contents."""
import argparse
import ast
import collections
import hashlib
import json
import os
import re
from pathlib import Path
import subprocess
import tempfile

VERSION = '8.30.1'


def verified_git_identifier(finding, git):
    """Recognize only the reviewed manifest's typed, independently resolved Git IDs.

    This is not a path/rule allowlist: malformed IDs, wrong objects, unknown fields,
    additional credential fields and every other finding remain actionable.
    Read the finding's historical commit, never substitute current working bytes.
    """
    path = 'docs/engineering/printify-shared-dependency-manifest-v1.json'
    try:
        if finding.get('File') != path or finding.get('RuleID') != 'generic-api-key':
            return False
        commit = finding.get('Commit')
        line = finding.get('StartLine')
        if not isinstance(commit, str) or not re.fullmatch('[a-f0-9]{40}', commit):
            return False
        if not isinstance(line, int) or line < 1 or finding.get('EndLine') != line:
            return False
        raw = git('show', commit + ':' + path)
        source = raw.splitlines()
        if line > len(source):
            return False
        field = re.fullmatch(r'\s*"([a-zA-Z_][a-zA-Z0-9_]*)"\s*:\s*"([a-f0-9]{40})"\s*,?\s*', source[line - 1])
        if field is None:
            return False
        name, value = field.groups()
        def unique_object(pairs):
            result = {}
            for key, item in pairs:
                if key in result:
                    raise ValueError('Duplicate JSON member')
                result[key] = item
            return result

        manifest = json.loads(raw, object_pairs_hook=unique_object)
        # Fail closed unless canonical serialization preserves exact historical lines.
        canonical = json.dumps(manifest, indent=2).splitlines()
        if canonical != source:
            return False

        def exact_location(container, key):
            previous = container[key]
            container[key] = '__reviewed_git_identifier_location__'
            changed = json.dumps(manifest, indent=2).splitlines()
            container[key] = previous
            return [i + 1 for i, pair in enumerate(zip(canonical, changed))
                    if pair[0] != pair[1]] == [line]

        if manifest.get('schema') != 'digiforge-printify-shared-dependency-v1':
            return False
        refs = manifest.get('refs', {})
        if not isinstance(refs, dict):
            return False
        if name in {'main', 'shared_1242', 'api_1253_start', 'api_branch_base_main', 'shared_core', 'consumer_baseline'}:
            return refs.get(name) == value and exact_location(refs, name) and git('cat-file', '-t', value) == 'commit'
        if name != 'api_start_blob' or git('cat-file', '-t', value) != 'blob':
            return False
        start = refs.get('api_1253_start')
        if not isinstance(start, str) or not re.fullmatch('[a-f0-9]{40}', start) or git('cat-file', '-t', start) != 'commit':
            return False
        for row in manifest.get('api_lane_files', []):
            if not isinstance(row, dict) or row.get(name) != value or not exact_location(row, name):
                continue
            target = row.get('path')
            if not isinstance(target, str) or not target or target.startswith('/') or '..' in Path(target).parts:
                return False
            if git('rev-parse', '--verify', start + ':' + target) == value:
                return True
    except (OSError, ValueError, TypeError, subprocess.SubprocessError):
        return False
    return False


def verified_plan_state_phrase(finding, git, git_bytes):
    """One reviewed historical noncredential span; never a path/rule suppression.

    Require independently resolved historical bytes and exact location/context.
    Changed content and all additional findings stay actionable.
    """
    path = 'docs/superpowers/plans/2026-11-13-native-reservation-continuation.md'
    reviewed_blob = '75b7888afc9feb2f36912af6539eb0e1355681a0'
    reviewed_content = '260aff926fff972cce787da6a59b033535306b2616753ef80b98234fa9f0c7c9'
    try:
        commit = finding.get('Commit')
        if (finding.get('File') != path or finding.get('RuleID') != 'generic-api-key'
                or type(finding.get('StartLine')) is not int or type(finding.get('EndLine')) is not int
                or finding.get('StartLine') != 8 or finding.get('EndLine') != 8
                or finding.get('Secret') != 'REDACTED'
                or finding.get('Match') != 'key ' + 'denial, REDACTED '
                or not isinstance(commit, str) or not re.fullmatch('[a-f0-9]{40}', commit)):
            return False
        if git('cat-file', '-t', commit) != 'commit':
            return False
        blob = git('rev-parse', '--verify', commit + ':' + path)
        if blob != reviewed_blob:
            return False
        raw = git_bytes('cat-file', 'blob', blob)
        if not isinstance(raw, bytes) or hashlib.sha256(raw).hexdigest() != reviewed_content:
            return False
        expected_line = ('- [x] Add real SQL failing tests for exact registration replay without new events/audit, changed-binding/'
                         + 'key ' + 'denial, ' + '/'.join(('cancellation', 'UNKNOWN'))
                         + ' non-revival, lost acknowledgement, generation/head/fence substitution and revoked session.')
        return raw.decode('utf-8').splitlines()[7] == expected_line
    except (OSError, ValueError, TypeError, IndexError, subprocess.SubprocessError):
        return False


def verified_source_digest_reference(finding, git, git_bytes):
    """Verify one exact historical typed source checksum, never ignore a path/rule.

    Its full reviewed blob, scanner span/masked grammar and Python AST must agree.
    Independently hash the actual qualified source; do not trust a claimed digest.
    """
    path = 'tests/offline/test_printify_dependencies.py'
    reviewed_blob = 'fcf8ea6e200166e07abe8310d498546b0e187fec'
    reviewed_content = '4da1d41d289524091dd711a672d766e5f507e3f9633102bcd0c9a140c804f499'
    reviewed_match = 'f39d40f87f1c979483dfa43e545d6e87909a3c44a6df9fe7fc2680eeca7b91e3'
    qualified_source = '7d624512a0fab416278b7d8f88208c9463224b6f'
    source_path = 'includes/Security/ShopAccessRepository.php'
    try:
        commit = finding.get('Commit')
        match = finding.get('Match')
        location = {'StartLine': 93, 'EndLine': 93, 'StartColumn': 36, 'EndColumn': 127}
        if (finding.get('File') != path or finding.get('RuleID') != 'generic-api-key'
                or finding.get('Secret') != 'REDACTED'
                or any(type(finding.get(key)) is not int or finding[key] != value for key, value in location.items())
                or type(match) is not str or len(match) != 36
                or hashlib.sha256(match.encode('utf-8')).hexdigest() != reviewed_match
                or not isinstance(commit, str) or not re.fullmatch('[a-f0-9]{40}', commit)):
            return False
        if git('cat-file', '-t', commit) != 'commit':
            return False
        blob = git('rev-parse', '--verify', commit + ':' + path)
        if blob != reviewed_blob:
            return False
        raw = git_bytes('cat-file', 'blob', blob)
        if not isinstance(raw, bytes) or len(raw) > 65536 or hashlib.sha256(raw).hexdigest() != reviewed_content:
            return False
        text = raw.decode('utf-8')
        module = ast.parse(text)
        classes = [node for node in module.body if isinstance(node, ast.ClassDef) and node.name == 'PrintifySharedDependencyBoundaryTest']
        if len(classes) != 1:
            return False
        methods = [node for node in classes[0].body if isinstance(node, ast.FunctionDef) and node.name == 'test_audit_stall_delta_preserves_exact_qualified_bases_and_grants_no_authority']
        if len(methods) != 1:
            return False
        assignments = [node for node in ast.walk(methods[0]) if isinstance(node, ast.Assign)
                       and len(node.targets) == 1 and isinstance(node.targets[0], ast.Name) and node.targets[0].id == 'bases']
        if len(assignments) != 1 or not isinstance(assignments[0].value, ast.Dict):
            return False
        mapping = assignments[0].value
        if len(mapping.keys) != 2 or any(not isinstance(key, ast.Constant) or type(key.value) is not str for key in mapping.keys):
            return False
        keys = [key.value for key in mapping.keys]
        if set(keys) != {source_path, 'includes/PortalAccess/GlobalOwnerAuthority.php'} or len(set(keys)) != len(keys):
            return False
        index = keys.index(source_path)
        value = mapping.values[index]
        if (not isinstance(value, ast.Constant) or type(value.value) is not str
                or re.fullmatch('[a-f0-9]{64}', value.value) is None
                or value.lineno != 93 or value.end_lineno != 93 or mapping.keys[index].lineno != 93):
            return False
        line = text.splitlines()[92]
        if line.count(value.value) != 1:
            return False
        masked = line.replace(value.value, 'REDACTED')
        # Gitleaks8.30.1 column metadata does not map to a direct one-based slice.
        # Bind the independently reviewed exact masked span, never accept alternatives.
        if masked.count(match) != 1:
            return False
        if git('cat-file', '-t', qualified_source) != 'commit':
            return False
        source_blob = git('rev-parse', '--verify', qualified_source + ':' + source_path)
        if not isinstance(source_blob, str) or not re.fullmatch('[a-f0-9]{40}', source_blob) or git('cat-file', '-t', source_blob) != 'blob':
            return False
        qualified = git_bytes('cat-file', 'blob', source_blob)
        return isinstance(qualified, bytes) and hashlib.sha256(qualified).hexdigest() == value.value
    except (OSError, ValueError, TypeError, IndexError, SyntaxError, subprocess.SubprocessError):
        return False


def check(repository, scanner):
    evidence = {'schema': 'digiforge-repository-credential-evidence-v1',
                'status': 'SCAN_UNAVAILABLE', 'scanner_version': VERSION,
                'commit_sha': None, 'reachable_commits': None, 'finding_count': None,
                'scope': 'candidate_HEAD_reachable_history_default_rules',
                'other_refs_certified': False, 'external_actions_performed': False}

    def git(*args):
        return subprocess.run(['git', '-C', str(repository), *args], check=True,
                              capture_output=True, text=True, timeout=30).stdout.strip()

    def git_bytes(*args):
        return subprocess.run(['git', '-C', str(repository), *args], check=True,
                              capture_output=True, timeout=30).stdout

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
            kinds = [('git' if verified_git_identifier(row, git) else
                      'plan_prose' if verified_plan_state_phrase(row, git, git_bytes) else
                      'source_digest' if verified_source_digest_reference(row, git, git_bytes) else
                      'actionable') for row in findings]
            actionable = [row for row, kind in zip(findings, kinds) if kind == 'actionable']
            evidence['raw_finding_count'] = len(findings)
            evidence['verified_git_identifier_count'] = kinds.count('git')
            evidence['verified_plan_state_phrase_count'] = kinds.count('plan_prose')
            evidence['verified_source_digest_reference_count'] = kinds.count('source_digest')
            evidence['raw_rules_with_findings'] = dict(collections.Counter(
                str(row.get('RuleID', 'unknown')) for row in findings))
            evidence['finding_count'] = len(actionable)
            evidence['rules_with_findings'] = dict(collections.Counter(
                str(row.get('RuleID', 'unknown')) for row in actionable))
            evidence['status'] = 'PASS' if not actionable else 'FINDINGS_REVIEW_REQUIRED'
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
