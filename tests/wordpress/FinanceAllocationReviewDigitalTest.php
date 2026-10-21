<?php
declare(strict_types=1);

use DigiForge\Database\Tables;
use DigiForge\Finance\AllocationProfitabilityReadModel;
use DigiForge\Finance\AllocationReview;
use DigiForge\Finance\DeclarationContract;
use DigiForge\Finance\DigitalSourceBinding;
use DigiForge\Finance\Validator;
use DigiForge\Tests\FinanceDeclarationFixtures;

require_once __DIR__.'/FinanceDigitalBindingTest.php';

/** Synthetic Digital end-to-end local evidence only; no provider calls or real credentials. */
final class FinanceAllocationReviewDigitalTest extends WP_UnitTestCase
{
    private ?Closure $ledgerFilter=null;
    private string $temporaryLedger='';

    protected function tearDown(): void
    {
        if ($this->ledgerFilter!==null) remove_filter('query',$this->ledgerFilter,99);
        global $wpdb;if ($this->temporaryLedger!=='') $wpdb->query('DROP TEMPORARY TABLE IF EXISTS '.$this->temporaryLedger);
        parent::tearDown();
    }

    private function fixture(): array
    {
        // Invoke only the established fixture helpers, never a second test lifecycle.
        // They create synthetic local connector metadata and explicit independent actors.
        $existing=new FinanceDigitalBindingTest('testExactReviewedDigitalBindingHasNoPodOrExternalAuthority');
        $fixture=(new ReflectionMethod(FinanceDigitalBindingTest::class,'fixture'))->invoke($existing);
        foreach (['author','reviewer'] as $actor) ShopAccessFixture::authorizeActor($fixture['actors'][$actor],['digital'],['manage_digiforge_finance']);
        wp_set_current_user($fixture['actors']['author']);
        $fixture['binding']=(new ReflectionMethod(FinanceDigitalBindingTest::class,'approve'))->invoke($existing,$fixture);
        global $wpdb;$table=Tables::finance_ledger();
        $this->temporaryLedger=$table.'_digitalreview_'.substr(str_replace('-','',wp_generate_uuid4()),0,8);
        self::assertNotFalse($wpdb->query('CREATE TEMPORARY TABLE '.$this->temporaryLedger.' LIKE '.$table));
        $temporary=$this->temporaryLedger;$this->ledgerFilter=static fn(string $sql): string => str_replace($table,$temporary,$sql);
        add_filter('query',$this->ledgerFilter,99);
        $now=current_time('mysql',true);
        $insert=static function(string $table,array $data) use ($wpdb,$now): int {
            self::assertSame(1,$wpdb->insert($table,$data+['created_at'=>$now,'updated_at'=>$now]));return (int)$wpdb->insert_id;
        };
        $listing=$insert(Tables::listings(),['product_version_id'=>$fixture['product_version_id'],'channel'=>'etsy','environment'=>'test','shop_reference'=>'digital','title'=>'Synthetic persistent Digital allocation']);
        $order=$insert(Tables::orders(),['channel'=>'etsy','environment'=>'test','shop_reference'=>'67757764','currency'=>'USD']);
        $line=$insert(Tables::order_line_items(),['order_id'=>$order,'listing_id'=>$listing,'product_version_id'=>$fixture['product_version_id'],'provider_mapping_id'=>0,'currency'=>'USD','environment'=>'test']);
        $input=$fixture['input'];
        $fixture['scope']=['shop_key'=>'digital','business_id'=>$input['business_id'],'store_id'=>$input['store_id'],'environment'=>'test'];
        $fixture['target']=['shop_key'=>'digital','business_id'=>$input['business_id'],'store_id'=>$input['store_id'],
            'order_id'=>$order,'order_line_item_id'=>$line,'product_id'=>$fixture['product_id'],'product_version_id'=>$fixture['product_version_id'],
            'listing_id'=>$listing,'provider_mapping_id'=>0,'variant_ref'=>'','environment'=>'test','currency'=>'USD'];
        $fixture['declaration']=FinanceDeclarationFixtures::input(['provider'=>'etsy','external_transaction_id'=>'synthetic-digital-reviewed-'.wp_generate_uuid4(),
            'scope'=>['shop_key'=>'digital','business_id'=>$input['business_id'],'store_id'=>$input['store_id'],'order_id'=>$order,
                'product_version_id'=>$fixture['product_version_id'],'listing_id'=>$listing,'provider_mapping_id'=>0,'variant_ref'=>'']]);
        return $fixture;
    }

