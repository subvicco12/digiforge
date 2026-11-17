import importlib.util
import json
import os
import pathlib
import shutil
import subprocess
import tempfile
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[2]


class PackageWorkerNamespaceTest(unittest.TestCase):
    def test_prepared_child_worker_inherits_exact_parent_namespace(self):
        spec = importlib.util.spec_from_file_location('package_worker_harness', ROOT / 'bin/prepare-printify-connector-package-tests.py')
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        with tempfile.TemporaryDirectory() as directory:
            root = pathlib.Path(directory)
            repo = root / 'repo'
            tests = repo / 'tests/wordpress'
            tests.mkdir(parents=True)
            (tests / 'bootstrap.php').write_text("<?php require getenv('WP_TESTS_DIR').'/includes/bootstrap.php';")
            (tests / 'PrintifyConnectorIntegrationTest.php').write_text('<?php // fixture only')
            (repo / 'phpunit.wordpress.xml.dist').write_text('<phpunit/>')
            def git(*args):
                return subprocess.check_output(['git', '-C', str(repo), *args], text=True).strip()
            git('init', '-q')
            git('config', 'user.email', 'fixture@example.invalid')
            git('config', 'user.name', 'Synthetic fixture')
            git('add', '.')
            git('commit', '-qm', 'legacy bootstrap fixture')
            baseline = git('rev-parse', 'HEAD')
            for name in ['bootstrap.php', 'FixtureConfiguration.php']:
                shutil.copyfile(ROOT / 'tests/wordpress' / name, tests / name)
            git('add', '.')
            git('commit', '-qm', 'namespace-aware fixture')
            module.ROOT, module.BASE = repo, baseline
            output = root / 'prepared'
            module.prepare(output)
            wp = root / 'wp'
            (wp / 'includes').mkdir(parents=True)
            (wp / 'wp-tests-config.php').write_text('<?php $table_prefix="baseline_";')
            (wp / 'includes/functions.php').write_text('<?php function tests_add_filter(...$args) {}')
            (wp / 'includes/bootstrap.php').write_text("<?php require defined('WP_TESTS_CONFIG_FILE_PATH') ? WP_TESTS_CONFIG_FILE_PATH : getenv('WP_TESTS_DIR').'/wp-tests-config.php'; echo $table_prefix;")
            child = root / 'child.php'
            child.write_text('<?php putenv("WP_TESTS_SKIP_INSTALL=1"); require ' + json.dumps(str(output / 'tests/wordpress/bootstrap.php')) + ';')
            parent = root / 'parent.php'
            parent.write_text('<?php require ' + json.dumps(str(ROOT / 'tests/wordpress/FixtureConfiguration.php')) + '; $c=DigiForgeTestFixtureConfiguration::prepare(' + json.dumps(str(wp / 'wp-tests-config.php')) + '); $p=proc_open([PHP_BINARY,' + json.dumps(str(child)) + '],[1=>["pipe","w"],2=>["pipe","w"]],$pipes); $text=stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]); $exit=proc_close($p); echo json_encode(["parent"=>$c["prefix"],"child"=>$text,"exit"=>$exit,"error"=>$error]);')
            env = dict(os.environ, WP_TESTS_DIR=str(wp))
            result = subprocess.run(['php', str(parent)], env=env, text=True, capture_output=True, check=True)
            evidence = json.loads(result.stdout)
            self.assertEqual(0, evidence['exit'], evidence['error'])
            self.assertEqual('', evidence['error'])
            self.assertNotEqual('baseline_', evidence['parent'])
            self.assertEqual(evidence['parent'], evidence['child'])
