<?php
declare(strict_types=1);

use DigiForge\Database\NativeFinanceEvidenceSchema;
use DigiForge\Finance\DigitalSourceBinding;
use DigiForge\Finance\EtsyFinancialSourceParser;
use DigiForge\Finance\NativeEvidenceAccess;
use DigiForge\Finance\NativeEvidenceStore;
use DigiForge\Integrations\CredentialVault;
use DigiForge\Security\ShopAccessPolicy;
require_once __DIR__.'/FinanceDigitalBindingTest.php';

/** Original bytes below are fictional. Local reviews do not certify provider ownership. */
final class FinanceNativeEvidenceStoreTest extends WP_UnitTestCase
{
    private function fixture():array
    {
        self::assertTrue(class_exists(NativeEvidenceStore::class),'Dedicated encrypted native store is missing.');
        $existing=new FinanceDigitalBindingTest('testExactReviewedDigitalBindingHasNoPodOrExternalAuthority');
        $f=(new ReflectionMethod(FinanceDigitalBindingTest::class,'fixture'))->invoke($existing);
        foreach(['author','reviewer']as$actor)ShopAccessFixture::authorizeActor($f['actors'][$actor],ShopAccessPolicy::canonicalShops(),['manage_digiforge','manage_digiforge_finance']);
        wp_set_current_user($f['actors']['author']);
        $f['binding']=(new ReflectionMethod(FinanceDigitalBindingTest::class,'approve'))->invoke($existing,$f);
        remove_filter('query',[$this,'_create_temporary_tables']);
        try{self::assertTrue(NativeFinanceEvidenceSchema::installOffline());}finally{add_filter('query',[$this,'_create_temporary_tables']);}
        // Dispose only orphan fault-injection artifacts from earlier cases in
        // this isolated synthetic database. This is not an application cleanup API.
        global $wpdb;$wpdb->query('DELETE a FROM '.DigiForge\Database\Tables::audit_log().' a LEFT JOIN '.NativeFinanceEvidenceSchema::records()." r ON r.record_hash=a.object_id WHERE a.object_type='finance_native_evidence' AND r.id IS NULL");
        $i=$f['input'];
        $f['context']=['shop_key'=>'digital','environment'=>'test','business_id'=>$i['business_id'],'store_id'=>$i['store_id'],
            'external_shop_id'=>67757764,'external_account_id'=>123456,'integration_id'=>$i['integration_id'],'source_contract_sha256'=>EtsyFinancialSourceParser::CONTRACT_SHA256];
        $f['scope']=$f['context']+['installation_sha256'=>NativeEvidenceAccess::installation(),'binding_hash'=>$f['binding']['proposal_hash'],'binding_revision'=>$f['binding']['revision']];
        $f['document']=$this->document($f,'PRIVATE_SYNTHETIC_NATIVE_MARKER');return $f;
    }
    private function document(array $f,string $status):array
    {
        $scope=array_intersect_key($f['context'],array_flip(['shop_key','environment','business_id','store_id','external_shop_id']));
        $raw=' {"payment_id":11,"shop_id":67757764,"status":"'.$status.'"} ';
        return ['native_schema'=>'Payment','raw'=>$raw,'capture'=>$scope+['source_sha256'=>hash('sha256',$raw),'observed_at'=>gmdate('Y-m-d\TH:i:s\Z',time()-20),'source_reference'=>'local://synthetic/native-store']];
    }
    public function testEncryptedOriginalBytesReplayAndRedactedReceipt():void
    {
        $f=$this->fixture();$f['document']['raw']=str_repeat(' ',33000).$f['document']['raw'];$f['document']['capture']['source_sha256']=hash('sha256',$f['document']['raw']);
        self::assertGreaterThan(32768,strlen($f['document']['raw']));$store=new NativeEvidenceStore();$http=0;
        $network=static function($pre)use(&$http){++$http;return new WP_Error('offline');};add_filter('pre_http_request',$network);
        try{$a=$store->append($f['document'],$f['scope']);$b=$store->append($f['document'],$f['scope']);}finally{remove_filter('pre_http_request',$network);}
        self::assertNotWPError($a);self::assertSame($a,$b);self::assertSame('STORED_UNVERIFIED',$a['state']);self::assertFalse($a['accounting_import_authorized']);self::assertSame(0,$http);
        self::assertSame($a,$store->receipt($a['record_hash'],$f['scope']));
        $retry=$f['document'];$retry['capture']['observed_at']=gmdate('Y-m-d\TH:i:s\Z',time()-10);self::assertSame($a,$store->append($retry,$f['scope']));
        global $wpdb;$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.NativeFinanceEvidenceSchema::records().' WHERE record_hash=%s',$a['record_hash']),ARRAY_A);
        self::assertStringNotContainsString('PRIVATE_SYNTHETIC_NATIVE_MARKER',wp_json_encode($row));
        $plain=CredentialVault::decryptExisting($row['ciphertext'],'finance-native:v1:'.$row['record_hash']);
        $payload=json_decode($plain,true,32,JSON_THROW_ON_ERROR);self::assertSame($f['document']['raw'],$payload['document']['raw']);
        self::assertStringNotContainsString('PRIVATE_SYNTHETIC_NATIVE_MARKER',wp_json_encode($a));
    }
    public function testConflictingBytesAreQuarantinedAndCorrectionsAppend():void
    {
        $f=$this->fixture();$store=new NativeEvidenceStore();$a=$store->append($f['document'],$f['scope']);self::assertNotWPError($a);
        $changed=$this->document($f,'changed');$b=$store->append($changed,$f['scope']);self::assertNotWPError($b);self::assertSame('QUARANTINED',$b['state']);
        self::assertSame($b,$store->append($changed,$f['scope']));
        $c=$store->append($changed,$f['scope'],$a['record_hash']);self::assertNotWPError($c);self::assertSame('UNREVIEWED_CORRECTION',$c['state']);self::assertSame($a['record_hash'],$c['supersedes']);
        self::assertSame($a,$store->receipt($a['record_hash'],$f['scope']));
        self::assertWPError($store->append($changed,$f['scope'],str_repeat('f',64)));
    }
    public function testScopeDriftForeignShopsUnknownAndRevocationDeny():void
    {
        $f=$this->fixture();$store=new NativeEvidenceStore();$a=$store->append($f['document'],$f['scope']);self::assertNotWPError($a);
        foreach(['installation_sha256'=>str_repeat('a',64),'binding_hash'=>str_repeat('a',64),'binding_revision'=>999,'shop_key'=>'personalized_pod','environment'=>'production','business_id'=>999999,'store_id'=>999999,'external_shop_id'=>123,'external_account_id'=>123,'integration_id'=>999999,'source_contract_sha256'=>str_repeat('a',64)]as$key=>$value){
            $foreign=array_replace($f['scope'],[$key=>$value]);self::assertWPError($store->append($f['document'],$foreign));self::assertWPError($store->receipt($a['record_hash'],$foreign));
        }
        wp_set_current_user($f['actors']['reviewer']);$i=$f['input'];$b=$f['binding'];
        self::assertNotWPError((new DigitalSourceBinding())->decide($i['business_id'],$i['store_id'],'test',$b['revision'],$b['proposal_hash'],'REVOKED',wp_generate_uuid4()));
        wp_set_current_user($f['actors']['author']);self::assertWPError($store->receipt($a['record_hash'],$f['scope']));self::assertWPError($store->append($f['document'],$f['scope']));
    }
    public function testTamperingAndMissingHistoryFailClosed():void
    {
        $f=$this->fixture();$store=new NativeEvidenceStore();$a=$store->append($f['document'],$f['scope']);self::assertNotWPError($a);global $wpdb;
        $wpdb->update(NativeFinanceEvidenceSchema::records(),['ciphertext'=>'sodium:v1:AAAA'],['record_hash'=>$a['record_hash']]);
        self::assertWPError($store->receipt($a['record_hash'],$f['scope']));self::assertWPError($store->append($f['document'],$f['scope']));
    }
    public function testDatabaseFailureRollsBackAndRetryRecovers():void
    {
        $f=$this->fixture();$store=new NativeEvidenceStore();global $wpdb;$before=$wpdb->get_var('SELECT COUNT(*) FROM '.NativeFinanceEvidenceSchema::records());
        $fault=static fn(string $sql):string=>str_starts_with($sql,'INSERT INTO `'.NativeFinanceEvidenceSchema::claims().'`')?'SELECT FROM /* synthetic failure */':$sql;
        $old=$wpdb->suppress_errors(true);add_filter('query',$fault);
        try{self::assertWPError($store->append($f['document'],$f['scope']));}finally{remove_filter('query',$fault);$wpdb->suppress_errors($old);}
        self::assertSame($before,$wpdb->get_var('SELECT COUNT(*) FROM '.NativeFinanceEvidenceSchema::records()));
        self::assertNotWPError($store->append($f['document'],$f['scope']));
    }
    public function testMissingIdentityNeverBecomesTransactionRecognitionAndHasNoCorrection():void
    {
        $f=$this->fixture();$doc=$f['document'];$doc['native_schema']='Money';$doc['raw']='{"amount":1,"divisor":3,"currency_code":"USD"}';$doc['capture']['source_sha256']=hash('sha256',$doc['raw']);
        $store=new NativeEvidenceStore();$a=$store->append($doc,$f['scope']);self::assertNotWPError($a);self::assertSame('OBSERVATION_ONLY',$a['state']);self::assertFalse($a['transaction_recognition_authorized']);self::assertWPError($store->append($doc,$f['scope'],$a['record_hash']));
    }
    public function testRevokedActorAndUnauthenticatedReadsAreDenied():void
    {
        $f=$this->fixture();$store=new NativeEvidenceStore();$a=$store->append($f['document'],$f['scope']);self::assertNotWPError($a);
        ShopAccessFixture::reset();self::assertWPError($store->receipt($a['record_hash'],$f['scope']));wp_set_current_user(0);self::assertWPError($store->append($f['document'],$f['scope']));
    }
    /** @dataProvider missingHistory */
    public function testDeletedEvidenceNeverReopensReplay(string $missing):void
    {
        $f=$this->fixture();$store=new NativeEvidenceStore();$a=$store->append($f['document'],$f['scope']);self::assertNotWPError($a);global $wpdb;
        if($missing==='claims')$wpdb->delete(NativeFinanceEvidenceSchema::claims(),['record_hash'=>$a['record_hash']]);
        elseif($missing==='audit')$wpdb->delete(DigiForge\Database\Tables::audit_log(),['object_type'=>'finance_native_evidence','object_id'=>$a['record_hash']]);
        else $wpdb->delete(NativeFinanceEvidenceSchema::records(),['record_hash'=>$a['record_hash']]);
        self::assertWPError($store->receipt($a['record_hash'],$f['scope']));self::assertWPError($store->append($f['document'],$f['scope']));
    }
    public static function missingHistory():array{return [['claims'],['audit'],['row']];}
    public function testIndependentDatabaseWriterLockAndReentrantWriterDeny():void
    {
        $f=$this->fixture();$store=new NativeEvidenceStore();global $wpdb;
        $scopeHash=DigiForge\Finance\Validator::hash(array_diff_key($f['scope'],array_flip(['binding_hash','binding_revision'])));
        $lock='df-native:'.substr(hash('sha256',DB_NAME.':'.$wpdb->prefix.':'.$scopeHash),0,50);
        $other=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);self::assertSame('1',(string)$other->get_var($other->prepare('SELECT GET_LOCK(%s,0)',$lock)));
        try{self::assertWPError($store->append($f['document'],$f['scope']));}finally{$other->get_var($other->prepare('SELECT RELEASE_LOCK(%s)',$lock));$other->close();}
        $nested=null;$once=false;
        $hook=static function(string $sql)use(&$once,&$nested,$store,$f):string{
            if(!$once&&str_starts_with($sql,'INSERT INTO `'.NativeFinanceEvidenceSchema::records().'`')){$once=true;$nested=$store->append($f['document'],$f['scope']);}return $sql;
        };
        add_filter('query',$hook);try{$a=$store->append($f['document'],$f['scope']);}finally{remove_filter('query',$hook);}
        self::assertNotWPError($a);self::assertWPError($nested);self::assertSame($a,$store->append($f['document'],$f['scope']));
    }
    public function testBindingDriftDuringWriteRollsBackBeforeReturning():void
    {
        $f=$this->fixture();$store=new NativeEvidenceStore();global $wpdb;$before=$wpdb->get_var('SELECT COUNT(*) FROM '.NativeFinanceEvidenceSchema::records());$once=false;
        $hook=static function(string $sql)use(&$once,$f,$wpdb):string{
            if(!$once&&str_starts_with($sql,'INSERT INTO `'.NativeFinanceEvidenceSchema::records().'`')){$once=true;$wpdb->update(DigiForge\Database\Tables::integrations(),['config'=>'{}'],['id'=>$f['context']['integration_id']]);}return $sql;
        };
        add_filter('query',$hook);try{self::assertWPError($store->append($f['document'],$f['scope']));}finally{remove_filter('query',$hook);}
        self::assertSame($before,$wpdb->get_var('SELECT COUNT(*) FROM '.NativeFinanceEvidenceSchema::records()));
        self::assertNotWPError($store->append($f['document'],$f['scope']));self::assertWPError(NativeEvidenceAccess::authoritative($f['scope']));
    }
    public function testAdditiveMigrationFailureRecoveryAndNoRepeatedDdl():void
    {
        $this->fixture();global $wpdb;$records=NativeFinanceEvidenceSchema::records();$claims=NativeFinanceEvidenceSchema::claims();
        remove_filter('query',[$this,'_create_temporary_tables']);remove_filter('query',[$this,'_drop_temporary_tables']);$old=$wpdb->suppress_errors(true);
        try{
            $wpdb->query('DROP TABLE '.$claims);$wpdb->query('DROP TABLE '.$records);delete_option('digiforge_native_finance_schema');
            $fault=static fn(string $sql):string=>str_starts_with($sql,'CREATE TABLE IF NOT EXISTS '.$claims)?'SELECT FROM /* synthetic migration failure */':$sql;
            add_filter('query',$fault);try{self::assertFalse(NativeFinanceEvidenceSchema::installOffline());}finally{remove_filter('query',$fault);}
            self::assertNotSame('1',get_option('digiforge_native_finance_schema'));self::assertTrue(NativeFinanceEvidenceSchema::installOffline());
            $ddl=[];$observe=static function(string $sql)use(&$ddl):string{if(preg_match('/^(CREATE|ALTER|DROP|TRUNCATE)\b/i',$sql))$ddl[]=$sql;return $sql;};
            add_filter('query',$observe);try{self::assertTrue(NativeFinanceEvidenceSchema::installOffline());}finally{remove_filter('query',$observe);}
            self::assertSame([],$ddl);self::assertSame('0',(string)$wpdb->get_var('SELECT COUNT(*) FROM '.$records));
        }finally{$wpdb->suppress_errors($old);add_filter('query',[$this,'_create_temporary_tables']);add_filter('query',[$this,'_drop_temporary_tables']);}
    }

    /** @dataProvider finalReadCases */
    public function testAllAppendPathsRecheckAuthorityAfterEvidenceReads(bool $replay):void
    {
        $f=$this->fixture();$store=new NativeEvidenceStore();global $wpdb;
        if($replay)self::assertNotWPError($store->append($f['document'],$f['scope']));
        $reads=0;$once=false;$before=$wpdb->get_var('SELECT COUNT(*) FROM '.NativeFinanceEvidenceSchema::records());
        $hook=static function(string $sql)use(&$reads,&$once,$replay,$f,$wpdb):string{
            if(str_starts_with($sql,'SELECT * FROM '.NativeFinanceEvidenceSchema::records().' WHERE record_hash=')&&++$reads===($replay?1:2)){
                $once=true;$wpdb->update(DigiForge\Database\Tables::integrations(),['config'=>'{}'],['id'=>$f['context']['integration_id']]);
            }return $sql;
        };
        add_filter('query',$hook);try{$out=$store->append($f['document'],$f['scope']);}finally{remove_filter('query',$hook);}
        self::assertTrue($once);self::assertWPError($out);self::assertSame($before,$wpdb->get_var('SELECT COUNT(*) FROM '.NativeFinanceEvidenceSchema::records()));
    }
    public static function finalReadCases():array{return [[true],[false]];}
    /** @dataProvider intermediateEvidenceCases */
    public function testCompleteCorrectionAncestryMustRemainIntact(string $missing):void
    {
        $f=$this->fixture();$store=new NativeEvidenceStore();$a=$store->append($f['document'],$f['scope']);self::assertNotWPError($a);
        $b=$store->append($this->document($f,'correction-b'),$f['scope'],$a['record_hash']);self::assertNotWPError($b);
        $c=$store->append($this->document($f,'correction-c'),$f['scope'],$b['record_hash']);self::assertNotWPError($c);
        $doc=$this->document($f,'correction-d');$d=$store->append($doc,$f['scope'],$c['record_hash']);self::assertNotWPError($d);global $wpdb;
        if($missing==='audit')$wpdb->delete(DigiForge\Database\Tables::audit_log(),['object_type'=>'finance_native_evidence','object_id'=>$b['record_hash']]);
        elseif($missing==='row')$wpdb->delete(NativeFinanceEvidenceSchema::records(),['record_hash'=>$b['record_hash']]);
        else $wpdb->update(NativeFinanceEvidenceSchema::records(),['source_hash'=>str_repeat('0',64)],['record_hash'=>$b['record_hash']]);
        self::assertWPError($store->receipt($d['record_hash'],$f['scope']));self::assertWPError($store->append($doc,$f['scope'],$c['record_hash']));
        self::assertWPError($store->append($this->document($f,'correction-e'),$f['scope'],$d['record_hash']));
    }
    public static function intermediateEvidenceCases():array{return [['audit'],['row'],['source_hash']];}
    public function testIdentityClaimCannotBeReboundToQuarantinedRecord():void
    {
        $f=$this->fixture();$store=new NativeEvidenceStore();$a=$store->append($f['document'],$f['scope']);self::assertNotWPError($a);
        $b=$store->append($this->document($f,'conflicting'),$f['scope']);self::assertNotWPError($b);self::assertSame('QUARANTINED',$b['state']);global $wpdb;
        self::assertSame(1,$wpdb->update(NativeFinanceEvidenceSchema::claims(),['record_hash'=>$b['record_hash']],['record_hash'=>$a['record_hash']]));
        self::assertWPError($store->receipt($a['record_hash'],$f['scope']));self::assertWPError($store->receipt($b['record_hash'],$f['scope']));self::assertWPError($store->append($f['document'],$f['scope']));
    }
    public function testIndependentCommittedBindingDriftIsNotHiddenByTransactionSnapshot():void
    {
        $f=$this->fixture();$store=new NativeEvidenceStore();global $wpdb;$other=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$once=false;$changed=null;
        $hook=static function(string $sql)use(&$once,&$changed,$f,$other):string{
            if(!$once&&str_starts_with($sql,'INSERT INTO `'.NativeFinanceEvidenceSchema::records().'`')){$once=true;$changed=$other->update(DigiForge\Database\Tables::integrations(),['config'=>'{}'],['id'=>$f['context']['integration_id']]);}return $sql;
        };
        // Bound any lock wait on this independent synthetic writer.
        $other->query('SET SESSION innodb_lock_wait_timeout=1');$old=$other->suppress_errors(true);
        add_filter('query',$hook);try{$out=$store->append($f['document'],$f['scope']);}finally{remove_filter('query',$hook);$other->suppress_errors($old);$other->close();}
        self::assertTrue($once);
        // A serialized writer may deny its competing update; a committed change must deny admission.
        if($changed===1)self::assertWPError($out);else{self::assertFalse($changed);self::assertNotWPError($out);}
    }

    public function testOrphanAuditBlocksChangedBytesAfterOriginalAndClaimDisappear():void
    {
        $f=$this->fixture();$store=new NativeEvidenceStore();$a=$store->append($f['document'],$f['scope']);self::assertNotWPError($a);global $wpdb;
        $wpdb->delete(NativeFinanceEvidenceSchema::claims(),['record_hash'=>$a['record_hash']]);$wpdb->delete(NativeFinanceEvidenceSchema::records(),['record_hash'=>$a['record_hash']]);
        self::assertWPError($store->append($this->document($f,'replacement-after-loss'),$f['scope']));
    }
    public function testStaleOuterSnapshotCannotHideOtherIntegrationIdentityFields():void
    {
        $f=$this->fixture();$store=new NativeEvidenceStore();global $wpdb;$other=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);
        self::assertNotFalse($wpdb->query('START TRANSACTION'));
        try{
            $wpdb->get_var('SELECT id FROM '.DigiForge\Database\Tables::integrations().' LIMIT 1');
            self::assertSame(1,$other->update(DigiForge\Database\Tables::integrations(),['display_name'=>'Changed synthetic identity'],['id'=>$f['context']['integration_id']]));
            self::assertWPError($store->append($f['document'],$f['scope']));
        }finally{$wpdb->query('ROLLBACK');$other->close();}
    }
    public function testMissingClaimCannotAdmitChangedBytesAsANewTransaction():void
    {
        $f=$this->fixture();$store=new NativeEvidenceStore();$a=$store->append($f['document'],$f['scope']);self::assertNotWPError($a);global $wpdb;
        $wpdb->delete(NativeFinanceEvidenceSchema::claims(),['record_hash'=>$a['record_hash']]);
        self::assertWPError($store->append($this->document($f,'changed-after-claim-loss'),$f['scope']));
    }

    public function testExplicitRefreshedBindingSupportsNewIdentityWithoutRebindingHistory():void
    {
        $f=$this->fixture();$store=new NativeEvidenceStore();$a=$store->append($f['document'],$f['scope']);self::assertNotWPError($a);
        $i=$f['input'];$i['source_sha256']=str_repeat('b',64);$service=new DigitalSourceBinding();
        $pending=$service->propose($i,time()-5,time()+3600,wp_generate_uuid4(),$f['binding']['revision']);self::assertNotWPError($pending);
        wp_set_current_user($f['actors']['reviewer']);$approved=$service->decide($i['business_id'],$i['store_id'],'test',$pending['revision'],$pending['proposal_hash'],'APPROVED',wp_generate_uuid4());self::assertNotWPError($approved);
        wp_set_current_user($f['actors']['author']);$scope=array_replace($f['scope'],['binding_hash'=>$approved['proposal_hash'],'binding_revision'=>$approved['revision']]);
        $next=$this->document($f,'new-reviewed-identity');$next['raw']=str_replace('"payment_id":11','"payment_id":12',$next['raw']);$next['capture']['source_sha256']=hash('sha256',$next['raw']);
        $b=$store->append($next,$scope);self::assertNotWPError($b);self::assertSame($b,$store->receipt($b['record_hash'],$scope));
        self::assertWPError($store->receipt($a['record_hash'],$scope));self::assertWPError($store->append($f['document'],$scope));
        self::assertWPError($store->append($this->document($f,'no-automatic-rebinding'),$scope));
    }
    public function testScopeHistoryResourceBoundDeniesWithoutWriting():void
    {
        $f=$this->fixture();$store=new NativeEvidenceStore();$a=$store->append($f['document'],$f['scope']);self::assertNotWPError($a);global $wpdb;
        $before=$wpdb->get_var('SELECT COUNT(*) FROM '.NativeFinanceEvidenceSchema::records());
        $hook=static function(string $sql):string{
            return str_starts_with($sql,'SELECT * FROM '.NativeFinanceEvidenceSchema::records().' WHERE scope_hash=')&&str_contains($sql,'ORDER BY id ASC')
                ?'WITH RECURSIVE synthetic_native_bound(n) AS (SELECT 1 UNION ALL SELECT n+1 FROM synthetic_native_bound WHERE n<501) SELECT n AS id FROM synthetic_native_bound':$sql;
        };
        add_filter('query',$hook);try{self::assertWPError($store->receipt($a['record_hash'],$f['scope']));self::assertWPError($store->append($this->document($f,'bounded'),$f['scope']));}finally{remove_filter('query',$hook);}
        self::assertSame($before,$wpdb->get_var('SELECT COUNT(*) FROM '.NativeFinanceEvidenceSchema::records()));
    }

    public function testAuditCreatorMustMatchEncryptedOriginalProvenance():void
    {
        $f=$this->fixture();$store=new NativeEvidenceStore();$a=$store->append($f['document'],$f['scope']);self::assertNotWPError($a);global $wpdb;
        self::assertSame(1,$wpdb->update(DigiForge\Database\Tables::audit_log(),['actor_id'=>$f['actors']['reviewer']],['object_type'=>'finance_native_evidence','object_id'=>$a['record_hash']]));
        self::assertWPError($store->receipt($a['record_hash'],$f['scope']));self::assertWPError($store->append($f['document'],$f['scope']));
    }

}
