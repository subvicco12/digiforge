#!/usr/bin/env python3
"""Read-only validation of bounded acceptance, provenance and denominator limits.

Reference existence is never a substitute for test execution or final acceptance.
Only committed source indexes are read; originals and raw ledgers are not rebuilt.
"""
import csv
import json
from pathlib import Path
import re
import subprocess
from collections import Counter

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / 'docs/engineering/original-source'
MAIN = '844107ab9baf7e1303cd47708e9eab3bc700b348'
STATUSES = {'IMPLEMENTED', 'MISSING', 'DEFECTIVE', 'DEFERRED', 'EXTERNAL', 'OWNER APPROVAL', 'UNVERIFIED'}


def revision_available(root, revision):
    return subprocess.run(['git', 'cat-file', '-e', revision + '^{commit}'], cwd=root,
                          capture_output=True, check=False).returncode == 0


def read_rows(path):
    with path.open(newline='') as stream:
        return list(csv.DictReader(stream, delimiter='\t'))


def reference_exists(root, ref, revision=None):
    match = re.fullmatch(r'([^:]+):(\d+)(?:::(\w+))?', ref)
    if not match:
        raise ValueError('Malformed reference: ' + ref)
    path, number, method = match.groups()
    if revision:
        result = subprocess.run(['git', 'show', revision + ':' + path], cwd=root,
                                capture_output=True, text=True, check=False)
        if result.returncode:
            if not revision_available(root, revision):
                raise ValueError('Pinned-main Git evidence unavailable: ' + revision)
            return False
        data = result.stdout
    else:
        target = root / path
        if not target.is_file():
            return False
        data = target.read_text()
    # Historical line numbers can move in main. Method identity or file presence
    # is the main comparison; candidate references must also have valid lines.
    if not revision and not 1 <= int(number) <= len(data.splitlines()):
        return False
    return not method or bool(re.search(r'(?:function|def)\s+' + re.escape(method) + r'\s*\(', data))


def validate(rows, anchors, root=ROOT):
    seen = set()
    for row in rows:
        identity = row['derived_requirement_id']
        if not identity or identity in seen:
            raise ValueError('Duplicate or empty derived requirement ID: ' + identity)
        seen.add(identity)
        for column in ('candidate_status', 'main_status'):
            if row[column] not in STATUSES:
                raise ValueError('Invalid ' + column + ': ' + identity)
        if any(state not in STATUSES for state in filter(None, row['remaining_statuses'].split('; '))):
            raise ValueError('Invalid remaining status: ' + identity)
        for locator in row['source_locators'].split('; '):
            if locator not in anchors:
                raise ValueError('Unknown source locator: ' + locator)
        if row['candidate_status'] == 'IMPLEMENTED':
            if not row['behavioral_evidence'] or not row['implementation']:
                raise ValueError('Missing bounded evidence: ' + identity)
            for column in ('implementation', 'behavioral_evidence'):
                for ref in row[column].split('; '):
                    if not reference_exists(root, ref):
                        raise ValueError('Invalid candidate reference: ' + ref)


def report(rows, anchors, links, source_records, root=ROOT, main=MAIN):
    validate(rows, anchors, root)
    local = [row for row in rows if row['requirement_scope'] == 'MANDATORY_BOUNDED_LOCAL']
    implemented = sum(row['candidate_status'] == 'IMPLEMENTED' for row in local)
    coverage = {locator for row in rows for locator in row['source_locators'].split('; ')}
    main_available = revision_available(root, main)
    main_paths = sum(all(reference_exists(root, ref, main) for ref in row['implementation'].split('; ')) for row in local) if main_available else None
    main_proofs = sum(bool(row['behavioral_evidence']) and all(reference_exists(root, ref, main) for ref in row['behavioral_evidence'].split('; ')) for row in local) if main_available else None
    return {
        'matrix_rows': len(rows), 'source_inventory_records': source_records,
        'blueprint_anchors': len(anchors), 'matrix_referenced_blueprint_anchors': len(coverage),
        'partial_link_records': len(links),
        'candidate_status_counts': dict(Counter(row['candidate_status'] for row in rows)),
        'main_status_counts': dict(Counter(row['main_status'] for row in rows)),
        'bounded_local_denominator': len(local), 'bounded_candidate_implemented': implemented,
        'bounded_candidate_implementation_percent': round(100 * implemented / len(local), 2) if local else None,
        'main_revision': main, 'main_local_implementation_references_present': main_paths,
        'main_local_behavioral_references_present': main_proofs,
        'main_git_evidence_status': 'AVAILABLE' if main_available else 'UNAVAILABLE',
        'mandatory_original_spec_denominator': None,
        'mandatory_original_spec_completion_percent': None,
        'mandatory_original_spec_status': 'UNVERIFIED',
        'full_product_acceptance': 'INCOMPLETE',
        'denominator_reason': str(len(rows)) + ' derived bounded rows are not an exhaustive original-requirement census; '
                              '1771 source records include context, headers, duplicate catalog records and planning. '
                              'Partial links cover only part of the Blueprint and no exhaustive workbook requirement mapping.',
        'verification_scope': 'Candidate reference validation and pinned-main reference comparison only; '
                              'no runtime execution, hosted CI, deployment or external certification inferred.',
    }


def main():
    anchors = {row['Derived locator (not original requirement ID)']
               for row in read_rows(SOURCE / 'blueprint-acceptance.tsv')}
    result = report(read_rows(SOURCE / 'distinct-nonfinance-acceptance.tsv'), anchors,
                    read_rows(SOURCE / 'partial-record-requirement-links.tsv'),
                    len(read_rows(SOURCE / 'nonfinance-closure-matrix.tsv')))
    print(json.dumps(result, indent=2, sort_keys=True))


if __name__ == '__main__':
    main()
