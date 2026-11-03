<?php
declare(strict_types=1);
use DigiForge\DigitalFactory\DriveGrantJournal;
use DigiForge\DigitalFactory\DriveWordPressGrantStore;
use DigiForge\DigitalFactory\DriveTransferRequest;
use DigiForge\Operations\RecoveryDispatchLedger as Ledger;

/** Actual durable option/CAS storage, synthetic grant only, zero provider calls. */
final class DriveGrantJournalDbTest extends WP_UnitTestCase
{
    private array $keys=[];
    protected function setUp():void { parent::setUp();DigiForge\Core\Activator::activate();DigiForge\Core\Settings::protectProduction(); }
    protected function tearDown():void {
        global $wpdb;
        foreach($this->keys as $key){$wpdb->delete($wpdb->options,['option_name'=>$key]);}
        $wpdb->query('COMMIT');$wpdb->query('START TRANSACTION');
        self::assertTrue(DigiForge\Core\Settings::get('stop_all',true));self::assertTrue(DigiForge\Core\Settings::safety_locked());parent::tearDown();
    }
    private function fixture():array {
        $now=time();$p=['id'=>hash('sha256',wp_generate_uuid4()),'installation_sha256'=>hash('sha256',site_url()),'environment'=>'local','shop_key'=>'digital','folder_id'=>DriveTransferRequest::FOLDER,'product_id'=>25,'product_version_id'=>5,'revision_id'=>12,'source_sha256'=>str_repeat('c',64),'actor_id'=>7];
        $g=$p+['issuer'=>'synthetic_google_issuer','connection_id'=>'synthetic_google','nonce'=>hash('sha256',wp_generate_uuid4()),'issued_at'=>$now-1,'expires_at'=>$now+300,'remaining_attempts'=>2,'stages'=>['folder','original_upload','reconcile_original_upload']];
        $key=DriveGrantJournal::key($g);$this->keys[]=$key;
        self::assertTrue(Ledger::insert($key,['grant'=>$g,'revoked'=>false,'attempts'=>[]]));return [$p,$g,$key,$now];
    }
    public function testDurableClaimReplayQuotaAndMonotonicRevocation():void {
        [$p,$g,$key,$now]=$this->fixture();$a=new DriveGrantJournal(new DriveWordPressGrantStore());$b=new DriveGrantJournal(new DriveWordPressGrantStore());
        $id=str_repeat('1',64);$t=$a->reserve($g,$p,'folder',$id,$now);self::assertNotNull($t);self::assertFalse($t['execution_authorized']);
        self::assertNull($b->reserve($g,$p,'folder',$id,$now));self::assertTrue($a->finish($g,$p,$t,'CONFIRMED',str_repeat('f',64),$now));
        self::assertNotNull($b->reserve($g,$p,'folder',str_repeat('2',64),$now));self::assertNull($a->reserve($g,$p,'folder',str_repeat('3',64),$now));
        self::assertTrue($b->revoke($g));self::assertTrue($a->revoke($g));self::assertTrue(Ledger::read($key)['revoked']);
    }
    public function testCrashPersistsAcrossInstancesAndNeverRepeatsCreate():void {
        [$p,$g,$key,$now]=$this->fixture();$a=new DriveGrantJournal(new DriveWordPressGrantStore());self::assertNotNull($a->reserve($g,$p,'original_upload',str_repeat('1',64),$now));unset($a);
        $b=new DriveGrantJournal(new DriveWordPressGrantStore());self::assertNull($b->reserve($g,$p,'original_upload',str_repeat('2',64),$now));self::assertNotNull($b->reserve($g,$p,'reconcile_original_upload',str_repeat('2',64),$now));
    }
    public function testStorageAdapterRejectsForeignNamespaceAndCorruptRows():void {
        [$p,$g,$key,$now]=$this->fixture();$store=new DriveWordPressGrantStore();self::assertNull($store->read('digiforge_other_state'));self::assertFalse($store->compareAndSwap('digiforge_other_state',[],['bad'=>true]));
        $old=Ledger::read($key);self::assertTrue(Ledger::compareAndSwap($key,$old,['invalid'=>true]));self::assertNull((new DriveGrantJournal($store))->reserve($g,$p,'folder',str_repeat('1',64),$now));
    }
}
