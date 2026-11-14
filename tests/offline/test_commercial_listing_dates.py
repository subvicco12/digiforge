"""Listing instructions and schemas must agree with the real typed portal contract."""
import json
import os
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PHP_CHECK = r'''require $argv[1];
try {
 DigiForge\POD\PersonalizationPayloadContract::validate(
  json_decode($argv[2],true,16,JSON_THROW_ON_ERROR),
  json_decode($argv[3],true,16,JSON_THROW_ON_ERROR));
 echo "ACCEPTED";
} catch (InvalidArgumentException) { echo "REJECTED"; }'''


class CommercialListingDatesTest(unittest.TestCase):
    def validate(self, definitions, values):
        return subprocess.run(
            [os.environ.get('PHP_BINARY', 'php'), '-r', PHP_CHECK,
             str(ROOT / 'includes/POD/PersonalizationPayloadContract.php'),
             json.dumps(definitions), json.dumps(values)],
            capture_output=True, text=True, check=True).stdout

    def test_candidate_dates_match_actual_portal_contract(self):
        for product in ['FL-03', 'FL-04']:
            with self.subTest(product=product):
                c = json.loads((ROOT / 'docs/launch/commercial-candidates' /
                                f'{product}.json').read_text())
                values = dict(first_name='Élodie', second_name='José',
                              date=c['date_input_example'])
                self.assertEqual('ACCEPTED', self.validate(c['input_schema_candidate'], values))
                self.assertIn('YYYY-MM-DD', c['content']['personalization_instructions'])
                self.assertEqual(13, len(c['content']['tags']))
                self.assertEqual(13, len(set(c['content']['tags'])))
                self.assertTrue(all(0 < len(t) <= 20 for t in c['content']['tags']))
                self.assertLessEqual(len(c['content']['title']), 140)
                self.assertFalse(c['publish'])
                self.assertFalse(c['execution_authorized'])

    def test_invalid_calendar_and_freeform_dates_stay_rejected(self):
        for product in ['FL-03', 'FL-04']:
            c = json.loads((ROOT / 'docs/launch/commercial-candidates' /
                            f'{product}.json').read_text())
            for value in ['2023-02-29', 'June 18, 2021', '1/2/21']:
                with self.subTest(product=product, value=value):
                    self.assertEqual('REJECTED', self.validate(
                        c['input_schema_candidate'],
                        dict(first_name='Maya', second_name='Alex', date=value)))


if __name__ == '__main__':
    unittest.main()
