<?php

declare(strict_types=1);

use DigiForge\Core\Settings;
use DigiForge\Integrations\Repository;
use DigiForge\Operations\HostingerRecoveryProvider;
use DigiForge\Operations\RecoveryEvidence;
use DigiForge\Operations\RecoveryBackupIdentityMarker;
use DigiForge\Operations\RecoveryBackupIdentityBinding;
use DigiForge\Operations\RecoveryOrchestrator;
use DigiForge\Operations\RecoveryProviderRegistry;

final class HostingerRecoveryTransportTest extends WP_UnitTestCase
{
    private Repository $repository;
    private int $id;
    private string $key;
    private array $config;
    private int $calls = 0;
    private mixed $response;
    private array $request = [];

    public function set_up(): void
    {
        parent::set_up();
        DigiForge\Core\Activator::activate();
        Settings::protectProduction();
        global $wpdb;
        // Ledger receipts intentionally commit independently of PHPUnit's transaction.
        $wpdb->query('SET autocommit = 1');
        $wpdb->update(DigiForge\Database\Tables::integrations(),['enabled'=>0],['provider'=>'hostinger','environment'=>'test']);
        $this->key = wp_generate_uuid4();
        delete_option('digiforge_recovery_orchestration');
        delete_option('digiforge_recovery_dispatch_interlock');
        self::assertIsArray(RecoveryBackupIdentityMarker::prepare($this->key));
        self::assertTrue(RecoveryEvidence::storeDatabaseBackup(['identifier'=>'backup-'.$this->key,'captured_at'=>gmdate('c'),'location'=>'backup.sql','verification_method'=>'retrieval','verified_at'=>gmdate('c'),'verified_by'=>'test','retrievable'=>true]));
        self::assertIsArray(RecoveryBackupIdentityBinding::bind($this->key,'backup-'.$this->key));
        self::assertTrue(RecoveryEvidence::storePluginPackage(['identifier'=>'package-'.$this->key,'version'=>'test','source_commit'=>str_repeat('a',40),'sha256'=>str_repeat('b',64),'location'=>'package.zip','retrievable'=>true,'checksum_verified'=>true]));
        $this->config = ['hosting_account'=>'test_account','staging_domain'=>'digiforgestaging.converentis.com','archive_path'=>'backup.zip','database_path'=>'backup.sql','database_backup_identifier'=>'backup-'.$this->key,'plugin_package_identifier'=>'package-'.$this->key];
        $this->config['artifact_evidence_hash']=RecoveryOrchestrator::artifactEvidenceHash(RecoveryEvidence::snapshot());
        $this->repository = new Repository();
        $created=$this->repository->create(['provider'=>'hostinger','environment'=>'test','connection_key'=>$this->key,'display_name'=>'Recovery test','status'=>'CONFIGURED','enabled'=>false,'config'=>$this->config]);
        self::assertIsArray($created);
        $this->id=(int)$created['id'];
        self::assertTrue($this->repository->storeSecret($this->id,'api_token','test-hostinger-sensitive-token')===true);
        self::assertIsArray($this->repository->setEnabled($this->id,true));
        RecoveryProviderRegistry::reset();
        RecoveryProviderRegistry::register(new HostingerRecoveryProvider());
        $this->response=['headers'=>[],'body'=>'{"message":"Request accepted"}','response'=>['code'=>200,'message'=>'OK'],'cookies'=>[],'filename'=>null];
        add_filter('pre_http_request',[$this,'http'],10,3);
    }

    public function tear_down(): void
    {
        remove_filter('pre_http_request',[$this,'http'],10);
        RecoveryProviderRegistry::reset();
        global $wpdb;
        $wpdb->query('ROLLBACK');
        $wpdb->query('SET autocommit = 1');
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'digiforge_recovery_claim_%' OR option_name LIKE 'digiforge_hostinger_dispatch_%' OR option_name LIKE 'digiforge_recovery_backup_marker_%' OR option_name LIKE 'digiforge_recovery_backup_binding_%' OR option_name = 'digiforge_recovery_dispatch_interlock'");
        $wpdb->update(DigiForge\Database\Tables::integrations(),['enabled'=>0],['provider'=>'hostinger','environment'=>'test']);
        Settings::protectProduction();
        parent::tear_down();
    }

