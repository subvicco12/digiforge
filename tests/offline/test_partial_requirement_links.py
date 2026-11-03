"""Derived review groups preserve original anchors; no source text or fixture grants live authority."""
import csv
import importlib.util
from pathlib import Path
import unittest
ROOT = Path(__file__).resolve().parents[2]
SOURCE = ROOT / 'docs/engineering/original-source'
def rows(name):
    with (SOURCE / name).open(newline='') as stream:
        return list(csv.DictReader(stream, delimiter='\t'))
class PartialRequirementLinksTest(unittest.TestCase):
    def test_every_partial_anchor_is_accounted_for_exactly_once(self):
        partial = {r['source_locator']:r for r in rows('nonfinance-closure-matrix.tsv') if r['status']=='IMPLEMENTED_NOT_FULLY_VERIFIED'}
        links = rows('partial-record-requirement-links.tsv')
        self.assertEqual(424,len(links));self.assertEqual(set(partial),{r['source_locator'] for r in links})
        for row in links:
            self.assertEqual(partial[row['source_locator']]['original_text_or_row_json'],row['verbatim_original_text'])
            self.assertEqual(partial[row['source_locator']]['original_section'],row['original_section'])
        self.assertEqual(163,sum(r['source_role']=='SOURCE_CONTEXT_OR_PLANNING' for r in links))
        self.assertEqual(86,sum('; ' in r['derived_requirement_ids'] for r in links))
    def test_bidirectional_distinct_links_and_real_behavioral_references(self):
        spec=importlib.util.spec_from_file_location('final_matrix',ROOT/'bin/check-final-acceptance-matrix.py');module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
        requirements=rows('distinct-nonfinance-acceptance.tsv');byid={r['derived_requirement_id']:r for r in requirements}
        self.assertEqual(len(requirements),len(byid))
        links=rows('partial-record-requirement-links.tsv');bylocator={r['source_locator']:r for r in links}
        partial_ids={identity for row in links for identity in row['derived_requirement_ids'].split('; ') if identity}
        self.assertEqual(45,len(partial_ids))
        self.assertTrue(partial_ids<=set(byid))
        source_locators={r['source_locator'] for r in rows('nonfinance-closure-matrix.tsv')}
        full_trace={r['source_locator']:r for r in rows('full-source-requirement-trace.tsv')}
        for row in links:
            for identity in filter(None,row['derived_requirement_ids'].split('; ')):
                self.assertIn(identity,byid);self.assertIn(row['source_locator'],byid[identity]['source_locators'].split('; '))
        for identity,row in byid.items():
            for column in ('implementation','behavioral_evidence'):
                for ref in filter(None,row[column].split('; ')):
                    self.assertTrue(module.reference_exists(ROOT,ref),ref)
            if row['local_classification']=='VERIFIED_LOCAL_SOFTWARE':
                self.assertTrue('::test' in row['behavioral_evidence'] or row['behavioral_evidence'].startswith('tests/browser/portal-layout.cjs:'))
            for locator in row['source_locators'].split('; '):
                self.assertIn(locator,source_locators)
                # The historical partial ledger retains its original45 selected
                # IDs. Full census associations belong to the supplemental trace.
                if row['census_role'] != 'LEGACY_BOUNDARY':
                    self.assertIn(identity,full_trace[locator]['requirement_ids'].split('; '))
        self.assertEqual({'NF-digital-qa-coverage','NF-backup-restore-certification','NF-release-installed-artifact',
                          'NF-release-package-identity','NF-schema-migration-preservation',
                          'NF-recovery-receipt-integrity','NF-global-readiness-behavior',
                          'NF-personalization-engine-coverage'},{i for i,r in byid.items() if r['legacy_preserved']=='yes'}-partial_ids)
        self.assertEqual('IMPLEMENTED',byid['NF-digital-qa-coverage']['candidate_status'])
        self.assertIn('EXTERNAL',byid['NF-digital-qa-coverage']['remaining_statuses'])
        self.assertEqual(48,sum(r['local_classification']=='VERIFIED_LOCAL_SOFTWARE' for r in requirements if r['legacy_preserved']=='yes'))
        self.assertTrue(all(r['unresolved_classification'] for r in requirements if r['legacy_preserved']=='yes' and r['local_classification']!='VERIFIED_LOCAL_SOFTWARE'))
    def test_category_arithmetic_retains_the_six_previously_omitted_records(self):
        original=rows('nonfinance-closure-matrix.tsv');self.assertEqual(1771,len(original))
        verified=sum(r['status']=='VERIFIED_LOCAL_BOUNDARY' for r in original)
        self.assertEqual(6,verified);self.assertEqual(1765,len(original)-verified)
if __name__=='__main__':
    unittest.main()
