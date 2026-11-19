"""The mandatory WordPress command must run every test file once across environments."""
import json
import pathlib
import unittest
import xml.etree.ElementTree as ET

ROOT = pathlib.Path(__file__).resolve().parents[2]


class WordPressEnvironmentCoverageTests(unittest.TestCase):
    def test_standard_ci_runs_every_wordpress_file_once_without_relaxing_checks(self):
        scripts = json.loads((ROOT / 'composer.json').read_text())['scripts']['test:wordpress']
        self.assertIsInstance(scripts, list, 'All mandatory profiles must run in the standard command.')
        selected = []
        environments = set()
        for command in scripts:
            self.assertRegex(command, r'^phpunit --configuration phpunit\.wordpress(?:\.(?:catalog|authority))?\.xml\.dist$')
            profile = ET.parse(ROOT / command.split()[-1]).getroot()
            self.assertEqual('true', profile.attrib['failOnWarning'])
            self.assertEqual('true', profile.attrib['failOnRisky'])
            environments.add(profile.find("php/env[@name='WP_ENVIRONMENT_TYPE']").attrib['value'])
            for suite in profile.findall('testsuites/testsuite'):
                files = set()
                for directory in suite.findall('directory'):
                    files.update(ROOT.glob(directory.text + '/**/*Test.php'))
                files.update(ROOT / item.text for item in suite.findall('file'))
                files.difference_update(ROOT / item.text for item in suite.findall('exclude'))
                selected.extend(files)
        expected = set((ROOT / 'tests/wordpress').rglob('*Test.php'))
        self.assertEqual(expected, set(selected), 'No WordPress test file may disappear from CI.')
        self.assertEqual(len(expected), len(selected), 'Each file must execute once, not be skipped or duplicated.')
        self.assertEqual({'local', 'production'}, environments)
        workflow = (ROOT / '.github/workflows/digiforge-foundation-audit.yml').read_text()
        self.assertIn('run: composer test:wordpress', workflow)


if __name__ == '__main__':
    unittest.main()
