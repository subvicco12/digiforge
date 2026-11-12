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
        # The qualified .17 normalization is a different, independently preserved source baseline.
        # Historical API integration authorization still never grants runtime authority.
        current=json.loads((ROOT/'docs/gate2/PAYLOAD.json').read_text())
        baseline=json.loads((ROOT/'docs/normalization/QUALIFIED_PAYLOAD.json').read_text())
        self.assertEqual('fb3b805ca7eb7d91b8e87deab6ba408f6e38d3e3',current['baseline_source_sha'])
        old={x['path']:x['sha256'] for x in baseline['files']}
        rows={x['path']:x for x in current['files']}
        self.assertEqual(741,len(old));self.assertEqual(743,len(rows))
        self.assertTrue(old.keys()<=rows.keys())
        for path,row in rows.items():
            self.assertEqual(old.get(path),row['baseline_sha256'])
            self.assertEqual(row['sha256'],hashlib.sha256((ROOT/path).read_bytes()).hexdigest(),path)
            self.assertTrue(row['reason'])
        self.assertEqual(731,sum(x['change']=='RETAIN_IDENTICAL' for x in rows.values()))
        self.assertEqual(10,sum(x['change']=='MODIFY_REVIEWED' for x in rows.values()))
        self.assertEqual(2,sum(x['change']=='ADD' for x in rows.values()))

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
