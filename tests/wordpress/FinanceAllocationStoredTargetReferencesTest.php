<?php
declare(strict_types=1);

use DigiForge\Database\Tables;
use DigiForge\Finance\AllocationProposal;
use DigiForge\Finance\AllocationReadModel;
use DigiForge\Finance\AllocationStoredTargetReferences;
use DigiForge\Finance\DeclarationContract;
use DigiForge\Tests\FinanceDeclarationFixtures;

require_once dirname(__DIR__).'/unit/FinanceDeclarationFixtures.php';
require_once __DIR__.'/fixtures/ShopAccessFixture.php';

/** Synthetic current-storage lineage only; no actual finance/provider/approval action. */
final class FinanceAllocationStoredTargetReferencesTest extends WP_UnitTestCase
{
    private ?Closure $ledgerFilter=null;
    private string $temporaryLedger='';
    protected function setUp(): void
    {
        parent::setUp(); DigiForge\Core\Activator::activate();global $wpdb;
        $table=Tables::finance_ledger();$this->temporaryLedger=$table.'_allocation_'.substr(str_replace('-','',wp_generate_uuid4()),0,8);
        self::assertNotFalse($wpdb->query('CREATE TEMPORARY TABLE '.$this->temporaryLedger.' LIKE '.$table));
        $temporary=$this->temporaryLedger;$this->ledgerFilter=static fn(string $sql): string => str_replace($table,$temporary,$sql);
        add_filter('query',$this->ledgerFilter,99);
    }
    protected function tearDown(): void
    {
        if ($this->ledgerFilter !== null) remove_filter('query',$this->ledgerFilter,99);
        global $wpdb;if ($this->temporaryLedger !== '') $wpdb->query('DROP TEMPORARY TABLE IF EXISTS '.$this->temporaryLedger);
        parent::tearDown();
    }

