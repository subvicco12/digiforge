<?php
declare(strict_types=1);

use DigiForge\Finance\EtsyFinancialSourceParser;
use DigiForge\Finance\DeclarationContract;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2).'/includes/Finance/DeclarationContract.php';
require_once dirname(__DIR__, 2).'/includes/Finance/ExactDecimal.php';
if (is_file(dirname(__DIR__, 2).'/includes/Finance/EtsyFinancialSourceParser.php')) {
    require_once dirname(__DIR__, 2).'/includes/Finance/EtsyFinancialSourceParser.php';
}

final class EtsyFinancialSourceParserTest extends TestCase
{
    private static function fixture():array { return json_decode(file_get_contents(dirname(__DIR__).'/fixtures/finance/etsy-native-synthetic-v1.json'),true,32,JSON_THROW_ON_ERROR); }
    private static function parse(string $schema,array $data,array $scopeChanges=[],array $captureChanges=[]):array
    {
        $scope=array_replace(self::fixture()['scope'],$scopeChanges);
        $raw=json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
        $capture=array_replace($scope+['observed_at'=>'2026-10-20T12:00:00Z','source_reference'=>'local://synthetic/etsy-native-v1','source_sha256'=>hash('sha256',$raw)],$captureChanges);
        return EtsyFinancialSourceParser::parse($schema,$raw,$capture,$scope);
    }

    public function testPinnedNativeSchemasPreserveEveryMoneyUnitAndCannotCreateRevenue():void
    {
        self::assertTrue(class_exists(EtsyFinancialSourceParser::class),'Native parser missing');
        $payment=self::fixture()['payment'];$out=self::parse('Payment',$payment);
        self::assertSame($payment,$out['native']);
        self::assertSame(1000,$out['native']['amount_gross']['divisor']);
        self::assertSame('UNKNOWN',$out['authenticity']);self::assertSame('UNKNOWN',$out['settlement']);
        self::assertSame('UNKNOWN',$out['source_completeness']);self::assertFalse($out['ownership_verified']);
        self::assertFalse($out['period_approval_authorized']);self::assertFalse($out['external_execution_authorized']);
        self::assertFalse($out['persistence_performed']);self::assertSame([],$out['finance_declarations']);
        self::assertSame('CONTENT_ADDRESSED_ONLY_NOT_DURABLE_IMPORT',$out['replay_protection']);
        self::assertTrue($out['native']['payment_adjustments'][0]['is_success']);
        self::assertNull($out['native']['payment_adjustments'][0]['total_adjustment_amount']);
    }

    public function testLedgerPaymentAndAdjustmentRemainRelatedViewsNotIndependentRevenue():void
    {
        $f=self::fixture();$ledger=self::parse('PaymentAccountLedgerEntry',$f['ledger_entry']);$payment=self::parse('Payment',$f['payment']);
        self::assertNotSame($ledger['source_identity_keys'],$payment['source_identity_keys']);
        self::assertSame(-17,$ledger['native']['amount']);self::assertSame(0,$ledger['native']['parent_entry_id']);
        self::assertSame('NO_AUTOMATIC_LEDGER_PAYMENT_SUM',$ledger['accounting_policy']);
        self::assertSame([],$payment['finance_declarations']);
        self::assertArrayNotHasKey('divisor',$ledger['native']);
        $adjustment=self::parse('PaymentAdjustment',$f['payment']['payment_adjustments'][0]);
        self::assertSame(11,$adjustment['native']['payment_id']);
        self::assertSame(-17,$adjustment['native']['payment_adjustment_items'][0]['amount']);
    }

    public function testEveryDocumentedObjectAndPageIsSupportedWithoutFabricatedDefaults():void
    {
        $f=self::fixture();$item=$f['payment']['payment_adjustments'][0]['payment_adjustment_items'][0];
        foreach (['Money'=>['amount'=>1,'divisor'=>3,'currency_code'=>'USD'],'PaymentAdjustmentItem'=>$item,'ShopRefund'=>['amount'=>['amount'=>0,'divisor'=>1,'currency_code'=>'JPY'],'created_timestamp'=>1790000000,'reason'=>null,'status'=>'pending'],'Payments'=>['count'=>2,'results'=>[$f['payment']]],'PaymentAccountLedgerEntries'=>['count'=>1,'results'=>[$f['ledger_entry']]]] as $schema=>$input) {
            $out=self::parse($schema,$input);self::assertSame($input,$out['native']);self::assertSame('UNKNOWN',$out['source_completeness']);
        }
        $out=self::parse('Payment',['payment_id'=>11,'shop_id'=>777]);
        self::assertArrayNotHasKey('amount_fees',$out['native']);self::assertArrayNotHasKey('create_timestamp',$out['native']);
        self::assertSame(['count'=>0,'results'=>[]],self::parse('Payments',['count'=>0,'results'=>[]])['native']);
    }

