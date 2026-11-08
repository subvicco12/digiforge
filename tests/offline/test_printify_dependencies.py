import hashlib
import importlib.util
import json
from pathlib import Path
import unittest
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[2]
MANIFEST=ROOT/'docs/engineering/printify-shared-dependency-manifest-v1.json'

class PrintifySharedDependencyBoundaryTest(unittest.TestCase):
    def test_source_integration_authorization_never_grants_runtime_authority(self):
        manifest=json.loads(MANIFEST.read_text())
        self.assertEqual('OWNER_AUTHORIZED_NARROW_INTEGRATION',manifest['approval_status'])
        self.assertEqual('b859d0078c68e8bcdc6c0732983969e81d3c9abe',manifest['refs']['shared_core'])
        self.assertFalse(any(manifest['policy'].values()))
        self.assertEqual(16,sum(x['consumption']=='owner-approved-shared-core' for x in manifest['runtime_files']))
        for ref in manifest['refs'].values(): self.assertRegex(ref,r'^[a-f0-9]{40}$')
        for row in manifest['runtime_files']:
            self.assertTrue(row['path'].startswith('includes/'))
            self.assertNotIn('..',Path(row['path']).parts)
            self.assertRegex(row['selected_sha256'],r'^[a-f0-9]{64}$')
            data=(ROOT/row['path']).read_bytes()
            qualification=json.loads((ROOT/'docs/engineering/printify-api-qualification-overlay.json').read_text())
            self.assertEqual('OWNER_FINAL_API_QUALIFICATION_PR1260',qualification['authorization'])
            self.assertFalse(qualification['runtime_authority_provisioned'])
            self.assertFalse(qualification['grant_limits_changed'])
            for delta in qualification['files']:
                if delta['path'] != row['path']: continue
                self.assertEqual(delta['overlay_sha256'],hashlib.sha256(data).hexdigest())
                text=data.decode()
                for hunk in reversed(delta['hunks']):
                    self.assertEqual(1,text.count(hunk['reviewed_text']))
                    text=text.replace(hunk['reviewed_text'],hunk['baseline_text'],1)
                data=text.encode()
                self.assertEqual(delta['baseline_sha256'],hashlib.sha256(data).hexdigest())
            completion=json.loads((ROOT/'docs/engineering/printify-api-completion-overlay.json').read_text())
            self.assertEqual('OWNER_API_SOFTWARE_COMPLETION_PR1260',completion['authorization'])
            self.assertFalse(completion['runtime_authority_provisioned'])
            self.assertFalse(completion['grant_limits_changed'])
            for delta in completion['files']:
                if delta['path'] != row['path']: continue
                self.assertEqual(delta['overlay_sha256'],hashlib.sha256(data).hexdigest())
                text=data.decode()
                for hunk in reversed(delta['hunks']):
                    self.assertEqual(1,text.count(hunk['reviewed_text']))
                    text=text.replace(hunk['reviewed_text'],hunk['baseline_text'],1)
                data=text.encode()
                self.assertEqual(delta['baseline_sha256'],hashlib.sha256(data).hexdigest())
            if row['path']=='includes/Security/ShopAccess.php':
                overlay=json.loads((ROOT/'docs/engineering/portal-access/native-acl-overlay.json').read_text())
                self.assertEqual('OWNER_APPROVED_ISSUE_1257_RESTRICTIVE_CONSUMER_OVERLAY',overlay['authorization'])
                self.assertFalse(overlay['signature_verifier_modified']);self.assertFalse(overlay['new_authority_granted'])
                self.assertEqual(row['selected_sha256'],overlay['baseline_sha256'])
                self.assertEqual(overlay['overlay_sha256'],hashlib.sha256(data).hexdigest())
                text=data.decode()
                for hunk in overlay['hunks']:
                    self.assertEqual(1,text.count(hunk['reviewed_text']))
                    text=text.replace(hunk['reviewed_text'],hunk['baseline_text'],1)
                data=text.encode()
            if row['path']=='includes/Security/ShopAccessRepository.php':
                overlay=json.loads((ROOT/'docs/engineering/portal-access/global-owner-native-overlay.json').read_text())
                self.assertEqual('OWNER_APPROVED_DATABASE_GLOBAL_PRIMARY_OWNER_REPLACEMENT',overlay['authorization'])
                self.assertFalse(overlay['signature_verifier_modified']);self.assertFalse(overlay['runtime_authority_provisioned'])
                self.assertEqual(row['selected_sha256'],overlay['baseline_sha256'])
                self.assertEqual(overlay['overlay_sha256'],hashlib.sha256(data).hexdigest())
                text=data.decode()
                for hunk in overlay['hunks']:
                    self.assertEqual(1,text.count(hunk['reviewed_text']))
                    text=text.replace(hunk['reviewed_text'],hunk['baseline_text'],1)
                data=text.encode()
            if row['path']=='includes/POD/PrintifyCatalogReadContract.php':
                overlay=json.loads((ROOT/'docs/engineering/printify-catalog-discovery-overlay.json').read_text())
                self.assertEqual(10,overlay['max_requests_per_grant'])
                self.assertFalse(overlay['grants_issued'])
                self.assertEqual(row['selected_sha256'],overlay['baseline_sha256'])
                self.assertEqual(overlay['overlay_sha256'],hashlib.sha256(data).hexdigest())
                text=data.decode()
                for hunk in overlay['hunks']:
                    self.assertEqual(1,text.count(hunk['reviewed_text']))
                    text=text.replace(hunk['reviewed_text'],hunk['baseline_text'],1)
                data=text.encode()
            if row['path']=='includes/POD/ScopedProviderConnection.php':
                overlay=json.loads((ROOT/'docs/engineering/printify-product-scope-overlay.json').read_text())
                self.assertFalse(overlay['external_authority_granted'])
                self.assertEqual(row['selected_sha256'],overlay['baseline_sha256'])
                self.assertEqual(overlay['overlay_sha256'],hashlib.sha256(data).hexdigest())
                text=data.decode()
                for hunk in overlay['hunks']:
                    self.assertEqual(1,text.count(hunk['reviewed_text']))
                    text=text.replace(hunk['reviewed_text'],hunk['baseline_text'],1)
                data=text.encode()
            if row['path']=='includes/Security/ShopAccessAuthority.php':
                # Explicit Ed25519-only restoration; original shared-core pin is retained.
                receipt=json.loads((ROOT/'docs/engineering/portal-access/installed-security-restorations.json').read_text())[0]
                self.assertEqual(row['path'],receipt['path'])
                self.assertEqual(row['selected_sha256'],receipt['reviewed_source_sha256'])
                self.assertEqual(receipt['installed_1032_sha256'],hashlib.sha256(data).hexdigest())
                continue
            self.assertEqual(row['selected_sha256'],hashlib.sha256(data).hexdigest(),row['path'])

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
