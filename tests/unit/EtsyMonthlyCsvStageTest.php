<?php
declare(strict_types=1);
use DigiForge\Finance\EtsyMonthlyCsvStage;
use PHPUnit\Framework\TestCase;
foreach(['ExactDecimal','DeclarationContract','EtsyFinancialSourceParser','EtsyNativeObservationStage','CsvSourceStage','ObservedCsvHeaderStage','EtsyMonthlyCsvStage','SourceAdmission']as$f){$p=dirname(__DIR__,2).'/includes/Finance/'.$f.'.php';if(is_file($p))require_once $p;}

/** Header witness is owner-supplied; every data row is synthetic and has no accounting meaning. */
final class EtsyMonthlyCsvStageTest extends TestCase
{
    private const HASH='4b9711a954913dd223c23cde077a22900e836f45803a61442dc0484c81b74f11';
    public static function header():string{return "\xef\xbb\xbfDate,Type,Title,Info,Currency,Amount,\"Fees & Taxes\",Net,\"Tax Details\"\n";}
    public static function context():array{return ['shop_key'=>'digital','environment'=>'test','business_id'=>1,'store_id'=>2,'external_shop_id'=>777,'external_account_id'=>778,'integration_id'=>3,'source_contract_sha256'=>self::HASH];}
    public static function document(?string $raw=null,string $ref='local://synthetic/etsy-monthly'):array
    {
        $raw=$raw??self::header();return ['raw'=>$raw,'declared_rows'=>null,'capture'=>array_intersect_key(self::context(),array_flip(['shop_key','environment','business_id','store_id','external_shop_id']))+['source_sha256'=>hash('sha256',$raw),'observed_at'=>'2026-10-30T16:00:00Z','source_reference'=>$ref]];
    }
    private function inspect(array $docs,?array $c=null,?array $selected=null,string $provider='etsy'):array
    {
        self::assertTrue(class_exists(EtsyMonthlyCsvStage::class),'Observed Etsy monthly header contract missing.');
        return EtsyMonthlyCsvStage::preview($provider,$docs,$c??self::context(),$selected??self::context(),strtotime('2026-10-31T12:00:00Z'));
    }
    public function testExactBomHeaderRemainsPartialWitnessNotWholeFileCustodyOrRecognition():void
    {
        $h=self::header();self::assertSame(73,strlen($h));self::assertSame(self::HASH,hash('sha256',$h));$d=self::document();$r=$this->inspect([$d]);
        self::assertSame($h,file_get_contents(dirname(__DIR__,2).'/includes/Finance/contracts/etsy-monthly-header-v1.csv'));
        self::assertSame('OBSERVED_HEADER_MATCHED_UNVERIFIED_MONTHLY_CSV',$r['state']);self::assertSame(9,$r['sources'][0]['column_count']);self::assertTrue($r['sources'][0]['utf8_bom_present']);self::assertSame(0,$r['population']['physical_rows']);
        foreach(['financial_amounts','money_units','dates','transaction_identity','corrections','settlement','ownership','source_completeness','original_file_custody']as$key)self::assertSame('UNKNOWN',$r[$key]);
        foreach(['persistence_performed','durable_replay_protection','accounting_import_authorized','period_approval_authorized','external_execution_authorized']as$key)self::assertFalse($r[$key]);
        self::assertSame($r,$this->inspect([$d]));self::assertSame($r,DigiForge\Finance\SourceAdmission::preview('etsy',[$d],self::context(),self::context(),strtotime('2026-10-31T12:00:00Z'),'etsy_monthly_csv_v1'));
        $d['declared_rows']=0;self::assertSame('UNKNOWN',$this->inspect([$d])['source_completeness']);
    }
    public function testOpaqueUnicodeAndQuotedMultilineCellsArePreservedOnlyAsDigests():void
    {
        $raw=self::header()."not-a-date,UNRECOGNIZED,\"SYNTHETIC_PRIVATE_é\nline\",\"a\"\"b\",UNKNOWN,0.00100,-unknown,=formula,https://example.invalid/private\n";
        $d=self::document($raw);$r=$this->inspect([$d]);self::assertSame(hash('sha256',$raw),$r['sources'][0]['raw_source_sha256']);self::assertSame(1,$r['population']['physical_rows']);
        self::assertStringNotContainsString('SYNTHETIC_PRIVATE',json_encode($r));self::assertStringNotContainsString('0.00100',json_encode($r));self::assertStringNotContainsString('example.invalid',json_encode($r));
        $other=$d;$other['capture']['source_reference']='local://synthetic/another';self::assertNotSame($r['stage_hash'],$this->inspect([$other])['stage_hash']);
    }
    /** @dataProvider invalidFiles */
    public function testMalformedReorderedDuplicateTruncatedAndUnsupportedHeaderReject(string $case):void
    {
        $h=self::header();$raw=match($case){'reorder'=>str_replace('Date,Type','Type,Date',$h),'duplicate'=>str_replace('Type','Date',$h),'missing'=>str_replace('Type,','',$h),'truncated'=>substr($h,0,-1),'no-bom'=>substr($h,3),'crlf'=>str_replace("\n","\r\n",$h),'quote'=>str_replace('"Tax Details"','Tax Details',$h),'extra'=>rtrim($h,"\n").",extra\n",'bad-row'=>$h.'"unterminated','width'=>$h."1,2\n",'encoding'=>$h."\xff\n",'oversize'=>$h.str_repeat('x',65537)};
        $this->expectException(InvalidArgumentException::class);$this->inspect([self::document($raw)]);
    }
    public static function invalidFiles():array{return array_map(static fn($x)=>[$x],['reorder','duplicate','missing','truncated','no-bom','crlf','quote','extra','bad-row','width','encoding','oversize']);}
    public function testDuplicatesConflictingFilesAndLayoutOverridesDeny():void
    {
        $a=self::document();$b=self::document(self::header()."a,b,c,d,e,f,g,h,i\n");$override=$a;$override['layout']=['headers'=>['forged'],'delimiter'=>','];
        foreach([[$a,$a],[$a,$b],[$override]]as$docs){try{$this->inspect($docs);self::fail('Conflicting or duplicate source accepted.');}catch(InvalidArgumentException){self::assertTrue(true);}}
    }
    /** @dataProvider scopeDrift */
    public function testForeignScopeAndFingerprintReject(string $key,mixed $value):void
    {
        $this->expectException(InvalidArgumentException::class);$this->inspect([self::document()],array_replace(self::context(),[$key=>$value]));
    }
    public static function scopeDrift():array{return [['shop_key','personalized_pod'],['environment','production'],['business_id',9],['store_id',9],['external_shop_id',9],['external_account_id',9],['integration_id',9],['source_contract_sha256',str_repeat('a',64)]];}
    public function testOnlyBothActiveEtsyShopsAndEtsyProviderCanUseObservedProfile():void
    {
        $c=self::context();$c['shop_key']='personalized_pod';$d=self::document();$d['capture']['shop_key']='personalized_pod';self::assertSame('etsy',$this->inspect([$d],$c,$c)['provider']);
        foreach(['standard_pod','jewelry']as$shop){$c['shop_key']=$shop;$d['capture']['shop_key']=$shop;try{$this->inspect([$d],$c,$c);self::fail('Deferred scope accepted.');}catch(InvalidArgumentException){self::assertTrue(true);}}
        $this->expectException(InvalidArgumentException::class);$this->inspect([self::document()],null,null,'printify');
    }
}
