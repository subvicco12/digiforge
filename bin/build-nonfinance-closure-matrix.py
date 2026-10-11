#!/usr/bin/env python3
"""Reproduce conservative source-to-test inventory; never grants acceptance authority."""
import csv
import json
from pathlib import Path
import re
from collections import Counter
ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / 'docs/engineering/original-source'
ENGINES = ('PET_PORTRAIT', 'WATERCOLOR', 'LINE_ART', 'VINTAGE_PORTRAIT', 'AI_TRANSFORM')
LOCATOR = 'Derived locator (not original requirement ID)'
FIELDS = ['source_kind', 'source_locator', 'original_section', 'original_text_or_row_json', 'status', 'bounded_proof', 'related_implementation_references', 'exact_test_references', 'remaining_contract_or_acceptance', 'authority']
def read_tsv(name):
    with (SOURCE / name).open(newline='') as stream:
        return list(csv.DictReader(stream, delimiter='\t'))
def validate_references(value):
    for ref in filter(None, value.split('; ')):
        match = re.fullmatch(r'([^:]+):(\d+)(?:::(\w+))?', ref)
        if not match:
            raise ValueError('Malformed reference: ' + ref)
        path, number, method = match.groups()
        lines = (ROOT / path).read_text().splitlines()
        if not 1 <= int(number) <= len(lines):
            raise ValueError('Invalid line: ' + ref)
        if method and not re.search(r'function\s+' + re.escape(method) + r'\s*\(', '\n'.join(lines)):
            raise ValueError('Missing test method: ' + ref)
