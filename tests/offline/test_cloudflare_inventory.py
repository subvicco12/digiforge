"""Mock-only Cloudflare inventory; never reads environment credentials or opens sockets."""
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
CLIENT = ROOT / 'bin/cloudflare-inventory/client.py'
ACCOUNT = 'a' * 32
TOKEN = 'synthetic-inventory-fixture-token-only'

class FakeTransport:
    def __init__(self, responses=None):
        self.responses = responses or {}
        self.calls = []
    def get(self, path, token):
        self.calls.append(path)
        value = self.responses.get(path, (200, json.dumps({'success': True, 'result': []}).encode(), None))
        if isinstance(value, Exception):
            raise value
        return value

def response(value, status=200, retry=None):
    return status, json.dumps(value).encode(), retry

class CloudflareInventoryTest(unittest.TestCase):
    def setUp(self):
        self.assertTrue(CLIENT.is_file(), 'Fixed Cloudflare inventory client required')
        spec = importlib.util.spec_from_file_location('cf_inventory', CLIENT)
        self.m = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(self.m)
        self.transport = FakeTransport()
        self.client = self.m.Client(TOKEN, self.transport)
    def test_fixed_allowlist(self):
        for op in self.m.OPERATIONS:
            path = self.m.endpoint(op, ACCOUNT if op not in ('TOKEN_VERIFY', 'ACCOUNTS') else None)
            self.assertTrue(path.startswith('/client/v4/'))
            self.assertNotIn('https:', path)
        self.assertEqual(6, len(self.m.OPERATIONS))
    def test_unknown_operation_and_url_rejected(self):
        for op in ('https://evil.invalid', '../workers', 'DEPLOY', 'POST', 'DNS_WRITE'):
            with self.assertRaises(ValueError): self.m.endpoint(op, ACCOUNT)
        self.assertEqual([], self.transport.calls)
    def test_non_get_denied(self):
        for method in ('POST','PUT','PATCH','DELETE','HEAD','get'):
            with self.assertRaises(ValueError): self.client.call('ACCOUNTS', method=method)
        self.assertEqual([], self.transport.calls)
    def test_account_identity_validation(self):
        for account in (None, '../x', 'x'*32, 'a'*31, 'https://evil.invalid'):
            with self.assertRaises(ValueError): self.m.endpoint('WORKERS', account)
    def test_permission_errors_distinct(self):
        for status, expected in ((401,'UNAUTHORIZED'),(403,'FORBIDDEN'),(404,'NOT_FOUND'),(500,'PROVIDER_ERROR'),(302,'REDIRECT_DENIED')):
            c=self.m.Client(TOKEN, FakeTransport({self.m.endpoint('ACCOUNTS'):(status,b'private-body',None)}))
            self.assertEqual(expected,c.call('ACCOUNTS')['state'])
    def test_timeout_redacted(self):
        c=self.m.Client(TOKEN, FakeTransport({self.m.endpoint('ACCOUNTS'):TimeoutError(TOKEN)}))
        r=c.call('ACCOUNTS');self.assertEqual('TIMEOUT',r['state']);self.assertNotIn(TOKEN,json.dumps(r))
    def test_transport_error_redacted(self):
        c=self.m.Client(TOKEN, FakeTransport({self.m.endpoint('ACCOUNTS'):OSError(TOKEN)}))
        self.assertEqual('TRANSPORT_ERROR',c.call('ACCOUNTS')['state'])
    def test_malformed_json(self):
        for body in (b'{',b'null',b'[]',b'{"success":true,"result":[],"success":false}'):
            c=self.m.Client(TOKEN, FakeTransport({self.m.endpoint('ACCOUNTS'):(200,body,None)}))
            self.assertEqual('MALFORMED',c.call('ACCOUNTS')['state'])
    def test_oversized_response(self):
        c=self.m.Client(TOKEN, FakeTransport({self.m.endpoint('ACCOUNTS'):(200,b'x'*1048577,None)}))
        self.assertEqual('OVERSIZED',c.call('ACCOUNTS')['state'])
    def test_rate_limit_no_retry(self):
        t=FakeTransport({self.m.endpoint('ACCOUNTS'):(429,b'{}',120)});c=self.m.Client(TOKEN,t)
        self.assertEqual(120,c.call('ACCOUNTS')['retry_after_seconds'])
        self.assertEqual('SKIPPED_RATE_LIMIT',c.call('ACCOUNTS')['state']);self.assertEqual(1,len(t.calls))
    def test_budget(self):
        for _ in range(6):self.client.call('ACCOUNTS')
        self.assertEqual('BUDGET_EXHAUSTED',self.client.call('ACCOUNTS')['state']);self.assertEqual(6,len(self.transport.calls))
    def test_provider_messages_and_token_not_exported(self):
        t=FakeTransport({self.m.endpoint('ACCOUNTS'):response({'success':False,'errors':[{'code':1000,'message':TOKEN,'source':TOKEN}]})})
        r=self.m.Client(TOKEN,t).call('ACCOUNTS');self.assertNotIn(TOKEN,json.dumps(r));self.assertEqual('API_ERROR',r['state'])
    def test_invalid_token_header_rejected(self):
        for token in ('','short','a'*20+'\r\nHost:evil', 'x'*4097):
            with self.assertRaises(ValueError):self.m.Client(token,self.transport)
    def inventory_transport(self):
        return FakeTransport({self.m.endpoint('SETTINGS',ACCOUNT):response({'success':True,'result':{}}),self.m.endpoint('TOKEN_VERIFY'):response({'success':True,'result':{'id':'f'*32,'status':'active','expires_on':'2099-01-01T00:00:00Z'}}),self.m.endpoint('ACCOUNTS'):response({'success':True,'result':[{'id':ACCOUNT,'name':'private-owner-name'}],'result_info':{'page':1,'per_page':5,'total_count':1}})})
    def test_selected_account_only(self):
        t=self.inventory_transport();r=self.m.inventory(self.m.Client(TOKEN,t),ACCOUNT)
        self.assertEqual(6,len(t.calls));self.assertEqual('SCOPED_INVENTORY_OBSERVED',r['connection']);self.assertNotIn(ACCOUNT,json.dumps(r));self.assertNotIn('private-owner-name',json.dumps(r))
    def test_no_account_selection_does_not_probe_resources(self):
        t=self.inventory_transport();r=self.m.inventory(self.m.Client(TOKEN,t),None)
        self.assertEqual(2,len(t.calls));self.assertEqual('ACCOUNT_SELECTION_REQUIRED',r['connection'])
    def test_account_not_available_does_not_probe(self):
        t=self.inventory_transport();r=self.m.inventory(self.m.Client(TOKEN,t),'b'*32)
        self.assertEqual(2,len(t.calls));self.assertEqual('ACCOUNT_UNAVAILABLE',r['connection'])
    def test_invalid_or_expired_token_stops(self):
        for state in ('expired','disabled','unexpected'):
            t=FakeTransport({self.m.endpoint('TOKEN_VERIFY'):response({'success':True,'result':{'status':state}})})
            r=self.m.inventory(self.m.Client(TOKEN,t),ACCOUNT);self.assertNotEqual('SCOPED_INVENTORY_OBSERVED',r['connection']);self.assertEqual(1,len(t.calls))
    def test_wrong_result_container(self):
        for op in ('ACCOUNTS','WORKERS','NAMESPACES','SUBSCRIPTIONS'):
            t=FakeTransport({self.m.endpoint(op, ACCOUNT if op!='ACCOUNTS' else None):response({'success':True,'result':{'unexpected':TOKEN}})})
            self.assertEqual('MALFORMED',self.m.Client(TOKEN,t).call(op,ACCOUNT if op!='ACCOUNTS' else None)['state'])
    def test_resource_names_and_bindings_redacted(self):
        t=FakeTransport({self.m.endpoint('WORKERS',ACCOUNT):response({'success':True,'result':[{'id':'unrelated-project','bindings':[{'secret':TOKEN}]}]})})
        r=self.m.Client(TOKEN,t).call('WORKERS',ACCOUNT)
        self.assertEqual(1,r['observed_count']);self.assertNotIn('unrelated-project',json.dumps(r));self.assertNotIn(TOKEN,json.dumps(r));self.assertEqual('UNVERIFIED',r['entitlement'])
    def test_no_quota_or_free_plan_inference(self):
        r=self.client.call('SUBSCRIPTIONS',ACCOUNT)
        self.assertEqual('UNVERIFIED',r['entitlement']);self.assertEqual('UNVERIFIED',r['available_quota'])
    def test_incomplete_account_inventory_not_full_count(self):
        t=FakeTransport({self.m.endpoint('ACCOUNTS'):response({'success':True,'result':[{'id':ACCOUNT}],'result_info':{'total_count':7}})})
        r=self.m.Client(TOKEN,t).call('ACCOUNTS');self.assertTrue(r['partial']);self.assertEqual(1,r['observed_count'])
    def test_https_transport_fixed_host_no_redirect_or_proxy(self):
        class Resp:
            status=302
            def getheader(self,k):return None
            def read(self,n):self.limit=n;return b'{}'
        class Conn:
            def request(self,*args,**kw):self.args=args;self.kw=kw
            def getresponse(self):return Resp()
            def close(self):pass
        conn=Conn()
        with patch.object(self.m.http.client,'HTTPSConnection',return_value=conn) as ctor:
            result=self.m.HttpsTransport().get(self.m.endpoint('TOKEN_VERIFY'),TOKEN)
            self.assertEqual('api.cloudflare.com',ctor.call_args.args[0]);self.assertEqual(10,ctor.call_args.kwargs['timeout']);self.assertEqual('GET',conn.args[0]);self.assertEqual(302,result[0]);self.assertIsNotNone(ctor.call_args.kwargs['context'])
    def test_report_write_exclusive_and_private(self):
        with tempfile.TemporaryDirectory() as tmp:
            path=self.m.write_report(Path(tmp),{'state':'OK'})
            self.assertEqual(0o600,path.stat().st_mode&0o777)
            with self.assertRaises(FileExistsError):self.m.write_report(Path(tmp),{'state':'NO'})
    def test_workflow_disabled_and_secret_step_scoped(self):
        w=(ROOT/'.github/workflows/cloudflare-account-inventory.yml').read_text()
        self.assertIn('workflow_dispatch:',w);self.assertIn('if: ${{ false }}',w)
        self.assertNotIn('pull_request:',w);self.assertNotIn('schedule:',w);self.assertNotIn('push:',w)
        self.assertIn('contents: read',w);self.assertEqual(1,w.count('secrets.CLOUDFLARE_READONLY_API_TOKEN'))
        self.assertIn('cloudflare-inventory-readonly',w);self.assertIn('github.workflow_sha',w)
        self.assertNotIn('actions/checkout@',w);self.assertNotIn('wrangler',w.lower())

    def test_expired_timestamp_rejects_active_status(self):
        t=FakeTransport({self.m.endpoint('TOKEN_VERIFY'):response({'success':True,'result':{'status':'active','expires_on':'2020-01-01T00:00:00Z'}})})
        self.assertEqual('TOKEN_NOT_QUALIFIED',self.m.inventory(self.m.Client(TOKEN,t))['connection']);self.assertEqual(1,len(t.calls))
    def test_actual_transport_rejects_arbitrary_paths_before_network(self):
        with patch.object(self.m.http.client,'HTTPSConnection') as conn:
            for path in ('https://evil.invalid/', '/client/v4/zones', '/client/v4/accounts/'+ACCOUNT+'/workers/scripts/secret', '/client/v4/user/tokens/verify?url=evil'):
                with self.assertRaises(ValueError):self.m.HttpsTransport().get(path,TOKEN)
            conn.assert_not_called()
    def test_symlink_report_directory_denied(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp);(root/'target').mkdir();(root/'digiforge-cf-inventory').symlink_to(root/'target')
            with self.assertRaises(ValueError):self.m.write_report(root,{'state':'OK'})
    def test_main_does_not_log_secret_or_exception(self):
        import io
        with tempfile.TemporaryDirectory() as tmp, patch.dict(self.m.os.environ,{'CLOUDFLARE_READONLY_API_TOKEN':TOKEN,'RUNNER_TEMP':tmp},clear=True), patch.object(self.m,'inventory',side_effect=OSError(TOKEN)), patch('sys.stdout',new_callable=io.StringIO) as output:
            self.assertEqual(1,self.m.main());self.assertNotIn(TOKEN,output.getvalue());self.assertEqual('INVENTORY_FAILED_SAFE\n',output.getvalue())
    def test_main_missing_secret_never_calls_transport(self):
        with patch.dict(self.m.os.environ,{},clear=True), patch.object(self.m,'HttpsTransport') as transport, patch('sys.stdout'):
            self.assertEqual(1,self.m.main());transport.assert_not_called()
    def verified_execution_program(self):
        text=(ROOT/'.github/workflows/cloudflare-account-inventory.yml').read_text()
        block=text.split('      - name: Execute only bounded',1)[1].split('      - name: Retain only',1)[0]
        return '\n'.join(line[10:] for line in block.split("<<'PY'\n",1)[1].split('\n          PY',1)[0].splitlines())
    def test_workflow_rejects_modified_client_bytes(self):
        import hashlib, io
        program=self.verified_execution_program()
        with tempfile.TemporaryDirectory() as tmp:
            directory=Path(tmp)/'digiforge-cf-inventory';directory.mkdir(mode=0o700)
            path=directory/'verified-client.py';path.write_text("print('UNREVIEWED')\n");path.chmod(0o600)
            with patch.dict(self.m.os.environ,{'RUNNER_TEMP':tmp,'APPROVED_CLIENT_SHA256':hashlib.sha256(b"print('APPROVED')\n").hexdigest()},clear=True),patch('sys.stdout',new_callable=io.StringIO) as output:
                with self.assertRaises(SystemExit):exec(compile(program,'workflow-test','exec'),{})
                self.assertNotIn('UNREVIEWED',output.getvalue());self.assertIn('VERIFIED_CLIENT_DENIED',output.getvalue())
    def test_workflow_replacement_race_executes_same_verified_bytes(self):
        import hashlib, io
        program=self.verified_execution_program();trusted=b"print('TRUSTED_BYTES_ONLY')\n";real_hash=hashlib.sha256
        with tempfile.TemporaryDirectory() as tmp:
            directory=Path(tmp)/'digiforge-cf-inventory';directory.mkdir(mode=0o700)
            path=directory/'verified-client.py';path.write_bytes(trusted);path.chmod(0o600)
            def replace_after_read(raw):
                path.write_text("print('REPLACEMENT_MUST_NOT_EXECUTE')\n")
                return real_hash(raw)
            with patch.dict(self.m.os.environ,{'RUNNER_TEMP':tmp,'APPROVED_CLIENT_SHA256':real_hash(trusted).hexdigest()},clear=True),patch('hashlib.sha256',side_effect=replace_after_read),patch('sys.stdout',new_callable=io.StringIO) as output:
                exec(compile(program,'workflow-test','exec'),{})
                self.assertEqual('TRUSTED_BYTES_ONLY\n',output.getvalue())

    def test_execution_branch_environment_and_literal_client_pin(self):
        import hashlib,re
        workflow=(ROOT/'.github/workflows/cloudflare-account-inventory.yml').read_text()
        self.assertIn("branch = 'refs/heads/cloudflare-inventory-execution'",workflow)
        self.assertNotIn('CLOUDFLARE_API_TOKEN',workflow+CLIENT.read_text())
        self.assertEqual([hashlib.sha256(CLIENT.read_bytes()).hexdigest()]*2,re.findall(r'APPROVED_CLIENT_SHA256: ([a-f0-9]{64})',workflow))
    def test_initial_creation_and_update_controls_are_separate(self):
        p=ROOT/'docs/engineering/cloudflare-inventory/execution-branch-proposal'
        freeze=json.loads((p/'required-ruleset.json').read_text())
        creation=json.loads((p/'required-creation-ruleset.json').read_text())
        lock=json.loads((p/'post-bootstrap-creation-lock.json').read_text())
        self.assertEqual([],freeze['bypass_actors'])
        self.assertEqual({'update','deletion'},{r['type'] for r in freeze['rules']})
        self.assertEqual([{'type':'creation'}],creation['rules'])
        self.assertEqual([{'actor_id':320672644,'actor_type':'User','bypass_mode':'always'}],creation['bypass_actors'])
        self.assertEqual([],lock['bypass_actors'])
        for rules in (freeze,creation,lock):
            self.assertEqual('active',rules['enforcement'])
            self.assertEqual(['refs/heads/cloudflare-inventory-execution'],rules['conditions']['ref_name']['include'])
    def test_isolated_proposal_has_only_verified_workflow_and_client(self):
        import hashlib
        p=ROOT/'docs/engineering/cloudflare-inventory/execution-branch-proposal'
        manifest=json.loads((p/'manifest.json').read_text())
        self.assertFalse(manifest['remote_branch_created'])
        files={str(f.relative_to(p/'files')) for f in (p/'files').rglob('*') if f.is_file()}
        self.assertEqual({'.github/workflows/cloudflare-account-inventory.yml','bin/cloudflare-inventory/client.py'},files)
        for path in files:
            data=(ROOT/path).read_bytes();self.assertEqual(data,(p/'files'/path).read_bytes());self.assertEqual(hashlib.sha256(data).hexdigest(),manifest['files'][path]['sha256'])
        raw=(p/'proposed-root-commit.txt').read_bytes()
        self.assertEqual(hashlib.sha1(b'commit '+str(len(raw)).encode()+b'\0'+raw).hexdigest(),manifest['proposed_local_root_commit'])
    def test_legacy_token_environment_variable_is_not_a_fallback(self):
        with patch.dict(self.m.os.environ,{'CLOUDFLARE_API_TOKEN':TOKEN},clear=True),patch.object(self.m,'HttpsTransport') as transport,patch('sys.stdout'):
            self.assertEqual(1,self.m.main());transport.assert_not_called()
