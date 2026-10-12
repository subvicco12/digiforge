#!/usr/bin/env python3
"""Read-only validation of bounded acceptance, provenance and denominator limits.

Reference existence is never a substitute for test execution or final acceptance.
Only committed source indexes are read; originals and raw ledgers are not rebuilt.
"""
import hashlib
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
            if row.get('census_role') == 'ATOMIC' and row.get('evidence_kind') != 'NAMED_BEHAVIORAL':
                raise ValueError('Verified atomic criterion lacks named behavioral evidence kind: ' + identity)
            if not row['behavioral_evidence'] or not row['implementation']:
                raise ValueError('Missing bounded evidence: ' + identity)
            for column in ('implementation', 'behavioral_evidence'):
                for ref in row[column].split('; '):
                    if column == 'behavioral_evidence' and not re.fullmatch(r'[^:]+:\d+::test\w+', ref, flags=re.I):
                        raise ValueError('Invalid candidate reference: unnamed behavioral evidence: ' + ref)
                    if not reference_exists(root, ref):
                        raise ValueError('Invalid candidate reference: ' + ref)


def validate_census(rows, trace, originals):
    immutable = {r['source_locator']: r for r in originals}
    if len(immutable) != len(originals):
        raise ValueError('Duplicate immutable source locator')
    classified = {}
    ids = {r['derived_requirement_id']: r for r in rows}
    for item in trace:
        locator = item['source_locator']
        if locator in classified:
            raise ValueError('Multiple source classification: ' + locator)
        if locator not in immutable:
            raise ValueError('Unknown source locator: ' + locator)
        classified[locator] = item
        text = immutable[locator]['original_text_or_row_json']
        if item['original_text_or_row_json'] != text or item['source_sha256'] != hashlib.sha256(text.encode()).hexdigest():
            raise ValueError('Source text/SHA mismatch: ' + locator)
        if item['classification'] not in {'CONTEXT', 'MANDATORY', 'HISTORICAL', 'FUTURE', 'FINANCE'} or not item['classification_reason']:
            raise ValueError('Missing source classification/reason: ' + locator)
        linked = list(filter(None, item['requirement_ids'].split('; ')))
        if item['classification'] == 'MANDATORY' and not linked:
            raise ValueError('Mandatory source without requirement links: ' + locator)
        for identity in linked:
            if identity not in ids:
                raise ValueError('Unknown requirement link: ' + identity)
            if locator not in ids[identity]['source_locators'].split('; '):
                raise ValueError('Requirement/source link mismatch: ' + identity)
    if set(classified) != set(immutable):
        raise ValueError('Omitted source classifications')
    for row in rows:
        if row.get('census_role') not in {'ATOMIC', 'AGGREGATE', 'LEGACY_BOUNDARY', 'SEMANTIC_DUPLICATE'}:
            raise ValueError('Invalid census role: ' + row['derived_requirement_id'])
        if row.get('census_role') == 'SEMANTIC_DUPLICATE' and (row.get('canonical_requirement_id') not in ids or row['canonical_requirement_id'] == row['derived_requirement_id']):
            raise ValueError('Invalid semantic duplicate canonical ID')
        if row.get('census_role') == 'SEMANTIC_DUPLICATE' and (ids[row['canonical_requirement_id']]['census_role'] != 'ATOMIC' or ids[row['canonical_requirement_id']]['kind'] != row['kind']):
            raise ValueError('Semantic duplicate must resolve to same-kind atomic criterion')
        if not row.get('classification_reason'):
            raise ValueError('Missing criterion classification reason')
        if row['census_role'] == 'ATOMIC' and row['kind'] not in {'SOFTWARE_MANDATORY', 'EXTERNAL_CERTIFICATION', 'FINANCE_DEPENDENT', 'FUTURE_OPTIONAL'}:
            raise ValueError('Invalid atomic criterion kind')
    return classified