    public function http(mixed $preempt,array $args,string $url): mixed
    {
        ++$this->calls;
        $this->request=['args'=>$args,'url'=>$url];
        self::assertSame('UNKNOWN',RecoveryOrchestrator::snapshot()['state']);
        self::assertTrue(RecoveryOrchestrator::snapshot()['reconciliation_required']);
        return $this->response;
    }

    private function plan(string $target='https://digiforgestaging.converentis.com/'): array|WP_Error
    {
        return RecoveryOrchestrator::plan(['operation_key'=>$this->key,'backup_identity_operation_key'=>$this->key,'target_environment'=>'staging','target_site_url'=>$target]);
    }

    private function bindCurrentBackupToKey(): void
    {
        $snapshot=RecoveryEvidence::snapshot();
        $backup=$snapshot['database_backup'];
        self::assertIsArray(RecoveryBackupIdentityMarker::prepare($this->key));
        $backup['captured_at']=gmdate('c');
        $backup['verified_at']=gmdate('c');
        $backup['retrievable']=true;
        self::assertTrue(RecoveryEvidence::storeDatabaseBackup($backup) || RecoveryEvidence::snapshot()['database_backup']['identifier']===$backup['identifier']);
        self::assertIsArray(RecoveryBackupIdentityBinding::bind($this->key,(string)$backup['identifier']));
        $evidence=RecoveryEvidence::snapshot();
        $this->config['database_backup_identifier']=(string)$backup['identifier'];
        $this->config['plugin_package_identifier']=(string)($evidence['plugin_package']['identifier'] ?? '');
        $this->config['artifact_evidence_hash']=RecoveryOrchestrator::artifactEvidenceHash($evidence);
        self::assertIsArray($this->repository->update($this->id,['config'=>$this->config]));
    }

    public function testDocumentedAcceptanceHasNoReferenceAndCannotBeReplayedOrCreatePass(): void
    {
        $before=get_option('digiforge_recovery_drill_evidence',[]);
        self::assertIsArray($this->plan());
        $result=RecoveryOrchestrator::execute($this->key);
        self::assertInstanceOf(WP_Error::class,$result);
        self::assertSame('digiforge_hostinger_reference_missing',$result->get_error_code());
        self::assertSame('UNKNOWN',RecoveryOrchestrator::snapshot()['state']);
        self::assertSame('hostinger',RecoveryOrchestrator::snapshot()['provider']);
        self::assertSame('https://developers.hostinger.com/api/hosting/v1/accounts/test_account/websites/digiforgestaging.converentis.com/wordpress/import',$this->request['url']);
        self::assertSame(['archive_path'=>'backup.zip','sql_path'=>'backup.sql'],json_decode($this->request['args']['body'],true));
        self::assertArrayNotHasKey('Idempotency-Key',$this->request['args']['headers']);
        self::assertSame(0,$this->request['args']['redirection']);
        self::assertTrue($this->request['args']['sslverify']);
        self::assertTrue($this->request['args']['reject_unsafe_urls']);
        self::assertInstanceOf(WP_Error::class,RecoveryOrchestrator::execute($this->key));
        self::assertSame(1,$this->calls);
        self::assertSame($before,get_option('digiforge_recovery_drill_evidence',[]));
        self::assertFalse(RecoveryOrchestrator::snapshot()['commerce_execution_authorized']);
        self::assertStringNotContainsString('test-hostinger-sensitive-token',wp_json_encode(RecoveryOrchestrator::snapshot()));
        self::assertStringNotContainsString('test-hostinger-sensitive-token',wp_json_encode($result));
        $this->key=wp_generate_uuid4();
        self::assertInstanceOf(WP_Error::class,$this->plan());
    }

