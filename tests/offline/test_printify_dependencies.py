import hashlib
import importlib.util
import json
from pathlib import Path
import unittest
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[2]
MANIFEST=ROOT/'docs/engineering/printify-shared-dependency-manifest-v1.json'

def qualified_overlay_bytes(data,entry):
    """Only the exact reviewed delta may differ; reconstruct and rehash every base byte."""
    if hashlib.sha256(data).hexdigest()!=entry['current_sha256']:
        raise ValueError('Unreviewed current source bytes')
    text=data.decode()
    for hunk in entry['hunks']:
        if not hunk['reviewed_text'] or text.count(hunk['reviewed_text'])!=1:
            raise ValueError('Missing or ambiguous reviewed hunk')
        text=text.replace(hunk['reviewed_text'],hunk['baseline_text'],1)
    base=text.encode()
    if hashlib.sha256(base).hexdigest()!=entry['baseline_sha256']:
        raise ValueError('Qualified baseline reconstruction mismatch')
    return base

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
            data=path.read_bytes()
            if 'audit5007_fresh_read_overlay' in pin:
                self.assertEqual('includes/Security/ShopAccessRepository.php',row['path'])
                self.assertEqual('docs/engineering/audit5007-fresh-read-overlay.json',pin['audit5007_fresh_read_overlay'])
                overlay=json.loads((ROOT/pin['audit5007_fresh_read_overlay']).read_text())
                self.assertEqual('USER_DIRECTED_AUDIT5007_STALL_FIX_NOT_OPERATIONAL_AUTHORITY',overlay['authorization'])
                self.assertEqual('63953bfd9b87a6c365443cd1c66199ca76cee6e2',overlay['baseline_source_sha'])
                entry=next(x for x in overlay['files'] if x['path']==row['path'])
                self.assertEqual(pin['sha256'],entry['baseline_sha256'])
                self.assertEqual(pin['current_tree_sha256'],entry['current_sha256'])
                data=qualified_overlay_bytes(data,entry)
            self.assertEqual(pin['sha256'],hashlib.sha256(data).hexdigest(),row['path'])
            if 'native_get_extension' in pin:
                extension=pin['native_get_extension']
                self.assertEqual('includes/POD/PrintifyCatalogReadRepository.php',row['path'])
                self.assertEqual('CORE_NATIVE_GET_EXTENSION',extension['current_origin'])
                self.assertEqual('9e9094097992453a8cb6ecddd7f936c3584eccef',extension['base_source_sha'])
                self.assertEqual('USER_DIRECTED_NATIVE_GET_COMPLETION_NOT_OPERATIONAL_AUTHORITY',extension['authorization'])
                raw=path.read_bytes();start=raw.index(b' /** Native ordinary-read admission.');end=raw.index(b' public function read(string $sha',start)
                self.assertEqual(extension['added_text_sha256'],hashlib.sha256(raw[start:end]).hexdigest())
                self.assertEqual(extension['base_sha256'],hashlib.sha256(raw[:start]+raw[end:]).hexdigest())

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

    def test_audit_stall_delta_preserves_exact_qualified_bases_and_grants_no_authority(self):
        overlay=json.loads((ROOT/'docs/engineering/audit5007-fresh-read-overlay.json').read_text())
        self.assertEqual('DF_AUDIT5007_FRESH_READ_DELTA_1',overlay['schema'])
        for field in ['decision_cache_added','external_execution_authorized','companion_pins_modified','qualified_hashes_replaced']:
            self.assertIs(False,overlay[field])
        bases={'includes/Security/ShopAccessRepository.php':'e2107698f79b465a1d4b0e23b61e736cbe01cc82e395db76aac7296d90611e9d',
               'includes/PortalAccess/GlobalOwnerAuthority.php':'0a7f3be355f0f8518f8ee46734779b624e7709062c91b158b684a1b1071c091c'}
        self.assertEqual(set(bases),{x['path'] for x in overlay['files']})
        for entry in overlay['files']:
            self.assertEqual(bases[entry['path']],entry['baseline_sha256'])
            raw=(ROOT/entry['path']).read_bytes()
            self.assertEqual(bases[entry['path']],hashlib.sha256(qualified_overlay_bytes(raw,entry)).hexdigest())
            with self.assertRaises(ValueError):qualified_overlay_bytes(raw+b'\n',entry)
            changed=raw.replace(b'return',b'throw',1);self.assertNotEqual(raw,changed)
            with self.assertRaises(ValueError):qualified_overlay_bytes(changed,entry)
            broken=json.loads(json.dumps(entry));broken['hunks'][0]['baseline_text']+='unreviewed change'
            with self.assertRaises(ValueError):qualified_overlay_bytes(raw,broken)

if __name__=='__main__': unittest.main()