    private function fixture(): array
    {
        global $wpdb;$now=current_time('mysql',true);
        wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));
        $insert=static function(string $table,array $data) use ($wpdb,$now): int {
            self::assertSame(1,$wpdb->insert($table,array_replace(['created_at'=>$now,'updated_at'=>$now],$data)));
            return (int)$wpdb->insert_id;
        };
        $opportunity=$insert(Tables::opportunities(),['title'=>'Synthetic allocation ancestry']);
        $insert(Tables::research_candidates(),['title'=>'Synthetic allocation','canonical_title'=>'synthetic allocation','fingerprint'=>hash('sha256',wp_generate_uuid4()),'score_version'=>'fixture','score_inputs'=>wp_json_encode(['shop_key'=>'personalized_pod']),'opportunity_id'=>$opportunity]);
        $family=$insert(Tables::product_families(),['opportunity_id'=>$opportunity,'name'=>'Synthetic allocation']);
        $product=$insert(Tables::products(),['product_family_id'=>$family,'name'=>'Synthetic allocation']);
        $version=$insert(Tables::product_versions(),['product_id'=>$product,'version_label'=>'allocation-fixture']);
        $business=$insert(Tables::businesses(),['business_key'=>'allocation-'.wp_generate_uuid4(),'status'=>'ACTIVE']);
        $store=$insert(Tables::stores(),['business_id'=>$business,'store_key'=>'allocation-'.wp_generate_uuid4(),'status'=>'ACTIVE']);
        $program=$insert(Tables::product_programs(),['business_id'=>$business,'store_id'=>$store,'program_key'=>'PERSONALIZED_POD','status'=>'ACTIVE']);
        $mapping=$insert(Tables::pod_mappings(),['product_version_id'=>$version,'production_plan_id'=>0,'provider'=>'printify','environment'=>'test','provider_product_key'=>'allocation-product','provider_variant_key'=>'allocation-variant','mapping_version'=>wp_generate_uuid4(),'state'=>'DRAFT']);
        $owner=$insert(Tables::pod_business_mappings(),['business_id'=>$business,'store_id'=>$store,'product_program_id'=>$program,'product_version_id'=>$version,'provider_mapping_id'=>$mapping,'state'=>'APPROVED','approved_by'=>get_current_user_id(),'approved_at'=>$now]);
        $listing=$insert(Tables::listings(),['product_version_id'=>$version,'channel'=>'etsy','environment'=>'test','shop_reference'=>'personalized_pod','title'=>'Synthetic allocation listing']);
        $order=$insert(Tables::orders(),['channel'=>'etsy','environment'=>'test','shop_reference'=>'personalized_pod','currency'=>'USD']);
        $line=$insert(Tables::order_line_items(),['order_id'=>$order,'listing_id'=>$listing,'product_version_id'=>$version,'provider_mapping_id'=>$mapping,'currency'=>'USD','environment'=>'test']);
        $source=DeclarationContract::normalize(FinanceDeclarationFixtures::input(['scope'=>['shop_key'=>'personalized_pod','business_id'=>$business,'store_id'=>$store,'order_id'=>$order,'product_version_id'=>$version,'listing_id'=>$listing,'provider_mapping_id'=>$mapping,'variant_ref'=>'allocation-variant']]));
        $target=['shop_key'=>'personalized_pod','business_id'=>$business,'store_id'=>$store,'order_id'=>$order,'order_line_item_id'=>$line,'product_id'=>$product,'product_version_id'=>$version,'listing_id'=>$listing,'provider_mapping_id'=>$mapping,'variant_ref'=>'allocation-variant','environment'=>'test','currency'=>'USD'];
        return compact('source','target','owner');
    }

    private static function build(array $fixture): array
    {
        return AllocationProposal::build([['ledger_id'=>1,'declaration'=>$fixture['source']]],$fixture['source']['declaration_hash'],
            [['target'=>$fixture['target'],'amount'=>'10','base_amount'=>'10']],new AllocationStoredTargetReferences());
    }

    private function persistedFixture(): array
    {
        $fixture=$this->fixture();ShopAccessFixture::reset();
        ShopAccessFixture::authorizeActor(get_current_user_id(),['personalized_pod'],['manage_digiforge_finance']);
        $input=$fixture['source'];unset($input['declaration_hash'],$input['accounting_convention'],$input['authenticity']);
        $fixture['input']=$input;
        $row=(new DigiForge\Finance\Repository())->importDeclaration($input);self::assertIsArray($row);$fixture['row']=$row;
        $fixture['scope']=array_intersect_key($fixture['target'],array_flip(['shop_key','business_id','store_id','environment']));
        return $fixture;
    }

    private static function preview(array $fixture, ?array $original=null, string $amount='10'): array|WP_Error
    {
        return (new AllocationReadModel())->preview($fixture['source']['declaration_hash'],$fixture['scope'],
            [['target'=>$fixture['target'],'amount'=>$amount,'base_amount'=>$amount]],$original);
    }

    public function testPreviewLoadsCurrentStoredHistoryAndDoesNotWriteBusinessRows(): void
    {
        $fixture=$this->persistedFixture();$writes=[];
        $filter=static function(string $sql) use (&$writes): string {
            if (preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP)\b/i',$sql)) $writes[]=$sql;
            return $sql;
        };
        add_filter('query',$filter);
        try {$packet=self::preview($fixture);self::assertIsArray($packet);self::assertSame('PROVISIONAL',$packet['state']);self::assertSame((int)$fixture['row']['id'],$packet['source_ledger_id']);}
        finally {remove_filter('query',$filter);}
        self::assertSame([],$writes);
    }

    public function testPreviewRequiresExplicitFinanceShopAssignmentBeforeReadingSource(): void
    {
        $fixture=$this->persistedFixture();ShopAccessFixture::reset();
        self::assertWPError(self::preview($fixture));
        wp_set_current_user(0);self::assertWPError(self::preview($fixture));
    }

    public function testPreviewRejectsSourceFromAnotherExplicitBusinessScope(): void
    {
        $fixture=$this->persistedFixture();$fixture['scope']['business_id']+=999;
        self::assertWPError(self::preview($fixture));
    }

    public function testPreviewCannotReuseAStoredSupersededRevision(): void
    {
        $fixture=$this->persistedFixture();
        $revision=array_replace($fixture['input'],['revision'=>2,'previous_revision_hash'=>$fixture['source']['declaration_hash'],'amount'=>'11','base_amount'=>'11']);
        self::assertIsArray((new DigiForge\Finance\Repository())->importDeclaration($revision));
        self::assertWPError(self::preview($fixture));
    }

    public function testPreviewFindsCrossPeriodDependentReversalAndRequiresExactOriginalPacket(): void
    {
        $fixture=$this->persistedFixture();$original=self::preview($fixture);self::assertIsArray($original);
        $reversal=array_replace($fixture['input'],['external_transaction_id'=>'allocation-november-reversal',
            'reversal_of_hash'=>$fixture['source']['declaration_hash'],'amount'=>'-10','base_amount'=>'-10',
            'occurred_at'=>'2026-11-10T12:00:00Z','observed_at'=>'2026-11-10T12:01:00Z']);
        self::assertIsArray((new DigiForge\Finance\Repository())->importDeclaration($reversal));
        self::assertWPError(self::preview($fixture));
        $fixture['source']=DeclarationContract::normalize($reversal);
        self::assertWPError(self::preview($fixture,null,'-10'));
        $packet=self::preview($fixture,$original,'-10');self::assertIsArray($packet);
        self::assertSame('EXPLICIT_REVERSAL_PROPOSAL',$packet['reconciliation_state']);
    }

    public function testPreviewMissingOrDamagedStoredSourceCannotBecomeAProposal(): void
    {
        $fixture=$this->persistedFixture();global $wpdb;
        self::assertSame(1,$wpdb->update(Tables::finance_ledger(),['metadata'=>'{"finance_declaration":{"tampered":true}}'],['id'=>$fixture['row']['id']]));
        self::assertWPError(self::preview($fixture));
        self::assertSame(1,$wpdb->delete(Tables::finance_ledger(),['id'=>$fixture['row']['id']]));
        self::assertWPError(self::preview($fixture));
    }

    public function testPreviewTruncatedEnvironmentNeverReturnsAPartialPacket(): void
    {
        $fixture=$this->persistedFixture();global $wpdb;
        $copy=$fixture['row'];unset($copy['id']);
        for ($i=0;$i<200;++$i) {$copy['idempotency_key']='synthetic-allocation-truncation-'.$i;self::assertSame(1,$wpdb->insert(Tables::finance_ledger(),$copy));}
        self::assertWPError(self::preview($fixture));
    }

    public function testPreviewSourceReadFailureCannotReturnCallerEvidence(): void
    {
        $fixture=$this->persistedFixture();global $wpdb;
        $filter=static fn(string $sql): string => str_starts_with($sql,'SELECT * FROM '.Tables::finance_ledger().' WHERE environment=') ? 'SELECT * FROM missing_allocation_history_fixture' : $sql;
        $previous=$wpdb->suppress_errors(true);add_filter('query',$filter);
        try {self::assertWPError(self::preview($fixture));}
        finally {remove_filter('query',$filter);$wpdb->suppress_errors($previous);}
    }

    public function testPreviewRechecksTargetLineageAfterItsFinalSourceHistoryRead(): void
    {
        $fixture=$this->persistedFixture();global $wpdb;$reads=0;$mutated=false;
        $filter=static function(string $sql) use ($fixture,$wpdb,&$reads,&$mutated): string {
            if (str_starts_with($sql,'SELECT * FROM '.Tables::finance_ledger().' WHERE environment=') && ++$reads === 2) {
                $mutated=$wpdb->update(Tables::order_line_items(),['unit_price_amount'=>'1.0000'],['id'=>$fixture['target']['order_line_item_id']]) === 1;
            }
            return $sql;
        };
        add_filter('query',$filter);
        try {self::assertWPError(self::preview($fixture));}
        finally {remove_filter('query',$filter);}
        self::assertTrue($mutated);
    }

    public function testCurrentStoredExactLineResolvesWithoutAnyBusinessWrite(): void
    {
        $fixture=$this->fixture();global $wpdb;
        $writes=[];$filter=static function(string $sql) use (&$writes): string {
            if (preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP)\b/i',$sql)) $writes[]=$sql;
            return $sql;
        };
        add_filter('query',$filter);
        try {$packet=self::build($fixture);self::assertSame('PROVISIONAL',$packet['state']);self::assertSame($fixture['target'],$packet['lines'][0]['target']);}
        finally {remove_filter('query',$filter);}
        self::assertSame([],$writes);
    }

    /** @dataProvider driftCases */
    public function testCurrentStoredLineageDriftIsRejected(string $entity,array $changes): void
    {
        $fixture=$this->fixture();global $wpdb;
        $table=match($entity){'line'=>Tables::order_line_items(),'order'=>Tables::orders(),'listing'=>Tables::listings(),'version'=>Tables::product_versions(),'owner'=>Tables::pod_business_mappings()};
        $id=match($entity){'line'=>$fixture['target']['order_line_item_id'],'order'=>$fixture['target']['order_id'],'listing'=>$fixture['target']['listing_id'],'version'=>$fixture['target']['product_version_id'],'owner'=>$fixture['owner']};
        self::assertSame(1,$wpdb->update($table,$changes,['id'=>$id]));
        $this->expectException(InvalidArgumentException::class);self::build($fixture);
    }

    public static function driftCases(): array
    {
        return [['line',['currency'=>'EUR']],['line',['environment'=>'production']],['line',['listing_id'=>0]],
            ['line',['provider_mapping_id'=>0]],['order',['shop_reference'=>'jewelry']],['order',['environment'=>'sandbox']],
            ['listing',['shop_reference'=>'jewelry']],['version',['product_id'=>0]],['owner',['state'=>'REVOKED']]];
    }

    public function testDeletedLineCannotBecomeEmptySuccessfulAllocation(): void
    {
        $fixture=$this->fixture();global $wpdb;self::assertSame(1,$wpdb->delete(Tables::order_line_items(),['id'=>$fixture['target']['order_line_item_id']]));
        $this->expectException(InvalidArgumentException::class);self::build($fixture);
    }

    public function testFailedCurrentLineReadCannotUseCallerTupleAsFallback(): void
    {
        $fixture=$this->fixture();global $wpdb;
        $filter=static fn(string $sql): string => str_starts_with($sql,'SELECT * FROM '.Tables::order_line_items().' WHERE id=') ? 'SELECT * FROM missing_allocation_line_fixture' : $sql;
        $previous=$wpdb->suppress_errors(true);add_filter('query',$filter);
        try {$this->expectException(InvalidArgumentException::class);self::build($fixture);}
        finally {remove_filter('query',$filter);$wpdb->suppress_errors($previous);}
    }
}