    public function testTimeoutAndExceptionNeverRedispatch(): void
    {
        $this->response=new WP_Error('timeout','test-hostinger-sensitive-token');
        self::assertIsArray($this->plan());
        self::assertInstanceOf(WP_Error::class,RecoveryOrchestrator::execute($this->key));
        self::assertInstanceOf(WP_Error::class,RecoveryOrchestrator::execute($this->key));
        self::assertSame(1,$this->calls);
        self::assertSame('UNKNOWN',RecoveryOrchestrator::snapshot()['state']);
    }

    public function testPreDispatchPersistenceFailureMakesNoHttpCall(): void
    {
        self::assertIsArray($this->plan());
        $filter=static fn($value,$old)=>$old;
        add_filter('pre_update_option_digiforge_recovery_orchestration',$filter,10,2);
        try { self::assertInstanceOf(WP_Error::class,RecoveryOrchestrator::execute($this->key)); }
        finally {remove_filter('pre_update_option_digiforge_recovery_orchestration',$filter);}
        self::assertSame(0,$this->calls);
        self::assertInstanceOf(WP_Error::class,RecoveryOrchestrator::execute($this->key));
        self::assertSame(0,$this->calls);
    }

    public function testDirectAdapterCannotDispatch(): void
    {
        self::assertIsArray($this->plan());
        self::assertInstanceOf(WP_Error::class,(new HostingerRecoveryProvider())->execute(RecoveryOrchestrator::snapshot()));
        self::assertSame(0,$this->calls);
    }

    public function testWrongCurrentAndNonRootTargetsNeverDispatch(): void
    {
        foreach(['https://wrong.example.org/','https://digiforgestaging.converentis.com/subsite','http://digiforgestaging.converentis.com/','https://digiforgestaging.converentis.com:8443/','https://user@digiforgestaging.converentis.com/',home_url('/'),'https://digiforge.converentis.com/'] as $url){
            delete_option('digiforge_recovery_orchestration');
            delete_option('digiforge_recovery_dispatch_interlock');
            $this->key=wp_generate_uuid4();
            $plan=$this->plan($url);
            if(is_array($plan))self::assertInstanceOf(WP_Error::class,RecoveryOrchestrator::execute($this->key));
        }
        self::assertSame(0,$this->calls);
    }

    public function testMissingArtifactCredentialAndDisabledConnectorNeverDispatch(): void
    {
        self::assertIsArray($this->plan());
        $this->config['archive_path']='';
        self::assertIsArray($this->repository->update($this->id,['config'=>$this->config]));
        self::assertInstanceOf(WP_Error::class,RecoveryOrchestrator::execute($this->key));
        self::assertSame(0,$this->calls);
    }
    public function testCredentialRemovedDisabledConnectorAndStaleEvidenceMakeNoHttp(): void
    {
        self::assertIsArray($this->plan());
        self::assertIsArray($this->repository->setEnabled($this->id,false));
        self::assertInstanceOf(WP_Error::class,RecoveryOrchestrator::execute($this->key));
        self::assertSame(0,$this->calls);
        self::assertIsArray($this->repository->setEnabled($this->id,true));
        global $wpdb;
        $wpdb->delete(DigiForge\Database\Tables::integration_secrets(),['integration_id'=>$this->id]);
        self::assertInstanceOf(WP_Error::class,RecoveryOrchestrator::execute($this->key));
        self::assertSame(0,$this->calls);
        delete_option('digiforge_recovery_dispatch_interlock');
        delete_option('digiforge_recovery_orchestration');
        $this->key=wp_generate_uuid4();
        $this->bindCurrentBackupToKey();
        self::assertIsArray($this->plan());
        delete_option('digiforge_recovery_database_backup_evidence');
        self::assertInstanceOf(WP_Error::class,RecoveryOrchestrator::execute($this->key));
        self::assertSame(0,$this->calls);
    }

