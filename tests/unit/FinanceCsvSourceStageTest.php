<?php
declare(strict_types=1);

use DigiForge\Finance\CsvSourceStage;
use DigiForge\Finance\EtsyNativeObservationStage;
use DigiForge\Finance\EtsyFinancialSourceParser;
use PHPUnit\Framework\TestCase;

foreach(['ExactDecimal','DeclarationContract','EtsyFinancialSourceParser','EtsyNativeObservationStage','CsvSourceStage','SourceAdmission']as$file){
    $path=dirname(__DIR__,2).'/includes/Finance/'.$file.'.php';if(is_file($path))require_once $path;
}

/** Deliberately fictional column labels: no provider CSV or accounting semantics. */
final class FinanceCsvSourceStageTest extends TestCase
{
    public static function context():array
    {
        return ['shop_key'=>'digital','environment'=>'test','business_id'=>1,'store_id'=>2,'external_shop_id'=>777,
            'external_account_id'=>778,'integration_id'=>3,'source_contract_sha256'=>hash('sha256','digiforge-finance-csv-structure-v1')];
    }
    public static function document(?string $raw=null,?int $declared=1):array
    {
        $raw=$raw??"Fixture ID,Fixture Amount,Fixture Note\r\n001,0.00100,\"  é PRIVATE_MARKER  \"\r\n";
        return ['raw'=>$raw,'layout'=>['delimiter'=>',','headers'=>['Fixture ID','Fixture Amount','Fixture Note']],
            'declared_rows'=>$declared,'capture'=>array_intersect_key(self::context(),array_flip(['shop_key','environment','business_id','store_id','external_shop_id']))+
                ['source_sha256'=>hash('sha256',$raw),'observed_at'=>'2026-10-30T00:00:00Z','source_reference'=>'local://synthetic/csv-original']];
    }
    private function stage(array $docs,?array $context=null,?array $selected=null,string $provider='etsy'):array
    {
        self::assertTrue(class_exists(CsvSourceStage::class),'Missing bounded source-independent CSV inspection boundary.');
        return CsvSourceStage::preview($provider,$docs,$context??self::context(),$selected??self::context(),strtotime('2026-10-30T12:00:00Z'));
    }
    public function testExactCellsAndOriginalBytesAreHashBoundButNeverRecognized():void
    {
        $doc=self::document();$r=$this->stage([$doc]);
        self::assertSame('STRUCTURE_VALID_PROVIDER_FORMAT_BLOCKED',$r['state']);
        self::assertSame(hash('sha256',$doc['raw']),$r['sources'][0]['raw_source_sha256']);
        self::assertSame(hash('sha256',json_encode([$doc['layout']['headers'],[['001','0.00100','  é PRIVATE_MARKER  ']]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)),$r['sources'][0]['canonical_source_sha256']);
        self::assertSame('DECLARED_COUNTS_MATCH',$r['population']['state']);
        self::assertSame(1,$r['population']['physical_rows']);
        foreach(['authenticity','ownership','settlement','source_completeness','provider_schema']as$key)self::assertSame('UNKNOWN',$r[$key]);
        foreach(['persistence_performed','durable_replay_protection','accounting_import_authorized','period_approval_authorized','external_execution_authorized']as$key)self::assertFalse($r[$key]);
        self::assertStringNotContainsString('PRIVATE_MARKER',json_encode($r));
        self::assertSame($r,$this->stage([$doc]));
    }
    /** @dataProvider malformedCsv */
    public function testMalformedStructureCannotBeStaged(string $raw):void
    {
        $this->expectException(InvalidArgumentException::class);$this->stage([self::document($raw)]);
    }
    public static function malformedCsv():array
    {
        $h="Fixture ID,Fixture Amount,Fixture Note\n";
        return [[$h.'1,2,"unterminated'],[$h.'1,2,"closed"junk'],[$h.'1,2,un"quoted'],[$h.'1,2'],[$h.'1,2,3,4'],
            ["Fixture ID,Fixture ID,Fixture Note\n1,2,3"],["Fixture Amount,Fixture ID,Fixture Note\n1,2,3"],
            [$h."1,2,\xff"],[$h."1,2,\0"],[$h."1,2,3\r4,5,6"],[$h."\n"],[$h.'1,2,'.str_repeat('x',4097)]];
    }
    public function testUnknownPartialDuplicateAndEmptyPopulationsNeverProveCompleteness():void
    {
        foreach([null,0,2]as$count){$r=$this->stage([self::document(null,$count)]);self::assertSame($count===null?'UNKNOWN':'DECLARED_COUNTS_CONFLICT',$r['population']['state']);self::assertSame('UNKNOWN',$r['source_completeness']);}
        $raw="Fixture ID,Fixture Amount,Fixture Note\n1,2,x\n1,2,x\n";$r=$this->stage([self::document($raw,2)]);
        self::assertSame('DUPLICATE_ROWS_REQUIRE_REVIEW',$r['population']['state']);self::assertSame(1,$r['population']['duplicate_rows']);
        $r=$this->stage([self::document("Fixture ID,Fixture Amount,Fixture Note\n",0)]);
        self::assertSame(0,$r['population']['physical_rows']);self::assertSame('UNKNOWN',$r['source_completeness']);self::assertFalse($r['period_approval_authorized']);
    }
    public function testDuplicateFilesFailClosedEvenWithDifferentDeclaredLayoutClaims():void
    {
        $doc=self::document();$copy=$doc;$copy['declared_rows']=2;
        $this->expectException(InvalidArgumentException::class);$this->stage([$doc,$copy]);
    }
    public function testQuotedNewlinesEscapesBomAndFormulaContentArePreservedNotExecuted():void
    {
        $doc=self::document("\xef\xbb\xbfFixture ID,Fixture Amount,Fixture Note\r\n1,-0.00100,\"é\r\n\"\"quoted\"\"\"\r\n");
        $r=$this->stage([$doc]);self::assertTrue($r['sources'][0]['utf8_bom_present']);self::assertSame(1,$r['sources'][0]['formula_cells']);
        self::assertSame(1,$r['sources'][0]['data_rows']);self::assertStringNotContainsString('quoted',json_encode($r));
    }
    /** @dataProvider scopeCases */
    public function testForeignOrStaleScopeIsDenied(string $key,mixed $value):void
    {
        $this->expectException(InvalidArgumentException::class);$this->stage([self::document()],array_replace(self::context(),[$key=>$value]));
    }
    public static function scopeCases():array{return [['shop_key','personalized_pod'],['shop_key','standard_pod'],['environment','production'],['business_id',9],['store_id',8],['external_shop_id',8],['external_account_id',8],['integration_id',8],['source_contract_sha256',str_repeat('a',64)]];}
    /** @dataProvider unsafeDocuments */
    public function testBoundsFutureCapturesHashDriftAndUnknownAuthorityClaimsAreDenied(string $case):void
    {
        $doc=self::document();$docs=[$doc];
        switch($case){
            case 'hash':$doc['capture']['source_sha256']=str_repeat('a',64);break;
            case 'future':$doc['capture']['observed_at']='2027-01-01T00:00:00Z';break;
            case 'count':$doc['declared_rows']='1';break;
            case 'authority':$doc['approved']=true;break;
            case 'capture':$doc['capture']['external_shop_id']=1;break;
            case 'reference':$doc['capture']['source_reference']='https://example.invalid/source';break;
            case 'delimiter':$doc['layout']['delimiter']='||';break;
            case 'file':$doc['raw']=str_repeat('x',65537);$doc['capture']['source_sha256']=hash('sha256',$doc['raw']);break;
            case 'rows':$doc=self::document("Fixture ID,Fixture Amount,Fixture Note\n".str_repeat("1,2,3\n",501),501);break;
            case 'documents':$docs=array_fill(0,21,$doc);break;
        }
        if($case!=='documents')$docs=[$doc];$this->expectException(InvalidArgumentException::class);$this->stage($docs);
    }
    public static function unsafeDocuments():array{return array_map(static fn(string $x):array=>[$x],['hash','future','count','authority','capture','reference','delimiter','file','rows','documents']);}
    public function testExactRowBoundarySupportedDelimitersAndCombinedBounds():void
    {
        foreach([',',';',"\t"]as$delimiter){
            $doc=self::document(implode($delimiter,['Fixture ID','Fixture Amount','Fixture Note'])."\r\n".'1'.$delimiter.'0.00100'.$delimiter.'note');$doc['layout']['delimiter']=$delimiter;
            self::assertSame(1,$this->stage([$doc])['population']['physical_rows']);
        }
        $raw="Fixture ID,Fixture Amount,Fixture Note\n";for($i=0;$i<500;++$i)$raw.=$i.",0.00001,note\n";
        self::assertSame(500,$this->stage([self::document($raw,500)])['population']['physical_rows']);
        $docs=[];for($i=0;$i<5;++$i){$raw="Fixture ID,Fixture Amount,Fixture Note\n";for($j=0;$j<14;++$j)$raw.=$i.'-'.$j.',1,'.str_repeat('x',4096)."\n";$docs[]=self::document($raw,14);}
        $this->expectException(InvalidArgumentException::class);$this->stage($docs);
    }

    public function testLayoutObjectKeyOrderCannotChangeTheRetryFingerprint():void
    {
        $doc=self::document();$first=$this->stage([$doc]);$doc['layout']=array_reverse($doc['layout'],true);
        self::assertSame($first['stage_hash'],$this->stage([$doc])['stage_hash']);
    }

    public function testOtherProvidersHaveOnlyStructuralSupportAndNoNativeSchemaPromotion():void
    {
        foreach(['etsy','printify','gelato','openai']as$provider){$r=$this->stage([self::document()],null,null,$provider);self::assertSame('UNKNOWN',$r['provider_schema']);self::assertFalse($r['accounting_import_authorized']);}
        $this->expectException(InvalidArgumentException::class);$this->stage([self::document()],null,null,'invented');
    }
}
