<?php
declare(strict_types=1);
use DigiForge\Finance\DeclarationContract;
use DigiForge\Finance\DeclarationReadModel;
use DigiForge\Tests\FinanceDeclarationFixtures;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/FinanceDeclarationFixtures.php';
require_once dirname(__DIR__,2).'/includes/Finance/ExactDecimal.php';
require_once dirname(__DIR__,2).'/includes/Finance/ProvisionalProfitMetrics.php';
require_once dirname(__DIR__,2).'/includes/Finance/DeclarationContract.php';
require_once dirname(__DIR__,2).'/includes/Finance/DeclarationMatcher.php';
require_once dirname(__DIR__,2).'/includes/Finance/DeclarationReadModel.php';

final class FinanceDeclarationProjectionTest extends TestCase
{
    private function entry(array$changes=[],int$id=1):array{return['ledger_id'=>$id,'product_id'=>9,'declaration'=>DeclarationContract::normalize(FinanceDeclarationFixtures::input(array_replace_recursive(['scope'=>['shop_key'=>'personalized_pod']],$changes)))];}
    private function scope():array{return['shop_key'=>'personalized_pod','environment'=>'test','period_start'=>'2026-10-01','period_end'=>'2026-10-31','base_currency'=>'USD'];}
    public function testExactScopedProfitAndMarginReuseV2BucketsWithoutAuthenticityOrFilingAuthority():void
    {
        $entries=[];$id=0;foreach(['REVENUE'=>'100.0000','REFUND'=>'10.0000','MARKETPLACE_FEE'=>'5.0000','COGS'=>'30.0000','SHIPPING'=>'4.0000','AI'=>'2.0000','TAX_COLLECTED'=>'7.0000','TAX_EXPENSE'=>'1.0000']as$category=>$amount)$entries[]=$this->entry(['external_transaction_id'=>'entry-'.$category,'category'=>$category,'amount'=>$amount,'base_amount'=>$amount],++$id);
        $report=DeclarationReadModel::project($entries,$this->scope());
        self::assertSame('PROVISIONAL',$report['state']);self::assertSame('60.0000',$report['totals']['gross_profit']);
        self::assertSame('51.0000',$report['totals']['contribution_profit']);self::assertSame('48.0000',$report['totals']['net_profit']);self::assertSame('48.0000',$report['totals']['margin_percent']);
        self::assertSame('2.0000',$report['totals']['ai']);self::assertSame('2.0000',$report['totals']['other_expense']);
        self::assertSame('48.0000',$report['groups']['product'][0]['metrics']['net_profit']);self::assertSame(9,$report['groups']['product'][0]['dimension_id']);
        self::assertSame('UNKNOWN',$report['authenticity']);self::assertSame('UNKNOWN',$report['source_completeness']);
        foreach(['period_approval_authorized','money_movement_authorized','tax_filing_authorized','external_execution_authorized']as$key)self::assertFalse($report[$key]);
        self::assertSame('ROW_ARRAYS_NOT_CSV',$report['gst_review']['format']);self::assertCount(8,$report['gst_review']['rows']);self::assertSame('UNKNOWN',$report['gst_review']['rows'][0]['jurisdiction']);
    }
    public function testLatestRevisionIsCountedOnceAndMissingHistoryOrCurrencyConflictBlocksTotals():void
    {
        $one=$this->entry();$two=$this->entry(['revision'=>2,'previous_revision_hash'=>$one['declaration']['declaration_hash'],'amount'=>'12.0000','base_amount'=>'12.0000'],2);
        self::assertSame('12.0000',DeclarationReadModel::project([$one,$two],$this->scope())['totals']['gross_revenue']);
        $missing=DeclarationReadModel::project([$two],$this->scope());self::assertSame('BLOCKED',$missing['state']);self::assertNull($missing['totals']);
        $foreign=$this->entry(['external_transaction_id'=>'foreign-currency','currency'=>'EUR','base_currency'=>'EUR'],3);
        self::assertSame('BLOCKED',DeclarationReadModel::project([$one,$foreign],$this->scope())['state']);
        $one['declaration']['amount']='999.0000';self::assertSame('BLOCKED',DeclarationReadModel::project([$one],$this->scope())['state']);
    }
    public function testEmptyTruncatedAndOtherScopesNeverBecomeVerifiedZeroOrMargin():void
    {
        $empty=DeclarationReadModel::project([],$this->scope());self::assertSame('NO_DECLARATIONS',$empty['state']);self::assertNull($empty['totals']);
        $other=$this->entry(['scope'=>['shop_key'=>'digital']]);self::assertSame('NO_DECLARATIONS',DeclarationReadModel::project([$other],$this->scope())['state']);
        $truncated=DeclarationReadModel::project([$this->entry()],$this->scope(),true);self::assertSame('BLOCKED',$truncated['state']);self::assertNull($truncated['totals']);
        $cost=$this->entry(['category'=>'COGS']);self::assertNull(DeclarationReadModel::project([$cost],$this->scope())['totals']['margin_percent']);
    }
    public function testLateEvidenceAndPartialRefundsAreProvisionalAndScopeAttributionIsDirectOnly():void
    {
        $paid=$this->entry(['amount'=>'100.0000','base_amount'=>'100.0000']);
        $refund=$this->entry(['external_transaction_id'=>'partial-refund','category'=>'REFUND','amount'=>'12.5000','base_amount'=>'12.5000','observed_at'=>'2026-11-01T00:00:00Z'],2);
        $report=DeclarationReadModel::project([$paid,$refund],$this->scope());self::assertSame('PROVISIONAL',$report['state']);self::assertSame('LATE',$report['matching']['state']);self::assertSame('87.5000',$report['totals']['net_profit']);
        self::assertSame('87.5000',$report['totals']['margin_percent']);self::assertSame('UNKNOWN',$report['source_completeness']);
    }
    public function testDistinctLocalMappingsWithTheSameVariantLabelNeverShareAProfitBucket():void
    {
        $one=$this->entry(['scope'=>['provider_mapping_id'=>11,'variant_ref'=>'shared-label']]);
        $two=$this->entry(['external_transaction_id'=>'other-mapping','scope'=>['provider_mapping_id'=>12,'variant_ref'=>'shared-label']],2);
        $report=DeclarationReadModel::project([$one,$two],$this->scope());
        self::assertCount(2,$report['groups']['variant']);self::assertNotSame($report['groups']['variant'][0]['dimension_id'],$report['groups']['variant'][1]['dimension_id']);
    }
    public function testScopeDriftCannotResurrectASupersededForeignShopRevision():void
    {
        $one=$this->entry(['scope'=>['shop_key'=>'digital']]);$two=$this->entry(['revision'=>2,'previous_revision_hash'=>$one['declaration']['declaration_hash'],'amount'=>'12.0000','base_amount'=>'12.0000'],2);
        $scope=array_replace($this->scope(),['shop_key'=>'digital']);$report=DeclarationReadModel::project([$one,$two],$scope);
        self::assertSame('BLOCKED',$report['state']);self::assertNull($report['totals']);
        foreach($report['matching']['rows']as$row)self::assertSame(1,$row['ledger_id']);
    }
    public function testNonCanonicalOrNulDatesFailBeforeAnyDatabaseRead():void
    {
        foreach(["2026-10-01\0",'2026-02-30','2026-1-1']as$date){
            $scope=array_replace($this->scope(),['period_start'=>$date]);
            self::assertSame('BLOCKED',DeclarationReadModel::project([],$scope)['state']);
            self::assertSame('BLOCKED',(new DeclarationReadModel())->snapshot('personalized_pod','test',$date,'2026-10-31','USD')['state']);
        }
    }
}