    public function testMalformedAndEchoedSecretResponsesRemainUnknown(): void
    {
        $this->response['body']='{"id":"test-hostinger-sensitive-token"}';
        self::assertIsArray($this->plan());
        $result=RecoveryOrchestrator::execute($this->key);
        self::assertInstanceOf(WP_Error::class,$result);
        self::assertStringNotContainsString('test-hostinger-sensitive-token',wp_json_encode($result));
        self::assertSame('',RecoveryOrchestrator::snapshot()['provider_operation_reference']);
        self::assertInstanceOf(WP_Error::class,RecoveryOrchestrator::execute($this->key));
        self::assertSame(1,$this->calls);
    }

    public function testPostDispatchPersistenceFailureRetainsUnknownClaim(): void
    {
        RecoveryProviderRegistry::reset();
        $adapter=new class implements DigiForge\Operations\RecoveryProviderAdapter {
            public int $calls=0;
            public function providerSlug(): string {return 'hostinger';}
            public function capability(): array {return ['available'=>true,'reason'=>'test'];}
            public function execute(array $plan): array|WP_Error {
                ++$this->calls;
                add_filter('pre_update_option_digiforge_recovery_orchestration',[$this,'fail'],10,2);
                return ['state'=>'in_progress','provider'=>'hostinger','provider_operation_reference'=>'test-ref'];
            }
            public function fail($value,$old) {return $old;}
            public function reconcile(array $plan): array|WP_Error {return new WP_Error('unverified');}
        };
        RecoveryProviderRegistry::register($adapter);
        self::assertIsArray($this->plan());
        try {self::assertInstanceOf(WP_Error::class,RecoveryOrchestrator::execute($this->key));}
        finally {remove_filter('pre_update_option_digiforge_recovery_orchestration',[$adapter,'fail']);}
        self::assertSame('UNKNOWN',RecoveryOrchestrator::snapshot()['state']);
        self::assertInstanceOf(WP_Error::class,RecoveryOrchestrator::execute($this->key));
        self::assertSame(1,$adapter->calls);
    }

    public function testThrownTransportAndReentrantCallCannotRetry(): void
    {
        $filter=function($preempt,$args,$url) {
            self::assertInstanceOf(WP_Error::class,RecoveryOrchestrator::execute($this->key));
            throw new RuntimeException('test-hostinger-sensitive-token');
        };
        remove_filter('pre_http_request',[$this,'http'],10);
        add_filter('pre_http_request',$filter,10,3);
        self::assertIsArray($this->plan());
        try {$result=RecoveryOrchestrator::execute($this->key);}
        finally {remove_filter('pre_http_request',$filter);}
        self::assertInstanceOf(WP_Error::class,$result);
        self::assertStringNotContainsString('test-hostinger-sensitive-token',wp_json_encode($result));
        self::assertSame('UNKNOWN',RecoveryOrchestrator::snapshot()['state']);
        self::assertInstanceOf(WP_Error::class,RecoveryOrchestrator::execute($this->key));
    }

    public function testInsertOnlyLedgerIgnoresStaleCachesAndDifferentReplayValues(): void
    {
        $name='digiforge_recovery_claim_'.hash('sha256',wp_generate_uuid4());
        wp_cache_set($name,false,'options');
        self::assertTrue(DigiForge\Operations\RecoveryDispatchLedger::insert($name,['winner'=>'first']));
        wp_cache_set($name,false,'options');
        self::assertFalse(DigiForge\Operations\RecoveryDispatchLedger::insert($name,['winner'=>'second']));
        self::assertSame(['winner'=>'first'],DigiForge\Operations\RecoveryDispatchLedger::read($name));
    }

    public function testStopAllAloneCannotAuthorizeRecovery(): void
    {
        self::assertIsArray($this->plan());
        self::assertTrue(Settings::activateResearch());
        self::assertTrue(Settings::set('stop_all',true));
        self::assertTrue(Settings::safety_locked());
        self::assertFalse(RecoveryOrchestrator::safetyLocked());
        self::assertInstanceOf(WP_Error::class,RecoveryOrchestrator::execute($this->key));
        self::assertSame(0,$this->calls);
    }

