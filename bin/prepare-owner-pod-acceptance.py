#!/usr/bin/env python3
"""Extend the existing review waves with exact unresolved evidence; no execution authority."""
import csv
import hashlib
import importlib.util
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
OBSERVATION_SHA256 = 'b3d389f2defdecc30d00e115920dd16019509d08661668d11d074417e67854b1'


def module(name, filename):
    spec = importlib.util.spec_from_file_location(name, ROOT / 'bin' / filename)
    result = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(result)
    return result


def receipt_digest(value):
    content = {k: v for k, v in value.items() if k != 'receipt_sha256'}
    return hashlib.sha256(json.dumps(content, sort_keys=True, ensure_ascii=False,
                                     separators=(',', ':')).encode()).hexdigest()


def prepare(product_id='DG2-438', source_bytes=None):
    # This reviewed shortlist is fixed. Selecting a different product needs its own evidence review.
    if product_id != 'DG2-438':
        raise ValueError('No reviewed evidence selection for this product')
    plan = module('launch_plan', 'prepare-master500-launch.py').prepare(source_bytes)
    with (ROOT / 'docs/engineering/original-source/distinct-nonfinance-acceptance.tsv').open(encoding='utf-8', newline='') as stream:
        rows = list(csv.DictReader(stream, delimiter='\t'))
    unresolved = [r for r in rows if r['census_role'] == 'ATOMIC'
                  and r['kind'] == 'SOFTWARE_MANDATORY' and r['candidate_status'] != 'IMPLEMENTED']
    if len(unresolved) != 22 or len({r['derived_requirement_id'] for r in unresolved}) != 22:
        raise ValueError('Acceptance census changed; review rather than inventing completion')
    wave = next(w for w in plan['waves'] if any(p['v2_id'] == product_id for p in w['products']))
    product = next(p for p in wave['products'] if p['v2_id'] == product_id)
    observation_path = 'tests/fixtures/printify/source-six-group-review-20261020.json'
    observation_bytes = (ROOT / observation_path).read_bytes()
    observation_hash = hashlib.sha256(observation_bytes).hexdigest()
    if observation_hash != OBSERVATION_SHA256:
        raise ValueError('Historical supplier observation changed; independent source review required')
    observation = json.loads(observation_bytes)
    snapshot = observation['source_snapshot']
    if (snapshot['shop_key'], snapshot['environment'], snapshot['printify_store_id'], snapshot['source_listing_id']) != (
            'personalized_pod', 'production', '28930593', '8a2666c5-d3db-4b44-bf6a-4c5b4213815e'):
        raise ValueError('Historical supplier scope conflicts with the reviewed shortlist')
    matches = [p for p in snapshot['products'] if p['label'] == 'sweatshirt']
    if len(matches) != 1 or matches[0]['product_id'] != '6ad647d24f4a65e4300211e5':
        raise ValueError('Historical sweatshirt identity is missing or ambiguous')
    supplier = matches[0]
    requirements = ['blueprint_provider_variant_binding', 'method_and_print_area', 'safe_zone_and_bleed',
                    'resolution_and_print_tolerance', 'shipping_region_method_delivery_and_cost',
                    'base_cost_currency_fees_tax_and_margin', 'complete_artwork_commercial_rights',
                    'embroidered_look_style_review', 'native_personalization_and_buyer_editability',
                    'independent_linguistic_artistic_and_physical_review']
    result = {
        'schema': 'digiforge-owner-pod-acceptance-review-v1',
        'existing_launch_plan_sha256': plan['plan_sha256'], 'waves': plan['waves'],
        'source_tsv_sha256': plan['source_tsv_sha256'],
        'selected_product': {
            'source': product, 'wave': wave['wave'],
            'selection_basis': 'NAME_MONOGRAM engine plus retained sweatshirt UI evidence; '
                               'simpler than photo/cutout products. Commercial ranking remains UNVERIFIED.',
            'alternatives': {'DG2-276': 'Name journal: simple engine but no retained exact native product observation.',
                             'DG2-054': 'Family T-shirt: native product observation but PET_PORTRAIT source engine; '
                                        'FC01 text-only cannot silently replace that source.'},
            'supplier': {'identity_evidence': 'ATTRIBUTED_HISTORICAL_UI_REPORT',
                         'shop_key': snapshot['shop_key'], 'environment': snapshot['environment'],
                         'printify_store_id': snapshot['printify_store_id'],
                         'source_listing_id': snapshot['source_listing_id'],
                         'product_id': supplier['product_id'], 'blueprint_id': supplier['blueprint_id'],
                         'provider_id': supplier['provider_id'], 'variant_ids': supplier['selected_variant_ids'],
                         'routing': supplier['routing'], 'source_reference': observation['source_snapshot']['provenance']['source_reference'],
                         'source_file': observation_path,
                         'source_file_sha256': observation_hash,
                         'conflict': 'Sweatshirt selected size slots 8 versus 9; exact selected colors incomplete.'},
            'reused_artwork': 'assets/pod-designs/fc01-v2-text-only; diagnostic licensed-font text engine only, '
                              'not a supplier template or embroidered-look approval.',
            'source_to_supplier_binding_verified': False,
            'production_requirements': dict.fromkeys(requirements, 'UNVERIFIED'),
            'production_authorized': False,
        },
        'unresolved_nonfinance': [
            {'id': r['derived_requirement_id'], 'requirement': r['bounded_requirement'],
             'status': 'UNVERIFIED', 'ledger_candidate_status': r['candidate_status'],
             'action_classification': r['action_classification'], 'next_action': r['next_action'],
             'remaining_evidence': r['unresolved_classification'], 'existing_behavioral_evidence': r['behavioral_evidence']}
            for r in unresolved],
        'bounded_implementation_candidates': 246, 'nonfinance_denominator': 268,
        'finance_criteria_excluded': 23, 'products_certified_or_promoted': 0,
        'stop_all': True, 'external_lock': True, 'automation': False, 'release_hold': True,
        'deploy_authorized': False, 'publish_authorized': False, 'external_execution_authorized': False,
    }
    if sum(r['census_role'] == 'ATOMIC' and r['kind'] == 'SOFTWARE_MANDATORY' for r in rows) != 268:
        raise ValueError('Mandatory denominator changed; independent reconciliation required')
    result['receipt_sha256'] = receipt_digest(result)
    return result


if __name__ == '__main__':
    print(json.dumps(prepare(), ensure_ascii=False, indent=2))
