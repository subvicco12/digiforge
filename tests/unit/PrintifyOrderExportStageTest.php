<?php
declare(strict_types=1);

use DigiForge\Finance\PrintifyOrderExportStage;
use PHPUnit\Framework\TestCase;
foreach(['ExactDecimal','DeclarationContract','EtsyFinancialSourceParser','EtsyNativeObservationStage','CsvSourceStage','PrintifyOrderExportStage','SourceAdmission']as$f){$p=dirname(__DIR__,2).'/includes/Finance/'.$f.'.php';if(is_file($p))require_once $p;}

/** Only the header is authentic owner-supplied evidence; every appended row is synthetic and opaque. */
final class PrintifyOrderExportStageTest extends TestCase
{
    private const HEADER_SHA='8b5db86bf5a3855b24a3082e660c97d646743e48023b8d421dea0e65a882c78d';
    public static function header():string{return file_get_contents(dirname(__DIR__,2).'/includes/Finance/contracts/printify-order-export-header-v1.csv');}
    public static function context():array{return ['shop_key'=>'personalized_pod','environment'=>'test','business_id'=>1,'store_id'=>2,'external_shop_id'=>777,'external_account_id'=>778,'integration_id'=>3,'source_contract_sha256'=>self::HEADER_SHA];}
    public static function document(?string $raw=null,string $ref='local://synthetic/order-export'):array
    {
        $raw=$raw??self::header();$scope=array_intersect_key(self::context(),array_flip(['shop_key','environment','business_id','store_id','external_shop_id']));
        return ['raw'=>$raw,'declared_rows'=>null,'capture'=>$scope+['source_sha256'=>hash('sha256',$raw),'observed_at'=>'2026-10-30T16:00:00Z','source_reference'=>$ref]];
    }
    public static function row(string $opaque='001'):string
    {
        $v=array_fill(0,20,'');$v[0]=$opaque;$v[1]='PAID_IS_NOT_VERIFIED';$v[2]='UNKNOWN_DATE';$v[7]='SYNTHETIC_PRIVATE_NAME';$v[9]='0.00100';$v[13]='UNKNOWN_UNIT';$v[15]='SYNTHETIC_PRIVATE_ADDRESS';$v[17]='https://example.invalid/do-not-fetch';
        return implode(',',array_map(static fn(string $x):string=>'"'.str_replace('"','""',$x).'"',$v))."\n";
    }
    private function inspect(array $docs,?array $context=null,?array $selected=null,string $provider='printify'):array
    {
        self::assertTrue(class_exists(PrintifyOrderExportStage::class),'Observed Printify order-header contract missing.');
        return PrintifyOrderExportStage::preview($provider,$docs,$context??self::context(),$selected??self::context(),strtotime('2026-10-30T18:00:00Z'));
    }
    public function testOriginalHeaderOnlyIsObservedNotPaidOrCompleteFinancialEvidence():void
    {
        $header=self::header();self::assertSame(306,strlen($header));self::assertSame(self::HEADER_SHA,hash('sha256',$header));
        $r=$this->inspect([self::document()]);self::assertSame('OBSERVED_HEADER_MATCHED_UNVERIFIED_ORDER_EXPORT',$r['state']);
        self::assertSame(self::HEADER_SHA,$r['observed_header_sha256']);self::assertSame(self::HEADER_SHA,$r['context']['source_contract_sha256']);
        self::assertSame(20,$r['sources'][0]['column_count']);self::assertSame(0,$r['population']['physical_rows']);
        foreach(['financial_amounts','money_units','dates','order_status','invoices','transaction_identity','ownership','settlement','source_completeness']as$key)self::assertSame('UNKNOWN',$r[$key]);
        foreach(['persistence_performed','accounting_import_authorized','period_approval_authorized','external_execution_authorized','durable_replay_protection']as$key)self::assertFalse($r[$key]);
        self::assertSame($r,$this->inspect([self::document()]));
        $empty=self::document();$empty['declared_rows']=0;$zero=$this->inspect([$empty]);self::assertSame('DECLARED_COUNTS_MATCH',$zero['population']['state']);self::assertSame('UNKNOWN',$zero['source_completeness']);self::assertFalse($zero['period_approval_authorized']);
        self::assertSame('UNKNOWN',$r['publisher_encoding']);
    }
    public function testOpaqueRowsPreserveOriginalBytesAndCannotBecomeProviderPaidInvoices():void
    {
        $raw=self::header().self::row();$doc=self::document($raw);$r=$this->inspect([$doc]);
        self::assertSame(hash('sha256',$raw),$r['sources'][0]['raw_source_sha256']);self::assertSame(1,$r['population']['physical_rows']);
        self::assertStringNotContainsString('SYNTHETIC_PRIVATE',json_encode($r));self::assertStringNotContainsString('0.00100',json_encode($r));self::assertStringNotContainsString('do-not-fetch',json_encode($r));
        self::assertSame($doc,self::document($raw));self::assertSame(hash('sha256',$doc['capture']['source_reference']),$r['sources'][0]['source_reference_sha256']);
    }
    public function testCaptureReferenceAndTimeRemainPartOfTheObservationFingerprint():void
    {
        $a=self::document();$first=$this->inspect([$a]);$b=$a;$b['capture']['source_reference']='local://synthetic/another-capture';
        self::assertNotSame($first['stage_hash'],$this->inspect([$b])['stage_hash']);
        $b=$a;$b['capture']['observed_at']='2026-10-30T16:01:00Z';self::assertNotSame($first['stage_hash'],$this->inspect([$b])['stage_hash']);
        $b=$a;$b['capture']['source_sha256']=str_repeat('a',64);$this->expectException(InvalidArgumentException::class);$this->inspect([$b]);
    }
    /** @dataProvider invalidFiles */
    public function testHeaderOrderFingerprintSyntaxAndEncodingAreStrict(string $case):void
    {
        $h=self::header();$raw=$h;
        switch($case){
            case 'reordered':$raw=str_replace('"Printify ID","Order Status"','"Order Status","Printify ID"',$h);break;
            case 'missing':$raw=str_replace('"Order Status",','',$h);break;
            case 'duplicate':$raw=str_replace('"Order Status"','"Printify ID"',$h);break;
            case 'extra':$raw=rtrim($h,"\n").",Extra\n";break;
            case 'crlf':$raw=str_replace("\n","\r\n",$h);break;
            case 'bom':$raw="\xef\xbb\xbf".$h;break;
            case 'utf16':$raw="\xff\xfe".implode("\0",str_split($h));break;
            case 'latin1':$raw=$h.'"'.str_repeat(', ',19)."\xe9\"\n";break;
            case 'quote':$raw=$h.'"unterminated';break;
            case 'width':$raw=$h."1,2,3\n";break;
            case 'overflow':$raw=$h.str_repeat('x',65537);break;
            case 'rows':$raw=$h.str_repeat(self::row(),501);break;
        }
        $this->expectException(InvalidArgumentException::class);$this->inspect([self::document($raw)]);
    }
    public static function invalidFiles():array{return array_map(static fn($x)=>[$x],['reordered','missing','duplicate','extra','crlf','bom','utf16','latin1','quote','width','overflow','rows']);}
    public function testDuplicateFilesAndConflictingImmutableSourceReferencesDeny():void
    {
        foreach([[self::document(),self::document()],[self::document(self::header().self::row('a')),self::document(self::header().self::row('b'))]]as$docs){
            try{$this->inspect($docs);self::fail('Duplicate or conflicting source accepted.');}catch(InvalidArgumentException $e){self::assertNotSame('',$e->getMessage());}
        }
    }
    public function testSameOpaqueOrderLabelDoesNotInventTransactionIdentityOrCorrectionMeaning():void
    {
        $a=self::document(self::header().self::row('same'),'local://synthetic/version-a');$b=self::document(self::header().str_replace('0.00100','not-a-money',self::row('same')),'local://synthetic/version-b');
        $r=$this->inspect([$a,$b]);self::assertSame(2,$r['population']['physical_rows']);self::assertSame('UNKNOWN',$r['transaction_identity']);self::assertFalse($r['accounting_import_authorized']);
    }
    /** @dataProvider scopeCases */
    public function testScopeAndVersionDriftNeverAcquireAuthority(string $key,mixed $value):void
    {
        $this->expectException(InvalidArgumentException::class);$this->inspect([self::document()],array_replace(self::context(),[$key=>$value]));
    }
    public static function scopeCases():array{return [['shop_key','digital'],['shop_key','jewelry'],['environment','production'],['business_id',9],['store_id',9],['external_shop_id',9],['external_account_id',9],['integration_id',9],['source_contract_sha256',str_repeat('a',64)]];}
    public function testMatchingDigitalOrDeferredContextsCannotInheritPrintifyPodScope():void
    {
        foreach(['digital','standard_pod','jewelry']as$shop){$c=self::context();$c['shop_key']=$shop;$d=self::document();$d['capture']['shop_key']=$shop;
            try{$this->inspect([$d],$c,$c);self::fail('Unsupported shop inherited POD observations.');}catch(InvalidArgumentException){self::assertTrue(true);}}
    }
    public function testWrongProviderAndForgedLayoutOrApprovalAreNotAccepted():void
    {
        foreach(['etsy','gelato','openai']as$provider){try{$this->inspect([self::document()],null,null,$provider);self::fail('Foreign provider accepted.');}catch(InvalidArgumentException){self::assertTrue(true);}}
        foreach(['layout'=>['delimiter'=>',','headers'=>['fake']],'approved'=>true]as$key=>$value){$d=self::document();$d[$key]=$value;try{$this->inspect([$d]);self::fail('Unsupported field accepted.');}catch(InvalidArgumentException){self::assertTrue(true);}}
    }
}
