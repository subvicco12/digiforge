import hashlib
import importlib.util
import json
from pathlib import Path
import unittest
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[2]
MANIFEST=ROOT/'docs/engineering/printify-shared-dependency-manifest-v1.json'

class PrintifySharedDependencyBoundaryTest(unittest.TestCase):
    def test_cumulative_shared_core_preserves_selected_verified_sources_without_runtime_authority(self):
        historical=json.loads(MANIFEST.read_text())
        self.assertEqual('OWNER_AUTHORIZED_NARROW_INTEGRATION',historical['approval_status'])
        self.assertFalse(any(historical['policy'].values()))
        self.assertEqual(16,sum(x['consumption']=='owner-approved-shared-core' for x in historical['runtime_files']))
        current=json.loads((ROOT/'docs/engineering/main-pod-recovery/shared-core-preservation.json').read_text())
        self.assertEqual('USER_DIRECTED_MAIN_PLUGIN_PRESERVATION_NOT_RUNTIME_AUTHORITY',current['authorization'])
        self.assertFalse(current['external_execution_authorized'])
        selected={x['path']:x for x in current['files']}
        self.assertEqual({x['path'] for x in historical['runtime_files']},set(selected))
        for row in historical['runtime_files']:
            pin=selected[row['path']]
            self.assertEqual(row['selected_sha256'],pin['historical_source_pin'])
            path=ROOT/row['path']
            if pin['origin']=='NOT_IN_QUALIFIED_PACKAGE':
                self.assertEqual('includes/Security/FirstPartyOwnerPolicy.php',row['path'])
                self.assertFalse(path.exists())
                continue
            self.assertIn(pin['origin'],['POD1256','QUALIFIED_25_PACKAGE'])
            self.assertEqual(pin['sha256'],hashlib.sha256(path.read_bytes()).hexdigest(),row['path'])
            if pin['origin']=='POD1256':
                self.assertEqual('d32fc91ae7b559857b6a1dc81798eb6c3e45ed1f',pin['source_sha'])
            else:
                self.assertEqual('98cf08aeb8bdf08617808751257e098a1fef37b60a14ce7400fe08122d6ba45e',pin['artifact_sha256'])

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
