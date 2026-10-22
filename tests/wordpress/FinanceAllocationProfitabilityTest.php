<?php
declare(strict_types=1);

use DigiForge\Database\Tables;
use DigiForge\Finance\AllocationProfitabilityReadModel;
use DigiForge\Finance\AllocationReview;
use DigiForge\Finance\DeclarationContract;

require_once __DIR__.'/FinanceAllocationReviewTest.php';

/** Reviewed synthetic declarations only; no actual financial approval or provider access. */
final class FinanceAllocationProfitabilityTest extends WP_UnitTestCase
{
    private array $actors;
    private ?Closure $ledgerFilter=null;
    private string $temporaryLedger='';

    protected function setUp(): void
    {
        parent::setUp();DigiForge\Core\Activator::activate();
        $this->actors=ReviewedLocalFixture::actors('personalized_pod');
        foreach (['author','reviewer'] as $actor) ShopAccessFixture::authorizeActor($this->actors[$actor],['personalized_pod'],['manage_digiforge_finance']);
        wp_set_current_user($this->actors['author']);
        global $wpdb;$table=Tables::finance_ledger();
        $this->temporaryLedger=$table.'_profit_'.substr(str_replace('-','',wp_generate_uuid4()),0,8);
        self::assertNotFalse($wpdb->query('CREATE TEMPORARY TABLE '.$this->temporaryLedger.' LIKE '.$table));
        $temporary=$this->temporaryLedger;$this->ledgerFilter=static fn(string $sql): string => str_replace($table,$temporary,$sql);
        add_filter('query',$this->ledgerFilter,99);
    }
    protected function tearDown(): void
    {
        if ($this->ledgerFilter!==null) remove_filter('query',$this->ledgerFilter,99);
        global $wpdb;if ($this->temporaryLedger!=='') $wpdb->query('DROP TEMPORARY TABLE IF EXISTS '.$this->temporaryLedger);
        parent::tearDown();
    }
    private function fixture(): array
    {
        // Reuse established source/ownership fixture without running a second test lifecycle.
        $method=new ReflectionMethod(FinanceAllocationReviewTest::class,'fixture');
        $f=$method->invoke(new FinanceAllocationReviewTest('testSeparateReviewPersistsExactPacketWithoutFinancialAuthority'));
        $f['scope']=array_intersect_key($f['target'],array_flip(['shop_key','business_id','store_id','environment']));
        $f['input']=$f['source'];unset($f['input']['declaration_hash'],$f['input']['accounting_convention'],$f['input']['authenticity']);
        return $f;
    }
    private function reviewed(array $f,array $changes=[],?array $lines=null): array
    {
        wp_set_current_user($this->actors['author']);
        $input=array_replace_recursive($f['input'],$changes);$source=DeclarationContract::normalize($input);
        self::assertIsArray((new DigiForge\Finance\Repository())->importDeclaration($input));
        $lines=$lines??[['target'=>$f['target'],'amount'=>$source['amount'],'base_amount'=>$source['base_amount']]];
        $service=new AllocationReview();
        $pending=$service->propose($source['declaration_hash'],$f['scope'],$lines,time()-1,time()+600,'profit-propose-'.wp_generate_uuid4(),0);
        self::assertIsArray($pending);wp_set_current_user($this->actors['reviewer']);
        $approved=$service->decide($source['declaration_hash'],$f['scope'],$pending['review']['revision'],$pending['review']['proposal_hash'],'APPROVED','profit-approve-'.wp_generate_uuid4());
        self::assertIsArray($approved);
        return ['source'=>$source,'input'=>$input,'current'=>$approved,'ref'=>[
            'source_hash'=>$source['declaration_hash'],'revision'=>$approved['review']['revision'],
            'state_hash'=>$approved['review']['state_hash'],'proposal_hash'=>$approved['review']['proposal_hash']]];
    }
    private static function scope(array $f,array $changes=[]): array
    {
        return array_replace($f['scope']+['period_start'=>'2026-10-01','period_end'=>'2026-10-31','base_currency'=>'USD'],$changes);
    }
    public function testReviewedSubsetGroupsExactAmountsAcrossStoredDimensions(): void
    {
        $f=$this->fixture();global $wpdb;$now=current_time('mysql',true);
        self::assertSame(1,$wpdb->insert(Tables::listings(),['product_version_id'=>$f['target']['product_version_id'],'channel'=>'etsy','environment'=>'test','shop_reference'=>'personalized_pod','title'=>'Second synthetic listing','created_at'=>$now,'updated_at'=>$now]));$listing=(int)$wpdb->insert_id;
        self::assertSame(1,$wpdb->insert(Tables::orders(),['channel'=>'etsy','environment'=>'test','shop_reference'=>'personalized_pod','currency'=>'USD','created_at'=>$now,'updated_at'=>$now]));$order=(int)$wpdb->insert_id;
        self::assertSame(1,$wpdb->insert(Tables::order_line_items(),['order_id'=>$order,'listing_id'=>$listing,'product_version_id'=>$f['target']['product_version_id'],'provider_mapping_id'=>$f['target']['provider_mapping_id'],'currency'=>'USD','environment'=>'test','created_at'=>$now,'updated_at'=>$now]));
        $other=array_replace($f['target'],['order_id'=>$order,'listing_id'=>$listing,'order_line_item_id'=>(int)$wpdb->insert_id]);
        $lines=static fn(string $a,string $b): array => [['target'=>$f['target'],'amount'=>$a,'base_amount'=>$a],['target'=>$other,'amount'=>$b,'base_amount'=>$b]];
        $restriction=['scope'=>['order_id'=>0,'listing_id'=>0]];
        $revenue=$this->reviewed($f,$restriction,$lines('6.0000','4.0000'));
        $cost=$this->reviewed($f,array_replace_recursive($restriction,['external_transaction_id'=>'synthetic-cogs','category'=>'COGS','amount'=>'4.0000','base_amount'=>'4.0000']),$lines('3.0000','1.0000'));
        $report=(new AllocationProfitabilityReadModel())->snapshot([$revenue['ref'],$cost['ref']],self::scope($f));self::assertIsArray($report);
        self::assertSame('PROVISIONAL',$report['state']);self::assertSame('10.0000',$report['totals']['gross_revenue']);self::assertSame('6.0000',$report['totals']['net_profit']);
        self::assertSame('EXPLICIT_REVIEWED_SUBSET',$report['coverage']['state']);self::assertSame(2,$report['coverage']['source_count']);self::assertSame(4,$report['coverage']['line_count']);
        self::assertCount(2,$report['groups']['order']);self::assertCount(2,$report['groups']['listing']);
        foreach (['business','store','shop','product','product_version','variant'] as $dimension) {
            self::assertCount(1,$report['groups'][$dimension]);self::assertSame('6.0000',$report['groups'][$dimension][0]['metrics']['net_profit']);
        }
        foreach ($report['groups']['order'] as $group) {self::assertSame('3.0000',$group['metrics']['net_profit']);self::assertCount(2,$group['provenance']);}
        self::assertSame($revenue['source']['declaration_hash'],$report['provenance'][0]['source_hash']);
        self::assertSame($revenue['current']['allocation_packet']['lines'][0]['reference_hash'],$report['provenance'][0]['target_reference_hash']);
        self::assertSame('UNKNOWN',$report['authenticity']);self::assertSame('UNKNOWN',$report['source_completeness']);
        foreach (['actual_settlement_verified','period_approval_authorized','external_execution_authorized','money_movement_authorized','tax_filing_authorized'] as $flag) self::assertFalse($report[$flag]);
    }
    public function testDuplicateMissingForeignAndOutOfPeriodEvidenceNeverProduceTotals(): void
    {
        $f=$this->fixture();$r=$this->reviewed($f);$reader=new AllocationProfitabilityReadModel();$scope=self::scope($f);
        self::assertWPError($reader->snapshot([],$scope));
        self::assertWPError($reader->snapshot([$r['ref'],$r['ref']],$scope));
        self::assertWPError($reader->snapshot([array_replace($r['ref'],['source_hash'=>str_repeat('f',64)])],$scope));
        foreach ([['shop_key'=>'digital'],['business_id'=>$f['scope']['business_id']+1],['store_id'=>$f['scope']['store_id']+1],['environment'=>'live'],
            ['period_start'=>'2026-11-01','period_end'=>'2026-11-30'],['base_currency'=>'EUR'],['period_start'=>'2026-02-30'],['period_end'=>'2026-1-1']] as $change) self::assertWPError($reader->snapshot([$r['ref']],self::scope($f,$change)));
        foreach (['revision'=>99,'state_hash'=>str_repeat('b',64),'proposal_hash'=>str_repeat('c',64)] as $key=>$value) self::assertWPError($reader->snapshot([array_replace($r['ref'],[$key=>$value])],$scope));
        self::assertWPError($reader->snapshot(array_fill(0,21,$r['ref']),$scope));
    }
    public function testTargetDriftBlocksReviewedSubset(): void
    {
        $f=$this->fixture();$r=$this->reviewed($f);global $wpdb;
        self::assertSame(1,$wpdb->update(Tables::order_line_items(),['unit_price_amount'=>'2.0000'],['id'=>$f['target']['order_line_item_id']]));
        self::assertWPError((new AllocationProfitabilityReadModel())->snapshot([$r['ref']],self::scope($f)));
    }
    public function testSourceRevisionAndReviewRevocationBlockOldPins(): void
    {
        $f=$this->fixture();$r=$this->reviewed($f);$reader=new AllocationProfitabilityReadModel();$service=new AllocationReview();
        self::assertIsArray($service->decide($r['ref']['source_hash'],$f['scope'],$r['ref']['revision'],$r['ref']['proposal_hash'],'REVOKED','profit-revoke-'.wp_generate_uuid4()));
        self::assertWPError($reader->snapshot([$r['ref']],self::scope($f)));
        wp_set_current_user($this->actors['author']);$p=$service->propose($r['ref']['source_hash'],$f['scope'],[['target'=>$f['target'],'amount'=>'10','base_amount'=>'10']],time()-1,time()+600,'profit-revise-'.wp_generate_uuid4(),3);self::assertIsArray($p);
        wp_set_current_user($this->actors['reviewer']);self::assertIsArray($service->decide($r['ref']['source_hash'],$f['scope'],$p['review']['revision'],$p['review']['proposal_hash'],'APPROVED','profit-reapprove-'.wp_generate_uuid4()));
        self::assertWPError($reader->snapshot([$r['ref']],self::scope($f)));
        $current=$service->current($r['ref']['source_hash'],$f['scope']);self::assertIsArray($current);$ref=array_replace($r['ref'],array_intersect_key($current['review'],$r['ref']));
        $next=array_replace($r['input'],['revision'=>2,'previous_revision_hash'=>$r['source']['declaration_hash'],'amount'=>'11.0000','base_amount'=>'11.0000']);
        self::assertIsArray((new DigiForge\Finance\Repository())->importDeclaration($next));self::assertWPError($reader->snapshot([$ref],self::scope($f)));
    }
    public function testMissingStoredTargetFailsClosed(): void
    {
        $f=$this->fixture();$r=$this->reviewed($f);global $wpdb;
        self::assertSame(1,$wpdb->delete(Tables::listings(),['id'=>$f['target']['listing_id']]));
        self::assertWPError((new AllocationProfitabilityReadModel())->snapshot([$r['ref']],self::scope($f)));
    }
    public function testRevokedActorFailsClosedWithIntactSourceAndTargets(): void
    {
        $f=$this->fixture();$r=$this->reviewed($f);
        ShopAccessFixture::provision([]);self::assertWPError((new AllocationProfitabilityReadModel())->snapshot([$r['ref']],self::scope($f)));
    }
    public function testAmbiguousCurrentMappingOwnershipFailsClosed(): void
    {
        $f=$this->fixture();$r=$this->reviewed($f);global $wpdb;$now=current_time('mysql',true);
        self::assertSame(1,$wpdb->insert(Tables::product_versions(),['product_id'=>$f['target']['product_id'],'version_label'=>'conflicting-allocation-owner','created_at'=>$now,'updated_at'=>$now]));$version=(int)$wpdb->insert_id;
        $owner=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::pod_business_mappings().' WHERE id=%d',$f['owner']),ARRAY_A);self::assertIsArray($owner);unset($owner['id']);
        $owner['product_version_id']=$version;self::assertSame(1,$wpdb->insert(Tables::pod_business_mappings(),$owner));
        self::assertWPError((new AllocationProfitabilityReadModel())->snapshot([$r['ref']],self::scope($f)));
    }
    public function testFinalSweepRechecksReferencesAfterLastInitialRead(): void
    {
        $f=$this->fixture();$r=$this->reviewed($f);global $wpdb;$reads=0;$mutated=false;
        // Determine the established current() read count; inject drift at the next sweep's first source read.
        $counter=static function(string $sql) use (&$reads): string {if (str_contains($sql,'SELECT')&&str_contains($sql,'digiforge_local_review_')) ++$reads;return $sql;};
        add_filter('query',$counter);try {self::assertIsArray((new AllocationReview())->current($r['ref']['source_hash'],$f['scope']));} finally {remove_filter('query',$counter);}
        self::assertGreaterThan(0,$reads);$threshold=$reads+1;$reads=0;
        $filter=static function(string $sql) use (&$reads,&$mutated,$threshold,$wpdb,$f): string {
            if (str_contains($sql,'SELECT')&&str_contains($sql,'digiforge_local_review_')&&++$reads===$threshold) {
                $mutated=true;self::assertSame(1,$wpdb->update(Tables::order_line_items(),['unit_price_amount'=>'3.0000'],['id'=>$f['target']['order_line_item_id']]));
            }
            return $sql;
        };
        add_filter('query',$filter);try {self::assertWPError((new AllocationProfitabilityReadModel())->snapshot([$r['ref']],self::scope($f)));} finally {remove_filter('query',$filter);}
        self::assertTrue($mutated);
    }
}