    public function testSameIdentifierDifferentArtifactCannotDispatch(): void
    {
        self::assertIsArray($this->plan());
        $package=RecoveryEvidence::snapshot()['plugin_package'];
        $package['sha256']=str_repeat('c',64);
        self::assertTrue(RecoveryEvidence::storePluginPackage($package));
        self::assertInstanceOf(WP_Error::class,RecoveryOrchestrator::execute($this->key));
        self::assertInstanceOf(WP_Error::class,$this->plan());
        self::assertSame(0,$this->calls);
    }

    public function testUnsafeArtifactPathCannotDispatch(): void
    {
        foreach(['../backup.zip','/backup.zip','https://bad.example/backup.zip'] as $path){
            delete_option('digiforge_recovery_dispatch_interlock');
            delete_option('digiforge_recovery_orchestration');
            $this->key=wp_generate_uuid4();
            $this->config['archive_path']=$path;
            $this->bindCurrentBackupToKey();
            self::assertIsArray($this->repository->update($this->id,['config'=>$this->config]));
            self::assertIsArray($this->plan());
            self::assertInstanceOf(WP_Error::class,RecoveryOrchestrator::execute($this->key));
        }
        self::assertSame(0,$this->calls);
    }

    public function testMultipleConfiguredConnectorsFailClosed(): void
    {
        $second=$this->repository->create(['provider'=>'hostinger','environment'=>'test','connection_key'=>wp_generate_uuid4(),'display_name'=>'Second','status'=>'CONFIGURED','enabled'=>false,'config'=>$this->config]);
        self::assertIsArray($second);
        self::assertTrue($this->repository->storeSecret((int)$second['id'],'api_token','second-test-token')===true);
        self::assertIsArray($this->repository->setEnabled((int)$second['id'],true));
        self::assertIsArray($this->plan());
        self::assertInstanceOf(WP_Error::class,RecoveryOrchestrator::execute($this->key));
        self::assertSame(0,$this->calls);
    }

    public function testMalformedAndNonSuccessResponsesRequireReconciliation(): void
    {
        foreach([[200,'not-json'],[500,'{"message":"test-hostinger-sensitive-token"}']] as [$status,$body]){
            delete_option('digiforge_recovery_dispatch_interlock');
            delete_option('digiforge_recovery_orchestration');
            $this->key=wp_generate_uuid4();
            $this->bindCurrentBackupToKey();
            $this->response['response']['code']=$status;
            $this->response['body']=$body;
            self::assertIsArray($this->plan());
            $result=RecoveryOrchestrator::execute($this->key);
            self::assertInstanceOf(WP_Error::class,$result);
            self::assertSame('UNKNOWN',RecoveryOrchestrator::snapshot()['state']);
            self::assertStringNotContainsString('test-hostinger-sensitive-token',wp_json_encode($result));
            self::assertInstanceOf(WP_Error::class,RecoveryOrchestrator::execute($this->key));
        }
        self::assertSame(2,$this->calls);
    }

    public function testAdapterClaimPersistenceFailureNeverCallsHttp(): void
    {
        self::assertIsArray($this->plan());
        global $wpdb;
        $filter=static function($query) use ($wpdb) {
            if(str_contains($query,'INSERT INTO') && str_contains($query,'digiforge_hostinger_dispatch_'))return "INSERT INTO missing_recovery_test_table (id) VALUES (1)";
            return $query;
        };
        add_filter('query',$filter);
        try {self::assertInstanceOf(WP_Error::class,RecoveryOrchestrator::execute($this->key));}
        finally {remove_filter('query',$filter);}
        self::assertSame(0,$this->calls);
        self::assertSame('UNKNOWN',RecoveryOrchestrator::snapshot()['state']);
        self::assertInstanceOf(WP_Error::class,RecoveryOrchestrator::execute($this->key));
    }