    public function testRetryKeysBindCanonicalNativeContentButKeepOriginalByteHashes():void
    {
        $f=self::fixture();$a=self::parse('Payment',$f['payment']);self::assertSame($a,self::parse('Payment',$f['payment']));
        $changed=$f['payment'];$changed['status']='settled';$b=self::parse('Payment',$changed);
        self::assertSame($a['source_identity_keys'],$b['source_identity_keys']);self::assertNotSame($a['observation_key'],$b['observation_key']);
        self::assertSame('UNKNOWN',$b['settlement']);
        $raw=json_encode(array_reverse($f['payment'],true),JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
        $capture=$f['scope']+['observed_at'=>'2026-10-20T12:00:00Z','source_reference'=>'local://synthetic/etsy-native-v1','source_sha256'=>hash('sha256',$raw)];
        $c=EtsyFinancialSourceParser::parse('Payment',$raw,$capture,$f['scope']);
        self::assertSame($a['observation_key'],$c['observation_key']);self::assertNotSame($a['raw_source_sha256'],$c['raw_source_sha256']);
    }

    public function testInt64AndNativeNetFormulaRemainExactWithoutFloatOrRounding():void
    {
        $p=self::fixture()['payment'];foreach(['amount_gross','amount_fees'] as $key)$p[$key]['amount']=PHP_INT_MAX;$p['amount_net']['amount']=0;
        self::assertSame(PHP_INT_MAX,self::parse('Payment',$p)['native']['amount_gross']['amount']);
        $money=['amount'=>PHP_INT_MIN,'divisor'=>7,'currency_code'=>'USD'];self::assertSame($money,self::parse('Money',$money)['native']);
    }

    public function testSameAdjustmentMayBeReferencedByDifferentLedgerEntriesWithoutCountingItTwice():void
    {
        $f=self::fixture();$a=$f['ledger_entry'];$a['payment_adjustments']=$f['payment']['payment_adjustments'];
        $b=$a;$b['entry_id']=13;
        $out=self::parse('PaymentAccountLedgerEntries',['count'=>2,'results'=>[$a,$b]]);
        self::assertCount(4,$out['source_identity_keys']);
        self::assertSame($a['payment_adjustments'],$out['native']['results'][1]['payment_adjustments']);
        self::assertSame([],$out['finance_declarations']);
    }

    public function testSchemaObservationCannotBeImportedAsAnExistingFinanceDeclaration():void
    {
        $out=self::parse('Payment',self::fixture()['payment']);
        $this->expectException(InvalidArgumentException::class);DeclarationContract::normalize($out);
    }

    /** @dataProvider malformed */
    public function testMalformedAmbiguousAndForeignObjectsFailClosed(string $schema,array $input):void
    {
        $this->expectException(InvalidArgumentException::class);self::parse($schema,$input);
    }
    public static function malformed():array
    {
        $f=self::fixture();$p=$f['payment'];$rows=[];
        foreach(['11',11.0,true,0,-1] as $id)$rows[]=['Payment',array_replace($p,['payment_id'=>$id])];
        foreach([0,-1,100.0,'100'] as $divisor)$rows[]=['Money',['amount'=>1,'divisor'=>$divisor,'currency_code'=>'USD']];
        foreach([1.25,'1',true] as $amount)$rows[]=['Money',['amount'=>$amount,'divisor'=>100,'currency_code'=>'USD']];
        $rows[]=['Money',['amount'=>1,'currency_code'=>'USD']];$rows[]=['Money',['amount'=>1,'divisor'=>100,'currency_code'=>'usd']];
        $rows[]=['Payment',array_replace($p,['shop_id'=>778])];$rows[]=['Payment',array_replace($p,['created_timestamp'=>1790000001])];
        $rows[]=['Payment',array_replace($p,['currency'=>'usd'])];
        $rows[]=['PaymentAccountLedgerEntry',array_replace($f['ledger_entry'],['currency'=>'eur'])];
        $rows[]=['Payment',array_replace($p,['updated_timestamp'=>1789999999,'update_timestamp'=>1789999999])];
        $rows[]=['Payment',array_replace($p,['created_timestamp'=>PHP_INT_MAX,'create_timestamp'=>PHP_INT_MAX])];
        $x=$p;$x['amount_net']['amount']++;$rows[]=['Payment',$x];
        $x=$p;$x['payment_adjustments'][0]['payment_id']=99;$rows[]=['Payment',$x];
        $x=$p;$x['payment_adjustments'][]=$x['payment_adjustments'][0];$rows[]=['Payment',$x];
        $x=$p;$x['payment_adjustments'][0]['payment_adjustment_items'][0]['payment_adjustment_id']=99;$rows[]=['Payment',$x];
        $x=$p;$x['payment_adjustments'][0]['payment_adjustment_items'][]=$x['payment_adjustments'][0]['payment_adjustment_items'][0];$rows[]=['Payment',$x];
        $rows[]=['Payments',['count'=>2,'results'=>[$p,$p]]];$rows[]=['Payments',['count'=>0,'results'=>[$p]]];
        $a=$f['ledger_entry'];$a['payment_adjustments']=$p['payment_adjustments'];$b=$a;$b['entry_id']=13;$b['payment_adjustments'][0]['total_fee_adjustment_amount']=1;
        $rows[]=['PaymentAccountLedgerEntries',['count'=>2,'results'=>[$a,$b]]];
        $rows[]=['Payment',array_replace($p,['authorization'=>'DO_NOT_LOG'])];
        $rows[]=['Payment',array_replace($p,['status'=>str_repeat('x',4097)])];
        $rows[]=['Payment',['amount_gross'=>['amount'=>1,'divisor'=>100,'currency_code'=>'USD']]];
        $rows[]=['Unknown',$p];return $rows;
    }

    /** @dataProvider wrongScopes */
    public function testCaptureAndSelectedScopeMustMatchExactly(array $scope,array $capture):void
    {
        $this->expectException(InvalidArgumentException::class);self::parse('Payment',self::fixture()['payment'],$scope,$capture);
    }
    public static function wrongScopes():array
    {
        return [[[],['shop_key'=>'personalized_pod']],[[],['environment'=>'production']],[[],['business_id'=>2]],[[],['store_id'=>3]],[[],['external_shop_id'=>778]],[['shop_key'=>'standard_pod'],[]],[['shop_key'=>'jewelry'],[]],[['business_id'=>'1'],[]],[[],['source_sha256'=>str_repeat('a',64)]],[[],['observed_at'=>'2026-10-20 12:00:00']],[[],['source_reference'=>'local://a/../b']]];
    }

    /** @dataProvider badRaw */
    public function testUnsafeJsonAndDuplicateMembersAreRejectedWithoutLeakingSource(string $raw):void
    {
        $f=self::fixture();$capture=$f['scope']+['observed_at'=>'2026-10-20T12:00:00Z','source_reference'=>'local://synthetic/source','source_sha256'=>hash('sha256',$raw)];
        try {EtsyFinancialSourceParser::parse('Payment',$raw,$capture,$f['scope']);self::fail('Unsafe source accepted');}
        catch(InvalidArgumentException $e){self::assertSame('Unsupported or inconsistent Etsy financial source.',$e->getMessage());}
    }
    public static function badRaw():array {return [['{"payment_id":11,"payment_id":12,"shop_id":777}'],['{"payment_id":11,"shop_id":777,"sh\\u006fp_id":778}'],['{"payment_id":9223372036854775808,"shop_id":777}'],['{"payment_id":11,"shop_id":777,"status":"DO_NOT_LOG","status":"x"}'],['{"payment_id":11,"shop_id":777,"amount_gross":{"amount":1,"divisor":100,"currency_code":"USD","currency_code":"GBP"}}'],['[]'],['{'],['{"payment_id":11,"shop_id":777,"status":"'.str_repeat('x',65536).'"}']];}

    public function testPublicSchemaAssetIsPinned():void
    {
        self::assertSame(EtsyFinancialSourceParser::CONTRACT_SHA256,hash_file('sha256',dirname(__DIR__,2).'/includes/Finance/contracts/etsy-openapi-finance-3.0.0.json'));
    }
}