    private function reviewed(array $fixture): array
    {
        $row=(new DigiForge\Finance\Repository())->importDeclaration($fixture['declaration']);self::assertIsArray($row);
        $source=DeclarationContract::normalize($fixture['declaration']);$service=new AllocationReview();
        $lines=[['target'=>$fixture['target'],'amount'=>$source['amount'],'base_amount'=>$source['base_amount']]];
        $pending=$service->propose($source['declaration_hash'],$fixture['scope'],$lines,time()-1,time()+600,'digital-allocation-propose-'.wp_generate_uuid4(),0);
        self::assertIsArray($pending);self::assertSame('PENDING',$pending['local_review_status']);
        self::assertWPError($service->current($source['declaration_hash'],$fixture['scope']));
        self::assertWPError($service->decide($source['declaration_hash'],$fixture['scope'],$pending['review']['revision'],$pending['review']['proposal_hash'],'APPROVED','digital-self-review-'.wp_generate_uuid4()));
        wp_set_current_user($fixture['actors']['reviewer']);
        $reviewed=$service->decide($source['declaration_hash'],$fixture['scope'],$pending['review']['revision'],$pending['review']['proposal_hash'],'APPROVED','digital-independent-review-'.wp_generate_uuid4());
        self::assertIsArray($reviewed);self::assertSame('REVIEWED_LOCAL_ALLOCATION',$reviewed['local_review_status']);
        self::assertSame($pending['allocation_packet'],$reviewed['allocation_packet']);
        self::assertSame($fixture['actors']['author'],$reviewed['review']['author_id']);
        self::assertSame($fixture['actors']['reviewer'],$reviewed['review']['reviewer_id']);
        self::assertSame($reviewed,$service->current($source['declaration_hash'],$fixture['scope']));
        return ['source'=>$source,'row'=>$row,'reviewed'=>$reviewed,'reference'=>[
            'source_hash'=>$source['declaration_hash'],'revision'=>$reviewed['review']['revision'],
            'state_hash'=>$reviewed['review']['state_hash'],'proposal_hash'=>$reviewed['review']['proposal_hash']]];
    }

    private static function reportScope(array $fixture): array
    {
        return $fixture['scope']+['period_start'=>'2026-10-01','period_end'=>'2026-10-31','base_currency'=>'USD'];
    }

    private static function assertNoAuthority(array $evidence): void
    {
        self::assertSame('UNKNOWN',$evidence['authenticity']);self::assertSame('UNKNOWN',$evidence['source_completeness']);
        foreach (['actual_settlement_verified','period_approval_authorized','external_execution_authorized','money_movement_authorized','tax_filing_authorized'] as $flag) self::assertFalse($evidence[$flag]);
    }