    public function testUnreadableLedgerReportsUnknownExternalActionsAndBlocksDispatch(): void
    {
        self::assertIsArray($this->plan());
        $filter=static function($query) {
            if(str_contains($query,'SELECT option_value FROM') && str_contains($query,'digiforge_recovery_dispatch_interlock'))return 'SELECT option_value FROM missing_recovery_test_table';
            return $query;
        };
        global $wpdb;
        $previous=$wpdb->suppress_errors(true);
        add_filter('query',$filter);
        try {
            $snapshot=RecoveryOrchestrator::snapshot();
            self::assertSame('UNKNOWN',$snapshot['state']);
            self::assertNull($snapshot['external_actions_performed']);
            self::assertTrue($snapshot['reconciliation_required']);
            self::assertInstanceOf(WP_Error::class,RecoveryOrchestrator::execute($this->key));
        } finally {remove_filter('query',$filter);$wpdb->suppress_errors($previous);}
        self::assertSame(0,$this->calls);
    }

    public function testDispatchIdentitySurvivesCallerRollback(): void
    {
        global $wpdb;
        $name='digiforge_recovery_claim_'.hash('sha256',wp_generate_uuid4());
        $wpdb->query('START TRANSACTION');
        try {self::assertTrue(DigiForge\Operations\RecoveryDispatchLedger::insert($name,['state'=>'UNKNOWN']));}
        finally {$wpdb->query('ROLLBACK');}
        self::assertSame(['state'=>'UNKNOWN'],DigiForge\Operations\RecoveryDispatchLedger::read($name));
        self::assertFalse(DigiForge\Operations\RecoveryDispatchLedger::insert($name,['state'=>'retry']));
    }

    public function testOverlappingWorkersHaveExactlyOneDurableClaimWinner(): void
    {
        $directory=sys_get_temp_dir().'/digiforge-ledger-race-'.wp_generate_uuid4();
        mkdir($directory);
        $name='digiforge_recovery_claim_'.hash('sha256',wp_generate_uuid4());
        $worker=$directory.'/worker.php';
        $bootstrap=var_export(__DIR__.'/bootstrap.php',true);
        $autoload=var_export(dirname(__DIR__,2).'/vendor/autoload.php',true);
        file_put_contents($worker, '<?php putenv("WP_TESTS_SKIP_INSTALL=1"); require '.$autoload.'; require '.$bootstrap.'; file_put_contents($argv[2].".ready", "1"); $deadline=microtime(true)+10; while (!file_exists($argv[3])) { if(microtime(true)>$deadline)exit(2); usleep(10000); } $ok=\\DigiForge\\Operations\\RecoveryDispatchLedger::insert($argv[1],["worker"=>$argv[2],"state"=>"UNKNOWN"]); file_put_contents($argv[2],$ok?"1":"0");');
        $processes=[];
        try {
            for($i=0;$i<2;++$i){
                $processes[]=proc_open([PHP_BINARY,$worker,$name,$directory.'/result'.$i,$directory.'/go'],[0=>['file','/dev/null','r'],1=>['file',$directory.'/log'.$i,'w'],2=>['file',$directory.'/error'.$i,'w']],$pipes);
                self::assertIsResource($processes[$i]);
            }
            $deadline=microtime(true)+10;
            while ((!file_exists($directory.'/result0.ready') || !file_exists($directory.'/result1.ready')) && microtime(true)<$deadline)usleep(10000);
            self::assertFileExists($directory.'/result0.ready');
            self::assertFileExists($directory.'/result1.ready');
            file_put_contents($directory.'/go','1');
            foreach($processes as $index=>$process){self::assertSame(0,proc_close($process));unset($processes[$index]);}
            self::assertSame(1,(int)file_get_contents($directory.'/result0')+(int)file_get_contents($directory.'/result1'));
            self::assertSame('UNKNOWN',DigiForge\Operations\RecoveryDispatchLedger::read($name)['state']);
        } finally {
            foreach($processes as $process){proc_terminate($process);proc_close($process);}
            foreach(glob($directory.'/*') as $file)unlink($file);
            rmdir($directory);
        }
    }

}
