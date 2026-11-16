"""Run the real audit script against isolated image-client mutations, without HTTP."""
import pathlib
import shutil
import subprocess
import tempfile
import unittest

from test_google_http_boundary import ROOT, scan_script


class ImageHttpBoundaryTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='image-http-scan-')
        self.root = pathlib.Path(self.temp.name)
        for folder in ('includes', 'modules', 'automation', 'admin'):
            shutil.copytree(ROOT / folder, self.root / folder)
        shutil.copyfile(ROOT / 'digiforge.php', self.root / 'digiforge.php')
        self.source = self.root / 'includes/AI/ImageGenerationHttpClient.php'
        self.original = self.source.read_text()

    def tearDown(self):
        self.temp.cleanup()

    def scan(self):
        return subprocess.run(['bash', '-e', '-o', 'pipefail', '-c', scan_script()],
                              cwd=self.root, capture_output=True, text=True)

    def artifact_scan(self):
        return subprocess.run(['python3', str(ROOT / 'bin/artifact-audit/security.py'), str(self.root)],
                              capture_output=True, text=True)

    def test_exact_guarded_image_sender_is_admitted(self):
        result = self.scan()
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)

    def test_another_sender_same_basename_or_duplicate_stays_denied(self):
        for relative in ('modules/Rogue.php', 'modules/ImageGenerationHttpClient.php',
                         'includes/AI/ImageGenerationHttpClientXphp'):
            target = self.root / relative
            target.write_text('<?php\n$response=wp_remote_post($wire["url"],$options);\n')
            try:
                self.assertNotEqual(0, self.scan().returncode)
            finally:
                target.unlink()
        self.source.write_text(self.original + '\n$response=wp_remote_post($wire["url"],$options);\n')
        self.assertNotEqual(0, self.scan().returncode)

    def test_exact_identity_endpoint_lease_and_security_guards_are_required(self):
        guards = [
            "Validator::canonicalJson($identity['wire_contract'])!==Validator::canonicalJson($contract)",
            'https://api.openai.com/v1/images/generations',
            'https://api.openai.com/v1/images/edits',
            '$this->custody===null',
            '$this->custody->withExclusiveGeneration(',
            'ImageGenerationAccess::networkAllowed()',
            'ImageGenerationAccess::authorize()',
            '$this->claim($job,$mode)',
            "'redirection'=>0", "'sslverify'=>true", "'reject_unsafe_urls'=>true",
            "'limit_response_size'=>", "'Accept-Encoding'=>'identity'",
            'MonetaryReservationRepository()',
            "'image_dispatch_claimed'",
            "(string)$session['active']!=='0'",
        ]
        for guard in guards:
            with self.subTest(guard=guard):
                self.assertIn(guard, self.original)
                self.source.write_text(self.original.replace(guard, 'MISSING_IMAGE_SECURITY_GUARD'))
                self.assertNotEqual(0, self.scan().returncode, guard)
                self.source.write_text(self.original)

    def test_exact_image_authority_passes_recovered_artifact_gate(self):
        result = self.artifact_scan()
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)

    def test_recovered_gate_rejects_any_image_authority_drift_or_missing_member(self):
        names = ['ImageGenerationAccess', 'ImageGenerationCredentialCustody',
                 'ImageGenerationPolicyRepository', 'ImageGenerationJournal',
                 'ImageGenerationWireContract', 'ImageGenerationHttpClient',
                 'ImageGenerationExecution', 'OfflineImageTransport', 'ProviderEvidenceRepository']
        for name in names:
            path = self.root / 'includes/AI' / (name + '.php')
            original = path.read_bytes()
            with self.subTest(name=name):
                try:
                    path.write_bytes(original + b'\n// unreviewed authority drift\n')
                    self.assertNotEqual(0, self.artifact_scan().returncode)
                    path.unlink()
                    self.assertNotEqual(0, self.artifact_scan().returncode)
                finally:
                    path.write_bytes(original)

    def test_recovered_gate_rejects_relocated_duplicate_and_symlinked_authority(self):
        rogue = self.root / 'modules/ImageGenerationHttpClient.php'
        rogue.write_text('<?php\n$response=wp_remote_post($wire["url"],$options);\n')
        self.assertNotEqual(0, self.artifact_scan().returncode)
        rogue.unlink()
        copy = self.root / 'copy.php'
        copy.write_text(self.original)
        self.source.unlink()
        self.source.symlink_to(copy)
        self.assertNotEqual(0, self.artifact_scan().returncode)


if __name__ == '__main__':
    unittest.main()