def report(rows, anchors, links, source_records, root=ROOT, main=MAIN, trace=None, originals=None):
    validate(rows, anchors, root)
    local = [row for row in rows if row['requirement_scope'] == 'MANDATORY_BOUNDED_LOCAL']
    implemented = sum(row['candidate_status'] == 'IMPLEMENTED' for row in local)
    coverage = {locator for row in rows for locator in row['source_locators'].split('; ')}
    main_available = revision_available(root, main)
    main_paths = sum(all(reference_exists(root, ref, main) for ref in row['implementation'].split('; ')) for row in local) if main_available else None
    main_proofs = sum(bool(row['behavioral_evidence']) and all(reference_exists(root, ref, main) for ref in row['behavioral_evidence'].split('; ')) for row in local) if main_available else None
    result = {
        'matrix_rows': len(rows), 'source_inventory_records': source_records,
        'blueprint_anchors': sum(a.startswith('word/') for a in anchors), 'matrix_referenced_blueprint_anchors': sum(a.startswith('word/') for a in coverage),
        'partial_link_records': len(links),
        'candidate_status_counts': dict(Counter(row['candidate_status'] for row in rows)),
        'main_status_counts': dict(Counter(row['main_status'] for row in rows)),
        'bounded_local_denominator': len(local), 'bounded_candidate_implemented': implemented,
        'bounded_ledger_scope': 'HISTORICAL_SELECTED_BOUNDARY_NOT_FULL_CENSUS',
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

    if trace is not None:
        validate_census(rows, trace, originals)
        atomic = [r for r in rows if r['census_role'] == 'ATOMIC' and r['kind'] == 'SOFTWARE_MANDATORY']
        verified = sum(r['candidate_status'] == 'IMPLEMENTED' for r in atomic)
        gaps = [r['derived_requirement_id'] for r in atomic if r['candidate_status'] != 'IMPLEMENTED']
        result.update(source_census_records=len(trace), source_classification_counts=dict(Counter(r['classification'] for r in trace)),
                      census_role_counts=dict(Counter(r['census_role'] for r in rows)),
                      atomic_kind_counts=dict(Counter(r['kind'] for r in rows if r['census_role'] == 'ATOMIC')),
                      mandatory_original_spec_denominator=len(atomic), mandatory_original_spec_verified=verified,
                      mandatory_original_spec_unverified=len(gaps), mandatory_unverified_ids=gaps,
                      mandatory_gap_groups=dict(Counter('PORTAL_CAPABILITY' if i.startswith('BP-portal-') else 'CORE_BLUEPRINT' if i.startswith('DERIVED-BP-') else 'OTHER_OPERATOR_LIFECYCLE' for i in gaps)),
                      mandatory_original_spec_completion_percent=round(100 * verified / len(atomic), 2) if atomic else None,
                      mandatory_original_spec_status='CLASSIFIED_NONFINANCE_SOFTWARE_CENSUS',
                      denominator_reason='All immutable source anchors classified once. Distinct atomic mandatory non-finance software criteria only; coarse aggregates, catalog data, finance, future and external certification excluded.',
                      verification_scope='Named criterion-specific local behavioral evidence inherited only for unchanged audited code or refreshed native tests; reference checks cannot execute tests or certify provider, production or deployed main.')
    return result


def main():
    anchors = {row['Derived locator (not original requirement ID)']
               for row in read_rows(SOURCE / 'blueprint-acceptance.tsv')}
    anchors |= {r['source_locator'] for r in read_rows(SOURCE / 'nonfinance-closure-matrix.tsv')}
    result = report(read_rows(SOURCE / 'distinct-nonfinance-acceptance.tsv'), anchors,
                    read_rows(SOURCE / 'partial-record-requirement-links.tsv'),
                    len(read_rows(SOURCE / 'nonfinance-closure-matrix.tsv')),
                    trace=read_rows(SOURCE / 'full-source-requirement-trace.tsv'),
                    originals=read_rows(SOURCE / 'nonfinance-closure-matrix.tsv'))
    print(json.dumps(result, indent=2, sort_keys=True))


if __name__ == '__main__':
    main()
