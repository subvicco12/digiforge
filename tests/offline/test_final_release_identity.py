"""Release identity checks using the actual committed repository archive."""
import hashlib
import json
import pathlib
import re
import subprocess
import tempfile
import unittest
import zipfile

ROOT = pathlib.Path(__file__).resolve().parents[2]


class FinalReleaseIdentityTests(unittest.TestCase):
    def test_dirty_version_pins_cannot_mislabel_committed_archive(self):
        with tempfile.TemporaryDirectory() as directory:
            checkout = pathlib.Path(directory) / "checkout"
            subprocess.run(["git", "clone", "--quiet", "--shared", "--no-hardlinks",
                            str(ROOT), str(checkout)], check=True, capture_output=True)
            entry = checkout / "digiforge.php"
            entry.write_text(entry.read_text().replace("const DIGIFORGE_VERSION = '",
                                                      "const DIGIFORGE_VERSION = 'dirty-")
                             .replace("const DIGIFORGE_DB_VERSION = '",
                                      "const DIGIFORGE_DB_VERSION = 'dirty-"))
            subprocess.run(["bash", str(ROOT / "bin/package-plugin.sh"), "build"],
                           cwd=checkout, check=True, capture_output=True, text=True)
            build = checkout / "build"
            manifest = json.loads((build / "digiforge.release-manifest.json").read_text())
            archive_bytes = (build / "digiforge.zip").read_bytes()
            with zipfile.ZipFile(build / "digiforge.zip") as archive:
                self.assertIsNone(archive.testzip())
                source = archive.read("digiforge/digiforge.php").decode()
                self.assertEqual(manifest["file_count"], len(archive.infolist()))
                self.assertEqual(manifest["commit_sha"], archive.comment.decode())
                self.assertTrue(all(name.startswith("digiforge/") for name in archive.namelist()))
            for field, constant in (("plugin_version", "DIGIFORGE_VERSION"),
                                    ("database_schema_version", "DIGIFORGE_DB_VERSION")):
                self.assertEqual(re.search(r"^const " + constant + r" = '([^']*)';$",
                                           source, re.MULTILINE).group(1), manifest[field])
            self.assertEqual(hashlib.sha256(archive_bytes).hexdigest(), manifest["sha256"])
            self.assertFalse(manifest["external_actions_performed"])
            self.assertFalse(manifest["production_activation_authorized"])


if __name__ == "__main__":
    unittest.main()
