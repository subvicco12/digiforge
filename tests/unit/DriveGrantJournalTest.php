<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use DigiForge\DigitalFactory\DriveGrantJournal;
use DigiForge\DigitalFactory\DriveGrantStore;
use DigiForge\DigitalFactory\DriveTransferRequest;
require_once dirname(__DIR__,2).'/includes/DigitalFactory/DriveTransferRequest.php';
require_once dirname(__DIR__,2).'/includes/DigitalFactory/DriveExecutionGrant.php';
require_once dirname(__DIR__,2).'/includes/DigitalFactory/DriveGrantStore.php';
require_once dirname(__DIR__,2).'/includes/DigitalFactory/DriveGrantJournal.php';

final class DriveJournalMemoryStore implements DriveGrantStore
{
    public array $rows=[];
    public bool $unavailable=false;
    public ?Closure $beforeCas=null;
    public function read(string $key): ?array { return $this->unavailable?null:($this->rows[$key]??[]); }
    public function compareAndSwap(string $key,array $expected,array $next): bool {
        if($this->beforeCas){$f=$this->beforeCas;$this->beforeCas=null;$f();}
        if($this->unavailable||($this->rows[$key]??[])!==$expected)return false;
        $this->rows[$key]=$next;return true;
    }
}
final class DriveGrantJournalTest extends TestCase
{
    private function fixture():array {
        $p=['id'=>str_repeat('a',64),'installation_sha256'=>str_repeat('b',64),'environment'=>'local','shop_key'=>'digital','folder_id'=>DriveTransferRequest::FOLDER,'product_id'=>25,'product_version_id'=>5,'revision_id'=>12,'source_sha256'=>str_repeat('c',64),'actor_id'=>7];
        $g=$p+['issuer'=>'digiforge_google_v1','connection_id'=>'google_digital','nonce'=>str_repeat('d',64),'issued_at'=>100,'expires_at'=>200,'remaining_attempts'=>2,'stages'=>['folder','original_upload','reconcile_original_upload']];
        $s=new DriveJournalMemoryStore();$key=DriveGrantJournal::key($g);
        // Simulates a trusted issuer's committed record, never a public issuance path.
        $s->rows[$key]=['grant'=>$g,'revoked'=>false,'attempts'=>[]];
        return [$p,$g,$s,new DriveGrantJournal($s),$key];
    }
    public function testUnissuedOrMismatchedGrantCannotConsume():void {
        [$p,$g,$s,$j,$k]=$this->fixture();unset($s->rows[$k]);self::assertNull($j->reserve($g,$p,'folder',str_repeat('1',64),150));
        [$p,$g,$s,$j]=$this->fixture();$g['connection_id']='foreign';self::assertNull($j->reserve($g,$p,'folder',str_repeat('1',64),150));
    }
    public function testReservationBurnsQuotaAndReplayNeverDispatchesAgain():void {
        [$p,$g,$s,$j]=$this->fixture();$id=str_repeat('1',64);$t=$j->reserve($g,$p,'folder',$id,150);self::assertNotNull($t);
        self::assertNull($j->reserve($g,$p,'folder',$id,150));
        self::assertTrue($j->finish($g,$p,$t,'CONFIRMED',str_repeat('f',64),151));
        self::assertFalse($j->finish($g,$p,$t,'CONFIRMED',str_repeat('f',64),152));
        self::assertNotNull($j->reserve($g,$p,'folder',str_repeat('2',64),153));
        self::assertNull($j->reserve($g,$p,'folder',str_repeat('3',64),154));
    }
    public function testCrashOrUnknownCreateRequiresReconciliationNotAnotherPost():void {
        [$p,$g,$s,$j]=$this->fixture();self::assertNotNull($j->reserve($g,$p,'original_upload',str_repeat('1',64),150));
        self::assertNull($j->reserve($g,$p,'original_upload',str_repeat('2',64),151));
        self::assertNotNull($j->reserve($g,$p,'reconcile_original_upload',str_repeat('2',64),151));
    }
    public function testConcurrentOneWinnerAndDurableRevocation():void {
        [$p,$g,$s,$j]=$this->fixture();$nested=null;
        $s->beforeCas=function()use($p,$g,$j,&$nested){$nested=$j->reserve($g,$p,'folder',str_repeat('2',64),150);};
        self::assertNull($j->reserve($g,$p,'folder',str_repeat('1',64),150));self::assertNotNull($nested);
        self::assertTrue($j->revoke($g));self::assertFalse($j->finish($g,$p,$nested,'CONFIRMED',str_repeat('f',64),151));
        self::assertNull($j->reserve($g,$p,'folder',str_repeat('3',64),151));
    }
    public function testPersistenceFailureExpiryCorruptionAndForgedTicketDeny():void {
        [$p,$g,$s,$j,$k]=$this->fixture();$s->unavailable=true;self::assertNull($j->reserve($g,$p,'folder',str_repeat('1',64),150));$s->unavailable=false;
        self::assertNull($j->reserve($g,$p,'folder',str_repeat('1',64),200));
        $t=$j->reserve($g,$p,'folder',str_repeat('1',64),150);$t['claim']=str_repeat('0',64);self::assertFalse($j->finish($g,$p,$t,'CONFIRMED',str_repeat('f',64),151));
        $s->rows[$k]['attempts']['broken']=['state'=>'CONFIRMED'];self::assertNull($j->reserve($g,$p,'folder',str_repeat('2',64),151));
    }
}
