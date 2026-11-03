import importlib.util
import json
from pathlib import Path
import unittest
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[2]
MANIFEST=ROOT/'docs/engineering/printify-shared-dependency-manifest-v1.json'

class PrintifySharedDependencyBoundaryTest(unittest.TestCase):
    def test_manifest_is_an_unapproved_diagnostic_not_an_import_permit(self):
        manifest=json.loads(MANIFEST.read_text())
        self.assertEqual('PENDING_SHARED_OWNER',manifest['approval_status'])
        self.assertFalse(any(manifest['policy'].values()))
        self.assertEqual(16,sum(x['consumption']=='owner-approved-shared-core' for x in manifest['runtime_files']))
        for ref in manifest['refs'].values(): self.assertRegex(ref,r'^[a-f0-9]{40}$')
        for row in manifest['runtime_files']:
            self.assertTrue(row['path'].startswith('includes/'))
            self.assertNotIn('..',Path(row['path']).parts)
            self.assertRegex(row['selected_sha256'],r'^[a-f0-9]{64}$')

    def test_unrelated_shared_deltas_and_overlapping_surfaces_remain_owned_elsewhere(self):
        manifest=json.loads(MANIFEST.read_text())
        paths={row['path']:row for row in manifest['runtime_files']}
        for name in ['includes/Database/Tables.php','includes/Integrations/CredentialVault.php']:
            self.assertEqual('retain-main-unchanged',paths[name]['consumption'])
        self.assertEqual(['assets/portal.css','includes/Portal/Portal.php'],[x['path'] for x in manifest['api_lane_files'] if x['overlaps_1242']])
        self.assertEqual(10,manifest['size_guide_owner_change']['max_requests_per_grant'])

    def test_probe_rejects_mutable_or_option_like_refs_before_running_git(self):
        spec=importlib.util.spec_from_file_location('check_printify_dependency',ROOT/'bin/check-printify-shared-dependency.py')
        module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
        with patch.object(module.subprocess,'run') as run:
            for ref in ['main','--help','refs/heads/main']:
                with self.assertRaises(ValueError):module.git_bytes(ROOT,ref,'includes/POD/PrintifyCatalogReadPolicy.php')
            run.assert_not_called()

if __name__=='__main__': unittest.main()