    public function testDigitalDeclarationStoredAllocationIndependentReviewAndProfitabilityUseNoPodMappings(): void
    {
        $fixture=$this->fixture();$podQueries=[];
        $observe=static function(string $sql) use (&$podQueries): string {
            foreach ([Tables::pod_mappings(),Tables::pod_business_mappings(),Tables::product_programs()] as $table) {
                if (str_contains($sql,$table)) $podQueries[]=$sql;
            }
            return $sql;
        };
        add_filter('query',$observe);
        try {
            $result=$this->reviewed($fixture);
            $report=(new AllocationProfitabilityReadModel())->snapshot([$result['reference']],self::reportScope($fixture));self::assertIsArray($report);
        } finally { remove_filter('query',$observe); }
        self::assertSame([],$podQueries,'Digital allocation and read profitability must not depend on POD/program tables.');
        self::assertSame('10.0000',$report['totals']['gross_revenue']);self::assertSame('10.0000',$report['totals']['net_profit']);
        self::assertSame('PROVISIONAL',$report['state']);self::assertSame('EXPLICIT_REVIEWED_SUBSET',$report['coverage']['state']);
        self::assertFalse($report['coverage']['full_period_coverage_verified']);self::assertSame(1,$report['coverage']['source_count']);self::assertSame(1,$report['coverage']['line_count']);
        self::assertSame([],$report['groups']['variant']);
        foreach (['business','store','shop','order','product','product_version','listing'] as $dimension) self::assertCount(1,$report['groups'][$dimension]);
        self::assertSame('digital',$report['groups']['shop'][0]['dimension_id']);
        self::assertSame(Validator::canonicalJson($fixture['target']),Validator::canonicalJson($report['provenance'][0]['target']));
        self::assertSame(0,$report['provenance'][0]['target']['provider_mapping_id']);self::assertSame('',$report['provenance'][0]['target']['variant_ref']);
        self::assertSame('NOT_APPLICABLE',$report['provenance'][0]['variant_attribution']);
        self::assertSame($result['source']['declaration_hash'],$report['provenance'][0]['source_hash']);
        self::assertSame((int)$result['row']['id'],$report['provenance'][0]['source_ledger_id']);
        self::assertSame($result['reference'],$report['provenance'][0]['review']);
        self::assertSame($result['reviewed']['allocation_packet']['lines'][0]['reference_hash'],$report['provenance'][0]['target_reference_hash']);
        self::assertNoAuthority($result['reviewed']);self::assertNoAuthority($result['reviewed']['allocation_packet']);self::assertNoAuthority($report);
    }

    /** @dataProvider invalidationCases */
    public function testDigitalBindingRevocationOrRestoredIdentityHistoryInvalidatesReviewedAllocationAndProfitability(string $change): void
    {
        $fixture=$this->fixture();$result=$this->reviewed($fixture);$reader=new AllocationProfitabilityReadModel();
        self::assertIsArray($reader->snapshot([$result['reference']],self::reportScope($fixture)));
        $input=$fixture['input'];
        if ($change==='revocation') {
            $binding=$fixture['binding'];
            self::assertIsArray((new DigitalSourceBinding())->decide($input['business_id'],$input['store_id'],'test',$binding['revision'],$binding['proposal_hash'],'REVOKED','digital-binding-revoke-'.wp_generate_uuid4()));
        } else {
            $repository=new DigiForge\Integrations\Repository();$integration=$repository->find($input['integration_id']);self::assertIsArray($integration);
            $changed=$integration['config'];$changed['_connection_test']['details']['user_id']=999;
            self::assertNotWPError($repository->update($input['integration_id'],['config'=>$changed]));
            self::assertNotWPError($repository->update($input['integration_id'],['config'=>$integration['config']]));
        }
        self::assertWPError((new DigitalSourceBinding())->current($input['business_id'],$input['store_id'],'test'));
        $service=new AllocationReview();self::assertWPError($service->current($result['source']['declaration_hash'],$fixture['scope']));
        $read=$service->read($result['source']['declaration_hash'],$fixture['scope']);self::assertIsArray($read);
        self::assertSame('STALE',$read['local_review_status']);self::assertNull($read['allocation_packet']);
        self::assertSame($result['reviewed']['review'],$read['review']);self::assertNoAuthority($read);
        $blocked=$reader->snapshot([$result['reference']],self::reportScope($fixture));self::assertWPError($blocked);
        $error=$blocked->get_error_data();self::assertIsArray($error);self::assertNull($error['totals']);self::assertNoAuthority($error);
    }

    public static function invalidationCases(): array { return [['revocation'],['restored_identity_history']]; }
}
