<?php
declare(strict_types=1);
use DigiForge\Database\Tables;
use DigiForge\NativeAuthority\WordPress\OfflineSchema;
use DigiForge\Portal\OrderLocalWorkflow;

/** Actual separate-process SIGKILL around the managed local order transaction. */
trait ManagedCrashCases
{
    public function testActualManagedProcessKilledBeforeCommitKeepsNonceAndRollsBackOnlyPlan():void
    {
        $this->managedCrashCase('before_commit');
    }
    public function testActualManagedProcessKilledAfterCommitKeepsOnePlanAndRejectsReplay():void
    {
        $this->managedCrashCase('after_commit');
    }
    private function managedCrashCase(string $phase):void
    {
        $f=$this->managedApprovedPhoto();
        $confirm=$this->confirmation(OrderLocalWorkflow::DRAFT,$f['order'],['shipping_method'=>'standard','base_production_cost'=>'10','shipping_estimate'=>'4']);
        global $wpdb;self::assertNotFalse($wpdb->query('COMMIT'));self::assertNotFalse($wpdb->query('SET autocommit=1'));
        $leases=OfflineSchema::leasesTable($wpdb);$prior=$wpdb->get_col('SELECT lease_id FROM `'.$leases.'`');self::assertIsArray($prior);
        $settings=$wpdb->get_results('SELECT * FROM '.Tables::settings().' ORDER BY setting_key',ARRAY_A);
        $jobs=$wpdb->get_results('SELECT * FROM '.Tables::jobs().' ORDER BY id',ARRAY_A);
        self::assertSame('0',(string)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Tables::fulfillment_plans().' WHERE order_id=%d',$f['order'])));
        $this->killManagedWorker($phase,$confirm);
        $plans=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.Tables::fulfillment_plans().' WHERE order_id=%d ORDER BY id',$f['order']),ARRAY_A);
        self::assertCount($phase==='after_commit'?1:0,$plans);
        if($phase==='after_commit'){
            self::assertSame('DRAFT',$plans[0]['state']);$payload=json_decode($plans[0]['canonical_payload'],true);
            self::assertSame($f['selection']['sha256'],$payload['personalization'][0]['selection_sha256']);self::assertSame(2,$payload['personalization'][0]['selection_revision']);
        }
        $nonceHash=hash('sha256',$confirm['df_confirmation_nonce']);
        $acks=$wpdb->get_col($wpdb->prepare('SELECT context FROM '.Tables::audit_log().' WHERE event_type=%s AND object_type=%s AND actor_id=%d AND JSON_VALID(context) AND JSON_UNQUOTE(JSON_EXTRACT(context,%s))=%s','portal_review_confirmation_accepted','local_review_confirmation',get_current_user_id(),'$.confirmation_nonce_sha256',$nonceHash));
        self::assertCount(1,$acks);$ack=json_decode($acks[0],true);self::assertFalse($ack['decision_recorded']);self::assertFalse($ack['external_execution_authorized']);
        $rows=$wpdb->get_results('SELECT * FROM `'.$leases.'` ORDER BY lease_id',ARRAY_A);
        $pinned=array_values(array_filter($rows,static fn(array $row):bool=>!in_array($row['lease_id'],$prior,true)&&$row['state']==='PINNED'&&(int)$row['actor_id']===get_current_user_id()&&(int)$row['reviewer_id']===$f['reviewer']));
        self::assertCount(1,$pinned,'Inspect the actual auxiliary retained-lease record left by the killed process.');
        if($phase==='before_commit')self::assertNull($pinned[0]['commit_marker']);
        else self::assertTrue(is_string($pinned[0]['commit_marker'])&&preg_match('/^[a-f0-9]{64}$/D',$pinned[0]['commit_marker'])===1);
        $replay=(new OrderLocalWorkflow())->processBrowser($confirm);self::assertWPError($replay);
        // A committed plan changes the bound inventory, so its earlier stale-evidence
        // fence denies before nonce lookup; the durable one-use audit is checked separately.
        self::assertSame($phase==='after_commit'?'portal_confirmation_stale':'portal_confirmation_replay',$replay->get_error_code());self::assertFalse($replay->get_error_data()['external_execution_authorized']);
        $after=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.Tables::fulfillment_plans().' WHERE order_id=%d ORDER BY id',$f['order']),ARRAY_A);self::assertSame($plans,$after,'Explicit replay cannot create another plan or retry the abandoned decision.');
        self::assertSame('1',(string)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Tables::audit_log().' WHERE event_type=%s AND object_type=%s AND actor_id=%d AND JSON_VALID(context) AND JSON_UNQUOTE(JSON_EXTRACT(context,%s))=%s','portal_review_confirmation_accepted','local_review_confirmation',get_current_user_id(),'$.confirmation_nonce_sha256',$nonceHash)));
        $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM `'.$leases.'` WHERE lease_id=%s',$pinned[0]['lease_id']),ARRAY_A);self::assertTrue($row===$pinned[0],'Replay cannot rewrite the killed process\'s original retained witness.');
        self::assertSame($settings,$wpdb->get_results('SELECT * FROM '.Tables::settings().' ORDER BY setting_key',ARRAY_A));self::assertSame($jobs,$wpdb->get_results('SELECT * FROM '.Tables::jobs().' ORDER BY id',ARRAY_A));
        self::assertTrue(\DigiForge\Core\Settings::safety_locked());self::assertTrue(\DigiForge\Core\Settings::get('stop_all',true));self::assertFalse(\DigiForge\Core\Settings::get('automation_armed',false));self::assertSame(0,$this->http);
    }
    private function killManagedWorker(string $phase,array $confirmation):void
    {
        global $wpdb;$dir=sys_get_temp_dir().'/df-managed-sigkill-'.wp_generate_uuid4();self::assertTrue(mkdir($dir,0700));$process=null;
        $constants=[];foreach(['DIGIFORGE_CREDENTIAL_KEY','DIGIFORGE_SHOP_ACCESS_TRUST_KEY','DIGIFORGE_SHOP_ACCESS_AUTHORITY_FILE','DIGIFORGE_PORTAL_OWNER_APPROVAL_FILE']as $name)if(defined($name))$constants[$name]=constant($name);
        $config=defined('WP_TESTS_CONFIG_FILE_PATH')?WP_TESTS_CONFIG_FILE_PATH:realpath(rtrim((string)getenv('WP_TESTS_DIR'),'/').'/wp-tests-config.php');self::assertIsString($config);self::assertFileExists($config);
        $job=['synthetic_only'=>true,'phase'=>$phase,'actor'=>get_current_user_id(),'cookies'=>$_COOKIE,'constants'=>$constants,'config'=>$config,'autoload'=>dirname((new ReflectionClass(Composer\Autoload\ClassLoader::class))->getFileName(),2).'/autoload.php','upload_path'=>$GLOBALS['wp_tests_options']['upload_path']??wp_upload_dir()['basedir'],'prefix'=>$wpdb->prefix,'home'=>home_url('/'),'confirmation'=>$confirmation,'ready'=>$dir.'/ready','network'=>$dir.'/network','unexpected'=>$dir.'/unexpected'];
        self::assertNotFalse(file_put_contents($dir.'/job.json',wp_json_encode($job)));self::assertTrue(chmod($dir.'/job.json',0600));
        foreach(['stdout','stderr']as $file){file_put_contents($dir.'/'.$file,'');chmod($dir.'/'.$file,0600);}
        try{
            $process=proc_open([PHP_BINARY,__DIR__.'/managed-crash-worker.php',$dir.'/job.json'],[0=>['file','/dev/null','r'],1=>['file',$dir.'/stdout','w'],2=>['file',$dir.'/stderr','w']],$pipes);self::assertIsResource($process);
            $deadline=microtime(true)+30;$status=proc_get_status($process);
            while(!is_file($job['ready'])&&$status['running']&&microtime(true)<$deadline){usleep(10000);$status=proc_get_status($process);}
            self::assertFileExists($job['ready'],'Managed worker must reach the actual business COMMIT boundary; test credentials and private diagnostics are never copied to assertion output.');
            $ready=json_decode((string)file_get_contents($job['ready']),true);self::assertSame($phase,$ready['phase']);self::assertSame($status['pid'],$ready['pid']);self::assertSame($phase==='after_commit',$ready['actual_commit']);
            self::assertTrue(proc_terminate($process,9),'Send actual SIGKILL; do not execute PHP shutdown/finally handlers.');
            $deadline=microtime(true)+10;do{usleep(10000);$status=proc_get_status($process);}while($status['running']&&microtime(true)<$deadline);
            self::assertFalse($status['running']);self::assertTrue($status['signaled']);self::assertSame(9,$status['termsig']);proc_close($process);$process=null;
            self::assertFileDoesNotExist($job['network'],'Neither the child nor its registered action may attempt HTTP.');self::assertFileDoesNotExist($job['unexpected']);
        }finally{
            if(is_resource($process)){proc_terminate($process,9);proc_close($process);}foreach(glob($dir.'/*')as $path)unlink($path);rmdir($dir);
        }
    }
}
