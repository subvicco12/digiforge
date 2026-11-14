<?php
declare(strict_types=1);

use DigiForge\Finance\EtsyFinancialSourceParser;
use DigiForge\Finance\EtsyNativeObservationStage;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__,2).'/includes/Finance/DeclarationContract.php';
require_once dirname(__DIR__,2).'/includes/Finance/ExactDecimal.php';
require_once dirname(__DIR__,2).'/includes/Finance/EtsyFinancialSourceParser.php';
if(is_file(dirname(__DIR__,2).'/includes/Finance/EtsyNativeObservationStage.php'))require_once dirname(__DIR__,2).'/includes/Finance/EtsyNativeObservationStage.php';

final class EtsyNativeObservationStageTest extends TestCase
{
    public static function fixture():array{return json_decode(file_get_contents(dirname(__DIR__).'/fixtures/finance/etsy-native-synthetic-v1.json'),true,32,JSON_THROW_ON_ERROR);}
    public static function context():array{return self::fixture()['scope']+['external_account_id'=>778,'integration_id'=>3,'source_contract_sha256'=>EtsyFinancialSourceParser::CONTRACT_SHA256];}
    public static function document(string $schema='Payment',?array $native=null):array
    {
        $raw=json_encode($native??self::fixture()['payment'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        return ['native_schema'=>$schema,'raw'=>$raw,'capture'=>self::fixture()['scope']+['observed_at'=>'2026-10-20T12:00:00Z','source_reference'=>'local://synthetic/native-stage','source_sha256'=>hash('sha256',$raw)]];
    }
    public static function stage(array $docs,?array $context=null,?array $selected=null):array
    {
        return EtsyNativeObservationStage::preview($docs,$context??self::context(),$selected??self::context(),1792497600);
    }
    public function testStagePreservesRawNativePrecisionAndIsNotAnAccountingImport():void
    {
        self::assertTrue(class_exists(EtsyNativeObservationStage::class),'Offline staging missing');
        $doc=self::document();$stage=self::stage([$doc]);
        self::assertSame($doc['raw'],$stage['documents'][0]['raw']);
        self::assertSame(1000,$stage['documents'][0]['observation']['native']['amount_gross']['divisor']);
        foreach(['authenticity','settlement','source_completeness','ownership']as$key)self::assertSame('UNKNOWN',$stage[$key]);
        foreach(['persistence_performed','durable_replay_protection','period_approval_authorized','external_execution_authorized','accounting_import_authorized']as$key)self::assertFalse($stage[$key]);
        self::assertSame([],$stage['finance_declarations']);self::assertSame($stage,self::stage([$doc]));
    }
    public function testRelatedIdenticalAdjustmentViewsAreLinkedWithoutDoubleCounting():void
    {
        $payment=self::fixture()['payment'];$adj=$payment['payment_adjustments'][0];
        $stage=self::stage([self::document(),self::document('PaymentAdjustment',$adj)]);
        self::assertGreaterThan(0,$stage['shared_identity_references']);self::assertSame([],$stage['finance_declarations']);
    }
    public function testConflictingNativeIdentityAcrossDocumentsIsRejected():void
    {
        $changed=self::fixture()['payment'];$changed['status']='changed';
        $this->expectException(InvalidArgumentException::class);self::stage([self::document(),self::document('Payment',$changed)]);
    }
    public function testConflictingNestedAdjustmentIsRejectedAcrossRelatedViews():void
    {
        $adj=self::fixture()['payment']['payment_adjustments'][0];$adj['status']='changed';
        $this->expectException(InvalidArgumentException::class);self::stage([self::document(),self::document('PaymentAdjustment',$adj)]);
    }
    public function testDuplicateObservationCannotBeCountedTwiceEvenWithDifferentJsonSpacing():void
    {
        $doc=self::document();$other=$doc;$other['raw']=json_encode(self::fixture()['payment'],JSON_PRETTY_PRINT);$other['capture']['source_sha256']=hash('sha256',$other['raw']);
        $this->expectException(InvalidArgumentException::class);self::stage([$doc,$other]);
    }
    /** @dataProvider scopeDrift */
    public function testExactSelectedContextRejectsForeignOrStaleSourceScope(string $key,mixed $value):void
    {
        $this->expectException(InvalidArgumentException::class);self::stage([self::document()],array_replace(self::context(),[$key=>$value]));
    }
    public static function scopeDrift():array{return [['shop_key','personalized_pod'],['external_account_id',779],['external_shop_id',778],['integration_id',4],['environment','production'],['business_id',2],['store_id',3],['source_contract_sha256',str_repeat('a',64)]];}
    public function testFutureCaptureAndUnknownContextFieldsFailClosed():void
    {
        $doc=self::document();$doc['capture']['observed_at']='2027-10-20T12:00:00Z';
        $this->expectException(InvalidArgumentException::class);self::stage([$doc]);
    }
    public function testUnknownContextCannotCarryApprovalFlags():void
    {
        $this->expectException(InvalidArgumentException::class);self::stage([self::document()],self::context()+['approved'=>true],self::context()+['approved'=>true]);
    }
    public function testMoneyWithoutTransactionIdentityStaysExplicitlyUnresolved():void
    {
        $stage=self::stage([self::document('Money',['amount'=>1,'divisor'=>7,'currency_code'=>'USD'])]);
        self::assertSame(1,$stage['documents_without_transaction_identity']);self::assertContains('MISSING_STABLE_TRANSACTION_ID',$stage['mapping_blockers']);
        self::assertSame(7,$stage['documents'][0]['observation']['native']['divisor']);
    }
    public function testPreviewProjectionWithholdsRawCustomerTextAndCannotBecomeAStoredDeclaration():void
    {
        $native=self::fixture()['payment'];$native['status']='<script>customer-marker</script>';
        $stage=self::stage([self::document('Payment',$native)]);$view=EtsyNativeObservationStage::projection($stage);
        self::assertStringNotContainsString('customer-marker',json_encode($view));self::assertArrayNotHasKey('documents',$view);
        self::assertSame('SCHEMA_VALIDATED_UNVERIFIED_SOURCE',$view['state']);
        $this->expectException(InvalidArgumentException::class);DigiForge\Finance\DeclarationContract::normalize($stage);
    }
    public function testTamperedPacketProjectionFailsClosed():void
    {
        $stage=self::stage([self::document()]);$stage['ownership']='VERIFIED';
        $this->expectException(InvalidArgumentException::class);EtsyNativeObservationStage::projection($stage);
    }
    public function testBoundedEmptyAndOversizedDocumentSetsFailClosed():void
    {
        $this->expectException(InvalidArgumentException::class);self::stage(array_fill(0,21,self::document()));
    }
    /** @dataProvider malformedStages */
    public function testMalformedAndTamperedInputsFailClosed(string $case):void
    {
        $documents=[self::document()];$context=self::context();
        switch($case){
            case 'empty':$documents=[];break;
            case 'hash':$documents[0]['capture']['source_sha256']=str_repeat('a',64);break;
            case 'version':$documents[0]['source_version']='invented';break;
            case 'schema':$documents[0]['native_schema']='Invoice';break;
            case 'bytes':$documents[0]['raw']=str_repeat(' ',262145);break;
            case 'identity_type':$context['external_account_id']='778';break;
            case 'deferred':$context['shop_key']='jewelry';break;
        }
        $this->expectException(InvalidArgumentException::class);self::stage($documents,$context,$context);
    }
    public static function malformedStages():array{return array_map(static fn($case)=>[$case],['empty','hash','version','schema','bytes','identity_type','deferred']);}
    public function testAllPinnedNativeSchemasKeepCorrectionBytesAndUnknownClassification():void
    {
        $f=self::fixture();$adjustment=$f['payment']['payment_adjustments'][0];
        foreach(['Money'=>['amount'=>1,'divisor'=>3,'currency_code'=>'USD'],'Payment'=>$f['payment'],
            'PaymentAccountLedgerEntry'=>$f['ledger_entry'],'PaymentAdjustment'=>$adjustment,'PaymentAdjustmentItem'=>$adjustment['payment_adjustment_items'][0],
            'Payments'=>['count'=>99,'results'=>[$f['payment']]],'PaymentAccountLedgerEntries'=>['count'=>99,'results'=>[$f['ledger_entry']]],
            'ShopRefund'=>['amount'=>['amount'=>0,'divisor'=>1,'currency_code'=>'JPY'],'created_timestamp'=>1790000000,'reason'=>null,'status'=>'pending']]as$schema=>$native){
            $doc=self::document($schema,$native);$stage=self::stage([$doc]);
            self::assertSame($native,$stage['documents'][0]['observation']['native']);self::assertSame($doc['raw'],$stage['documents'][0]['raw']);
            self::assertSame('UNKNOWN',$stage['source_completeness']);self::assertSame([],$stage['finance_declarations']);
        }
    }
}
