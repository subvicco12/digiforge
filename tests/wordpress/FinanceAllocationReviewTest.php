<?php
declare(strict_types=1);
use DigiForge\Database\Tables;
use DigiForge\Finance\AllocationReview;
use DigiForge\Finance\DeclarationContract;
use DigiForge\Tests\FinanceDeclarationFixtures;
require_once dirname(__DIR__).'/unit/FinanceDeclarationFixtures.php';
require_once __DIR__.'/Support/ReviewedLocalFixture.php';

/** Synthetic offline evidence: no provider or financial approval. */
final class FinanceAllocationReviewTest extends WP_UnitTestCase
{
    private array $actors;
    private ?Closure $ledgerFilter=null;
    private string $temporaryLedger='';
    protected function setUp(): void
    {
        parent::setUp(); DigiForge\Core\Activator::activate();
        $this->actors=ReviewedLocalFixture::actors('personalized_pod');
        foreach (['author','reviewer'] as $actor) ShopAccessFixture::authorizeActor($this->actors[$actor],['personalized_pod'],['manage_digiforge_finance']);
        wp_set_current_user($this->actors['author']);
        global $wpdb; $table=Tables::finance_ledger();
        $this->temporaryLedger=$table.'_review_'.substr(str_replace('-','',wp_generate_uuid4()),0,8);
        self::assertNotFalse($wpdb->query('CREATE TEMPORARY TABLE '.$this->temporaryLedger.' LIKE '.$table));
        $temporary=$this->temporaryLedger; $this->ledgerFilter=static fn(string $sql): string => str_replace($table,$temporary,$sql);
        add_filter('query',$this->ledgerFilter,99);
    }
    protected function tearDown(): void
    {
        if ($this->ledgerFilter !== null) remove_filter('query',$this->ledgerFilter,99);
        global $wpdb; if ($this->temporaryLedger !== '') $wpdb->query('DROP TEMPORARY TABLE IF EXISTS '.$this->temporaryLedger);
        parent::tearDown();
    }
    private function fixture(): array
    {
        global $wpdb;$now=current_time('mysql',true);
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

    private function stored(): array
    {
        $f=$this->fixture();$input=$f['source'];unset($input['declaration_hash'],$input['accounting_convention'],$input['authenticity']);
        self::assertIsArray((new DigiForge\Finance\Repository())->importDeclaration($input));
        $f['input']=$input;
        $f['scope']=array_intersect_key($f['target'],array_flip(['shop_key','business_id','store_id','environment']));
        return $f;
    }
    private static function lines(array $f): array { return [['target'=>$f['target'],'amount'=>'10','base_amount'=>'10']]; }
    private function propose(array $f,int $revision=0,string $request='allocation-propose-request-1'): array
    {
        $result=(new AllocationReview())->propose($f['source']['declaration_hash'],$f['scope'],self::lines($f),time()-1,time()+600,$request,$revision);
        self::assertNotWPError($result);self::assertIsArray($result);return $result;
    }
    private function decide(array $f,array $state,string $decision='APPROVED',string $request='allocation-decide-request-1'): array|WP_Error
    {
        return (new AllocationReview())->decide($f['source']['declaration_hash'],$f['scope'],$state['review']['revision'],$state['review']['proposal_hash'],$decision,$request);
    }
    public function testSeparateReviewPersistsExactPacketWithoutFinancialAuthority(): void
    {
        $f=$this->stored();$p=$this->propose($f);
        self::assertSame('PENDING',$p['local_review_status']); self::assertWPError($this->decide($f,$p));
        wp_set_current_user($this->actors['reviewer']);$a=$this->decide($f,$p);self::assertNotWPError($a);
        self::assertSame('REVIEWED_LOCAL_ALLOCATION',$a['local_review_status']);
        $current=(new AllocationReview())->current($f['source']['declaration_hash'],$f['scope']); self::assertSame($a,$current);
        self::assertSame($p['allocation_packet'],$a['allocation_packet']);self::assertSame('UNKNOWN',$a['authenticity']);self::assertSame('UNKNOWN',$a['source_completeness']);
        foreach (['period_approval_authorized','external_execution_authorized','money_movement_authorized','tax_filing_authorized','actual_settlement_verified'] as $field) self::assertFalse($a[$field]);
    }
    public function testTargetDriftDeniesDecisionAndCurrentRead(): void
    {
        $f=$this->stored();$p=$this->propose($f);global $wpdb;
        self::assertSame(1,$wpdb->update(Tables::order_line_items(),['unit_price_amount'=>'1.0000'],['id'=>$f['target']['order_line_item_id']]));
        wp_set_current_user($this->actors['reviewer']);self::assertWPError($this->decide($f,$p));
        $read=(new AllocationReview())->read($f['source']['declaration_hash'],$f['scope']);self::assertIsArray($read);self::assertNull($read['allocation_packet']);self::assertSame('STALE',$read['local_review_status']);
    }
    public function testSourceRevisionInvalidatesReviewedAllocation(): void
    {
        $f=$this->stored();$p=$this->propose($f);wp_set_current_user($this->actors['reviewer']);self::assertNotWPError($this->decide($f,$p));
        $next=array_replace($f['input'],['revision'=>2,'previous_revision_hash'=>$f['source']['declaration_hash'],'amount'=>'11','base_amount'=>'11']);
        self::assertIsArray((new DigiForge\Finance\Repository())->importDeclaration($next));
        self::assertWPError((new AllocationReview())->current($f['source']['declaration_hash'],$f['scope']));
    }
    public function testRevocationAndNewVersionInvalidateCurrentReviewAndDenyReplay(): void
    {
        $f=$this->stored();$p=$this->propose($f);wp_set_current_user($this->actors['reviewer']);$a=$this->decide($f,$p);self::assertIsArray($a);
        self::assertWPError($this->decide($f,$p));
        $r=$this->decide($f,$a,'REVOKED','allocation-revoke-request-1');self::assertIsArray($r);self::assertSame('REVOKED',$r['local_review_status']);
        self::assertWPError((new AllocationReview())->current($f['source']['declaration_hash'],$f['scope']));
        wp_set_current_user($this->actors['author']);$next=$this->propose($f,$r['review']['revision'],'allocation-propose-request-2');
        self::assertSame(2,$next['review']['version']);self::assertSame(4,$next['review']['revision']);
        self::assertWPError((new AllocationReview())->current($f['source']['declaration_hash'],$f['scope']));
    }
    public function testDeletedAuditAndRevokedActorNeverProvideCurrentReview(): void
    {
        $f=$this->stored();$p=$this->propose($f);wp_set_current_user($this->actors['reviewer']);self::assertNotWPError($this->decide($f,$p));
        global $wpdb;$id=$wpdb->get_var("SELECT id FROM ".Tables::audit_log()." WHERE object_type='reviewed_local_evidence' ORDER BY id DESC LIMIT 1");
        self::assertSame(1,$wpdb->delete(Tables::audit_log(),['id'=>$id]));
        self::assertWPError((new AllocationReview())->current($f['source']['declaration_hash'],$f['scope']));
    }
    public function testMissingOrForeignFinanceAssignmentDeniesAllEntryPoints(): void
    {
        $f=$this->stored();$p=$this->propose($f);ShopAccessFixture::reset();
        $service=new AllocationReview();self::assertWPError($service->read($f['source']['declaration_hash'],$f['scope']));
        self::assertWPError($service->current($f['source']['declaration_hash'],$f['scope']));
        self::assertWPError($this->decide($f,$p));
        self::assertWPError($service->propose($f['source']['declaration_hash'],$f['scope'],self::lines($f),time(),time()+600,'allocation-denied-request-1',1));
    }
    public function testPersistentReversalIsExplicitlyUnsupported(): void
    {
        $f=$this->stored();$input=array_replace($f['input'],['external_transaction_id'=>'persistent-reversal','reversal_of_hash'=>$f['source']['declaration_hash'],'amount'=>'-10','base_amount'=>'-10']);
        self::assertIsArray((new DigiForge\Finance\Repository())->importDeclaration($input));$reverse=DeclarationContract::normalize($input);
        $lines=self::lines($f);$lines[0]['amount']='-10';$lines[0]['base_amount']='-10';
        self::assertWPError((new AllocationReview())->propose($reverse['declaration_hash'],$f['scope'],$lines,time(),time()+600,'allocation-reversal-request',0));
    }
    public function testRejectedVersionCanBeExplicitlyRevisedButNotMadeCurrent(): void
    {
        $f=$this->stored();$p=$this->propose($f);wp_set_current_user($this->actors['reviewer']);
        $r=$this->decide($f,$p,'REJECTED','allocation-reject-request-1');self::assertIsArray($r);
        self::assertSame('REJECTED',$r['local_review_status']);
        self::assertWPError((new AllocationReview())->current($f['source']['declaration_hash'],$f['scope']));
        wp_set_current_user($this->actors['author']);$next=$this->propose($f,$r['review']['revision'],'allocation-revise-request-1');
        self::assertSame(2,$next['review']['version']);self::assertSame('PENDING',$next['local_review_status']);
    }
    public function testCurrentReviewRequiresBothActorsFinanceAfterStoredApproval(): void
    {
        $f=$this->stored();$p=$this->propose($f);wp_set_current_user($this->actors['reviewer']);self::assertNotWPError($this->decide($f,$p));
        ShopAccessFixture::provision([]);
        self::assertWPError((new AllocationReview())->current($f['source']['declaration_hash'],$f['scope']));
    }
    public function testFutureEffectiveReviewIsVisibleButCannotBecomeCurrent(): void
    {
        $f=$this->stored();$service=new AllocationReview();
        $p=$service->propose($f['source']['declaration_hash'],$f['scope'],self::lines($f),time()+60,time()+600,'allocation-future-request-1',0);self::assertIsArray($p);
        wp_set_current_user($this->actors['reviewer']);$a=$this->decide($f,$p);self::assertIsArray($a);
        self::assertNotSame('REVIEWED_LOCAL_ALLOCATION',$a['local_review_status']);self::assertNull($a['allocation_packet']);
        self::assertWPError($service->current($f['source']['declaration_hash'],$f['scope']));
    }

    public function testTransactionalConditionRejectsTargetDriftAfterOuterPreflight(): void
    {
        $f=$this->stored();$p=$this->propose($f);wp_set_current_user($this->actors['reviewer']);global $wpdb;$mutated=false;
        $filter=static function(string $sql) use ($f,$wpdb,&$mutated): string {
            if (!$mutated && str_contains($sql,'FOR UPDATE') && str_contains($sql,'digiforge_shop_access_state_')) {
                $mutated=true;
                self::assertSame(1,$wpdb->update(Tables::order_line_items(),['unit_price_amount'=>'2.0000'],['id'=>$f['target']['order_line_item_id']]));
            }
            return $sql;
        };
        add_filter('query',$filter);
        try {self::assertWPError($this->decide($f,$p));} finally {remove_filter('query',$filter);}
        self::assertTrue($mutated);
        $read=(new AllocationReview())->read($f['source']['declaration_hash'],$f['scope']);self::assertIsArray($read);
        self::assertSame($p['review']['revision'],$read['review']['revision']);self::assertNotSame('REVIEWED_LOCAL_ALLOCATION',$read['local_review_status']);
    }

    public function testPendingPacketCannotSurviveProposalAuthorFinanceRevocation(): void
    {
        $f=$this->stored();$p=$this->propose($f);$author=get_userdata($this->actors['author']);self::assertInstanceOf(WP_User::class,$author);
        $author->add_cap('manage_digiforge_finance',false);wp_set_current_user($this->actors['reviewer']);
        $read=(new AllocationReview())->read($f['source']['declaration_hash'],$f['scope']);self::assertIsArray($read);
        self::assertSame('STALE',$read['local_review_status']);self::assertNull($read['allocation_packet']);self::assertWPError($this->decide($f,$p));
    }

}
