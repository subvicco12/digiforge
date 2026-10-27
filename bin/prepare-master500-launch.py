#!/usr/bin/env python3
"""Prepare source-bound offline review waves; never a publisher or readiness certificate."""
import csv
import hashlib
import io
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / 'docs/engineering/original-source/Master_500_v2.tsv'
SOURCE_SHA256 = 'c64b7df73897619eaf5a6cc66a034478694558f987469f0defc8dd6b67e9712e'
WORKBOOK_SHA256 = '56639c9122985ac17b46685bb8b91e5d29f387f16fff715b3488e9b4c2380f6d'
EVIDENCE = [
    'current_shop_scoped_product_research_and_commercial_review',
    'licensed_source_artwork_and_independent_artistic_review',
    'versioned_personalization_schema_and_exact_buyer_editability',
    'deterministic_preview_final_artwork_parity_and_unicode_overflow_qa',
    'current_supplier_variant_geometry_shipping_and_native_evidence',
    'source_bound_listing_media_copy_policy_and_product_review',
    'durable_queue_replay_unknown_recovery_and_exact_head_audit',
    'separate_owner_release_authorization_and_external_execution_grant',
]


def plan_digest(plan):
    content = {k: v for k, v in plan.items() if k != 'plan_sha256'}
    return hashlib.sha256(json.dumps(content, sort_keys=True, ensure_ascii=False,
                                     separators=(',', ':')).encode()).hexdigest()


def prepare(source_bytes=None):
    raw = SOURCE.read_bytes() if source_bytes is None else source_bytes
    if hashlib.sha256(raw).hexdigest() != SOURCE_SHA256:
        raise ValueError('Source TSV differs from reviewed extraction; do not rebind workbook provenance')
    rows = list(csv.DictReader(io.StringIO(raw.decode('utf-8')), delimiter='\t'))
    expected = [f'DG2-{i:03d}' for i in range(1, 501)]
    if [r['V2 ID'] for r in rows] != expected:
        raise ValueError('Exact ordered 500 source identities required')
    products = []
    for row in rows:
        products.append({
            'v2_id': row['V2 ID'], 'source_dg_id': row['Source DG ID'],
            'source_excel_row': int(row['Original Excel row']),
            'family': row['Family'], 'concept': row['Concept'], 'engine': row['Engine'],
            'physical_product_placeholder': row['Physical Product'],
            'source_supplier_gate': row['Supplier Gate'],
            'source_template_state': row['Template State'],
            'source_recommended_stage': row['Recommended Stage'],
            'shop': 'personalized_pod', 'launch_status': 'REVIEW_REQUIRED',
            'required_evidence': list(EVIDENCE), 'publish_authorized': False,
        })
    plan = {
        'schema': 'digiforge-master500-offline-launch-review-v1',
        'source_workbook_sha256': WORKBOOK_SHA256, 'source_tsv_sha256': SOURCE_SHA256,
        'source_boundary': 'Committed extraction; original workbook not reopened by this tool',
        'product_count': len(products), 'operational_shop_scopes': ['digital', 'personalized_pod'],
        'inactive_shop_scopes': ['standard_pod', 'jewelry'],
        'stop_all': True, 'external_lock': True, 'automation_armed': False,
        'release_hold': True, 'external_execution_authorized': False,
        'finance_operationally_excluded': True,
        'waves': [{'wave': n // 25 + 1, 'purpose': 'OFFLINE_REVIEW_ONLY',
                   'products': products[n:n + 25]} for n in range(0, 500, 25)],
    }
    plan['plan_sha256'] = plan_digest(plan)
    return plan


if __name__ == '__main__':
    print(json.dumps(prepare(), ensure_ascii=False, indent=2))
