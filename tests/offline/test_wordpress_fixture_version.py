"""Exercise the real installer offline: released core and test library must match."""
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest


INSTALLER = Path(__file__).resolve().parents[2] / "bin/install-wp-tests.sh"
STUB = '''#!{python}
import json, os, pathlib, sys
args = sys.argv[1:]
name = pathlib.Path(sys.argv[0]).name
with open(os.environ['FIXTURE_CALLS'], 'a') as log:
    log.write(json.dumps([name, args]) + '\\n')
if name == 'tar':
    sys.stdin.buffer.read()
    core = pathlib.Path(args[args.index('-C') + 1]) / 'wp-includes'
    core.mkdir(parents=True)
    (core / 'version.php').write_text("<?php $wp_version = '" + os.environ['FIXTURE_VERSION'] + "';")
elif name == 'svn':
    pathlib.Path(args[-1]).mkdir(parents=True)
elif name == 'curl' and '--output' in args:
    pathlib.Path(args[args.index('--output') + 1]).write_text("<?php\\ndefine('ABSPATH', dirname( __FILE__ ) . '/src/');\\n")
'''


class WordPressFixtureVersionTest(unittest.TestCase):
    def run_installer(self, requested="latest", resolved="7.1.3"):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            commands = root / "commands"
            commands.mkdir()
            for name in ("curl", "tar", "svn"):
                command = commands / name
                command.write_text(STUB.format(python=sys.executable))
                command.chmod(0o755)
            calls = root / "calls.jsonl"
            environment = dict(os.environ, PATH=str(commands) + os.pathsep + os.environ["PATH"],
                               WP_CORE_DIR=str(root / "core"), WP_TESTS_DIR=str(root / "tests"),
                               FIXTURE_CALLS=str(calls), FIXTURE_VERSION=resolved)
            result = subprocess.run(["bash", str(INSTALLER), "fixture", "root", "fixture-only",
                                     "127.0.0.1:3306", requested], env=environment,
                                    capture_output=True, text=True, timeout=15)
            observed = [json.loads(line) for line in calls.read_text().splitlines()] if calls.exists() else []
            return result, observed

    def test_latest_uses_the_actual_released_core_tag_for_every_test_source(self):
        result, calls = self.run_installer()
        self.assertEqual(0, result.returncode, result.stderr)
        urls = [argument for _, arguments in calls for argument in arguments if argument.startswith("https://")]
        self.assertEqual(["https://wordpress.org/latest.tar.gz",
                          "https://develop.svn.wordpress.org/tags/7.1.3/tests/phpunit/includes/",
                          "https://develop.svn.wordpress.org/tags/7.1.3/tests/phpunit/data/",
                          "https://develop.svn.wordpress.org/tags/7.1.3/wp-tests-config-sample.php"], urls)

    def test_explicit_release_uses_its_official_archive(self):
        result, calls = self.run_installer("7.1.3")
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("https://wordpress.org/wordpress-7.1.3.tar.gz", calls[0][1])

    def test_mismatched_or_prerelease_core_never_falls_back_to_trunk(self):
        for requested, resolved in (("7.1.2", "7.1.3"), ("latest", "7.2-alpha")):
            with self.subTest(requested=requested, resolved=resolved):
                result, calls = self.run_installer(requested, resolved)
                self.assertNotEqual(0, result.returncode)
                self.assertFalse(any(name == "svn" for name, _ in calls))

    def test_invalid_requested_versions_are_rejected_before_network_work(self):
        result, calls = self.run_installer("trunk")
        self.assertNotEqual(0, result.returncode)
        self.assertEqual([], calls)


if __name__ == "__main__":
    unittest.main()