def build():
    original = read_tsv('blueprint-acceptance.tsv')
    ledger = read_tsv('blueprint-reconciliation.tsv')
    workbook = json.loads((SOURCE / 'workbook-original-complete.json').read_text())
    workbook_ledger = read_tsv('workbook-reconciliation.tsv')
    assert len(original) == len(ledger) == 706
    assert len({r[LOCATOR] for r in original}) == 706
    result = []
    for old, row in zip(original, ledger):
        for key in (LOCATOR, 'Original section', 'Original source text'):
            assert old[key] == row[key]
        state = row['Reconciliation state']
        if state == 'OWNER_DEFERRED_FINANCE':
            status = 'FINANCE_DEPENDENCY'
        elif row[LOCATOR] == 'word/document.xml/p89':
            status = 'BLOCKED_MISSING_TRANSFORMATION_CONTRACT'
        elif state in ('OWNER_DEFERRED_OPERATION', 'FUTURE_DEFERRED'):
            status = 'OPERATIONALLY_DEFERRED'
        elif state == 'LOCAL_SOFTWARE_VERIFIED' and row['Requirement acceptance'] == 'BOUNDED_CRITERION_VERIFIED':
            status = 'VERIFIED_LOCAL_BOUNDARY'
        elif state in ('SOURCE_CONTEXT', 'HISTORICAL_CONTEXT', 'AUTHORITY_BOUNDARY', 'GOVERNANCE_BOUNDARY'):
            status = 'SOURCE_CONTEXT/PLANNING'
        else:
            status = 'IMPLEMENTED_NOT_FULLY_VERIFIED'
        result.append(dict(zip(FIELDS, ['blueprint', row[LOCATOR], row['Original section'], row['Original source text'], status, row['Verification scope'] if status == 'VERIFIED_LOCAL_BOUNDARY' else 'No requirement-specific full acceptance established by associated references.', row['Exact implementation reference'], row['Exact test references'], row['Remaining acceptance / authority boundary'], 'REQUIREMENTS_ONLY_NOT_EXECUTION_AUTHORIZATION'])))
        if row[LOCATOR] == 'word/document.xml/p89':
            # Record bounded follow-on evidence without changing the aggregate engine status.
            evidence = result[-1]
            evidence['bounded_proof'] += ' FACE_REPEAT now also has tested operator-selected offline GrabCut segmentation and metadata-free PNG/JPEG normalization; semantic/artistic and supplier certification are not inferred. Experimental SAM Small + closed-form soft-alpha matting also has bounded isolated inference, retained originals, mandatory review and canonical FACE_REPEAT acceptance; measured reference-alpha accuracy remains missing.'
            evidence['related_implementation_references'] += '; includes/POD/OfflineCutoutPipeline.php:6; includes/POD/CustomerCutoutRepository.php:11; docs/engineering/face-cutout-automation.md:1; includes/POD/ExperimentalMattingPipeline.php:6; includes/POD/offline/sam_matting.py:1; docs/engineering/sam-small-matting-experimental.md:1'
            evidence['exact_test_references'] += '; tests/offline/test_face_segmentation.py:36; tests/unit/OfflineCutoutPipelineTest.php:1; tests/wordpress/RenderArtifactCertificationTest.php:301::testOfflineSegmentationUploadIsScopedImmutableReviewGatedAndRendersCanonicalRepeat; tests/unit/ExperimentalMattingPipelineTest.php:1; tests/offline/test_sam_matting_boundary.py:1; tests/wordpress/RenderArtifactCertificationTest.php:316::testExperimentalMattingIsOperatorReviewedScopedAndCanonical'
            evidence['remaining_contract_or_acceptance'] += ' Existing aggregate engine classification is not upgraded by FACE_CUTOUT; PT04/artistic transforms, ground-truth fine-edge quality and supplier certification remain separate.'
    assert sum(len(v['rows']) for v in workbook.values()) == len(workbook_ledger) == 1065
    seen = set()
    for row in workbook_ledger:
        sheet, coordinate = row['Original worksheet'], int(row['Physical row (derived coordinate)'])
        source_row = workbook[sheet]['rows'][coordinate - 1]
        assert json.loads(row['Complete original row values/formulas']) == source_row
        assert (sheet, coordinate) not in seen
        seen.add((sheet, coordinate))
        blocked = sheet in ('Master_500_v2', 'Source_Migration_500') and coordinate > 1 and source_row[4] in ENGINES
        result.append(dict(zip(FIELDS, ['workbook', f'{sheet}!row{coordinate}', sheet, row['Complete original row values/formulas'], 'BLOCKED_MISSING_TRANSFORMATION_CONTRACT' if blocked else 'SOURCE_CONTEXT/PLANNING', 'Original values/formulas/identities preserved; no listing or production certification.', row['Exact implementation reference'], row['Exact test references'], 'Missing approved engine-specific transformation contract and source/result fixtures.' if blocked else row['Acceptance boundary'], 'NOT_CERTIFIED_OR_PROMOTED_NOT_OPERATIONAL_AUTHORITY'])))
    assert seen == {(s, i) for s, v in workbook.items() for i in range(1, len(v['rows']) + 1)}
    for row in result:
        validate_references(row['related_implementation_references'])
        validate_references(row['exact_test_references'])
    engines = {}
    for engine in ENGINES:
        entries = {}
        for sheet in ('Master_500_v2', 'Source_Migration_500'):
            entries[sheet] = [{'source_locator': f'{sheet}!row{i}', 'original_values': r, 'id': r[0], 'source_dg_id': r[1] if sheet == 'Master_500_v2' else r[0], 'migration_disposition': r[5] if sheet == 'Source_Migration_500' else None} for i, r in enumerate(workbook[sheet]['rows'], 1) if i > 1 and r[4] == engine]
        engines[engine] = {'blueprint_anchor': 'word/document.xml/p89', 'rows': entries, 'counts': {s: len(v) for s, v in entries.items()}, 'sufficient_algorithm_or_style_contract_found': False, 'approved_source_result_fixture_pairs_found': 0, 'current_implementation': 'includes/POD/DeterministicPersonalizationRenderer.php:66', 'negative_boundary_test': 'tests/wordpress/RenderArtifactCertificationTest.php:140::testUnsupportedTransformationCannotCertifyGenericArtwork', 'missing_contracts': ['Reviewed engine-specific input/output algorithm or executable prompt/model/version', 'Explicit style and subject-fidelity acceptance criteria', 'Authentic owner-approved source/result fixture bytes and provenance'], 'owner_evidence': 'No affirmative style/fixture approval found in original sources or cached issue959; requirements documents do not grant execution authorization.'}
    inventory = {'source_inventory': json.loads((SOURCE / 'source-inventory.json').read_text()), 'source_document_authorization': False, 'full_product_acceptance': 'INCOMPLETE', 'counts': dict(Counter(r['status'] for r in result)), 'engines': engines, 'scope_limit': 'Local source/code inventory, not provider or runtime certification. References prove related implementation existence unless bounded proof explicitly states otherwise.'}
    for e in engines.values():
        validate_references(e['current_implementation'])
        validate_references(e['negative_boundary_test'])
    return result, inventory

def main():
    rows, inventory = build()
    with (SOURCE / 'nonfinance-closure-matrix.tsv').open('w', newline='') as stream:
        writer = csv.DictWriter(stream, FIELDS, delimiter='\t', lineterminator='\n')
        writer.writeheader()
        writer.writerows(rows)
    (SOURCE / 'transformation-contract-inventory.json').write_text(json.dumps(inventory, ensure_ascii=False, indent=2) + '\n')
    print(json.dumps({'source_rows': len(rows), 'counts': inventory['counts'], 'full_product_acceptance': inventory['full_product_acceptance']}, sort_keys=True))
if __name__ == '__main__':
    main()
