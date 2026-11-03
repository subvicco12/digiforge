<?php
declare(strict_types=1);
use DigiForge\Database\Tables;
use DigiForge\Finance\AllocationReview;
use DigiForge\Finance\AllocationProfitabilityReadModel;
use DigiForge\Finance\DeclarationContract;
use DigiForge\Finance\Validator;
require_once __DIR__.'/FinanceAllocationReviewTest.php';

/** Synthetic independently reviewed inverse entries; no external or financial authority. */
final class FinanceAllocationReviewReversalTest extends WP_UnitTestCase
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
        $this->temporaryLedger=$table.'_reversal_'.substr(str_replace('-','',wp_generate_uuid4()),0,8);
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
        $method=new ReflectionMethod(FinanceAllocationReviewTest::class,'stored');$method->setAccessible(true);
        return $method->invoke(new FinanceAllocationReviewTest('testSeparateReviewPersistsExactPacketWithoutFinancialAuthority'));
    }
    private static function reference(array $review): array
    {
        return ['source_hash'=>$review['source_hash'],'revision'=>$review['review']['revision'],'state_hash'=>$review['review']['state_hash'],'proposal_hash'=>$review['review']['proposal_hash']];
    }
    private static function originalApprovalAudit(array $f): array
    {
        global $wpdb;
        $key=(new ReflectionMethod(AllocationReview::class,'key'))->invoke(null,$f['source']['declaration_hash'],$f['scope']);
        $stream=(new ReflectionMethod(DigiForge\Security\ReviewedLocalEvidence::class,'identity'))->invoke(null,'finance_allocation',$f['scope']['shop_key'],$key);
        $row=$wpdb->get_row($wpdb->prepare('SELECT id,context,object_id FROM '.Tables::audit_log().' WHERE object_type=%s AND object_id=%s AND event_type=%s','reviewed_local_evidence',$stream,'local_review_approved'),ARRAY_A);
        self::assertIsArray($row);self::assertSame($stream,$row['object_id']);
        $event=json_decode($row['context'],true);self::assertIsArray($event);
        self::assertSame('finance_allocation',$event['kind']);
        self::assertSame($f['source']['declaration_hash'],$event['state']['payload']['source_hash']);
        self::assertSame('APPROVED',$event['state']['status']);return $row;
    }
    private function approvedOriginal(): array
    {
        $f=$this->fixture();$service=new AllocationReview();
        $p=$service->propose($f['source']['declaration_hash'],$f['scope'],[['target'=>$f['target'],'amount'=>'10','base_amount'=>'10']],time()-1,time()+600,'original-proposal-request',0);self::assertIsArray($p);
        wp_set_current_user($this->actors['reviewer']);
        $a=$service->decide($f['source']['declaration_hash'],$f['scope'],$p['review']['revision'],$p['review']['proposal_hash'],'APPROVED','original-approve-request');self::assertIsArray($a);
        $f['original']=$a;$f['reference']=self::reference($a);return $f;
    }
    private function addReversal(array $f,bool $later=false): array
    {
        $input=array_replace($f['input'],['external_transaction_id'=>'reviewed-reversal','amount'=>'-10','base_amount'=>'-10','reversal_of_hash'=>$f['source']['declaration_hash']]);
        if ($later) { $input['occurred_at']='2026-11-01T10:00:00Z';$input['observed_at']='2026-11-01T10:01:00Z'; }
        self::assertIsArray((new DigiForge\Finance\Repository())->importDeclaration($input));
        $f['reverse']=DeclarationContract::normalize($input);$f['negative']=[['target'=>$f['target'],'amount'=>'-10','base_amount'=>'-10']];return $f;
    }
    private function proposeReverse(array $f,?array $reference=null): array|WP_Error
    {
        wp_set_current_user($this->actors['author']);
        return (new AllocationReview())->propose($f['reverse']['declaration_hash'],$f['scope'],$f['negative'],time()-1,time()+600,'reversal-proposal-request',0,$reference??$f['reference']);
    }
    private function approveReverse(array $f): array
    {
        $p=$this->proposeReverse($f);self::assertIsArray($p);wp_set_current_user($this->actors['reviewer']);
        $a=(new AllocationReview())->decide($f['reverse']['declaration_hash'],$f['scope'],$p['review']['revision'],$p['review']['proposal_hash'],'APPROVED','reversal-approve-request');self::assertIsArray($a);return $a;
    }
    private static function profitScope(array $f,string $start='2026-01-01',string $end='2026-12-31'): array
    {return array_merge($f['scope'],['period_start'=>$start,'period_end'=>$end,'base_currency'=>'USD']);}
    public function testPersistentInverseUsesPinnedApprovedProofAndNeverResurrectsOriginalCurrent(): void
    {
        $f=$this->addReversal($this->approvedOriginal());$a=$this->approveReverse($f);$service=new AllocationReview();
        self::assertSame('REVIEWED_LOCAL_ALLOCATION',$a['local_review_status']);
        self::assertSame(Validator::canonicalJson($f['reference']),Validator::canonicalJson($a['original_review_reference']));
        self::assertSame($f['original']['allocation_packet']['proposal_hash'],$a['allocation_packet']['reversal_of_proposal_hash']);
        self::assertSame('-10.0000',$a['allocation_packet']['allocated_base_amount']);
        self::assertWPError($service->current($f['source']['declaration_hash'],$f['scope']));
        $stale=$service->read($f['source']['declaration_hash'],$f['scope']);self::assertSame('STALE',$stale['local_review_status']);self::assertNull($stale['allocation_packet']);
        $historical=$service->reviewedOriginal($f['reverse']['declaration_hash'],$f['scope']);self::assertIsArray($historical);
        self::assertSame('HISTORICAL_REVIEWED_LOCAL_ALLOCATION',$historical['local_review_status']);
        self::assertSame($f['original']['allocation_packet'],$historical['allocation_packet']);
        self::assertFalse($a['period_approval_authorized']);self::assertFalse($a['money_movement_authorized']);
    }
    public function testProfitabilityAdmitsExactPairAndNegativePriorPeriodReversalOnly(): void
    {
        $f=$this->addReversal($this->approvedOriginal(),true);$a=$this->approveReverse($f);$model=new AllocationProfitabilityReadModel();
        $pair=$model->snapshot([$f['reference'],self::reference($a)],self::profitScope($f));self::assertIsArray($pair);
        self::assertSame(2,$pair['coverage']['source_count']);self::assertSame('0.0000',$pair['totals']['net_profit']);
        $negative=$model->snapshot([self::reference($a)],self::profitScope($f,'2026-11-01','2026-11-30'));self::assertIsArray($negative);
        self::assertSame('-10.0000',$negative['totals']['net_profit']);
        self::assertWPError($model->snapshot([$f['reference']],self::profitScope($f)));
        $forged=$f['reference'];$forged['revision']++;
        self::assertWPError($model->snapshot([$forged,self::reference($a)],self::profitScope($f)));
        self::assertWPError($model->snapshot([$f['reference'],self::reference($a)],self::profitScope($f,'2026-11-01','2026-11-30')));
    }
    public function testMissingExactPinAndNoninverseAmountsNeverCreateReview(): void
    {
        $f=$this->addReversal($this->approvedOriginal());$service=new AllocationReview();wp_set_current_user($this->actors['author']);
        self::assertWPError($service->propose($f['reverse']['declaration_hash'],$f['scope'],$f['negative'],time()-1,time()+600,'missing-reference-request',0));
        $ref=$f['reference'];$ref['state_hash']=str_repeat('f',64);self::assertWPError($this->proposeReverse($f,$ref));
        $f['negative'][0]['amount']='-9';self::assertWPError($this->proposeReverse($f));
    }
    public function testOriginalRevocationInvalidatesReversalButPermitsWithdrawal(): void
    {
        $f=$this->addReversal($this->approvedOriginal());$a=$this->approveReverse($f);$service=new AllocationReview();
        $r=$service->decide($f['source']['declaration_hash'],$f['scope'],$f['original']['review']['revision'],$f['original']['review']['proposal_hash'],'REVOKED','original-revoke-request');self::assertIsArray($r);
        self::assertWPError($service->current($f['reverse']['declaration_hash'],$f['scope']));self::assertWPError($service->reviewedOriginal($f['reverse']['declaration_hash'],$f['scope']));
        $withdraw=$service->decide($f['reverse']['declaration_hash'],$f['scope'],$a['review']['revision'],$a['review']['proposal_hash'],'REVOKED','reversal-revoke-request');self::assertIsArray($withdraw);self::assertSame('REVOKED',$withdraw['local_review_status']);
    }
    public function testDeletedOriginalAuditInvalidatesRetainedProof(): void
    {
        $f=$this->addReversal($this->approvedOriginal());$a=$this->approveReverse($f);global $wpdb;
        $audit=self::originalApprovalAudit($f);
        self::assertSame(1,$wpdb->delete(Tables::audit_log(),['id'=>$audit['id'],'object_id'=>$audit['object_id']]));
        self::assertNull($wpdb->get_var($wpdb->prepare('SELECT id FROM '.Tables::audit_log().' WHERE id=%d',$audit['id'])));
        self::assertWPError((new AllocationReview())->current($f['reverse']['declaration_hash'],$f['scope']));
        self::assertWPError((new AllocationProfitabilityReadModel())->snapshot([self::reference($a)],self::profitScope($f)));
    }
    public function testCurrentAndProfitabilityDenyAfterTargetLineageChanges(): void
    {
        $f=$this->addReversal($this->approvedOriginal());$a=$this->approveReverse($f);global $wpdb;
        self::assertSame(1,$wpdb->update(Tables::order_line_items(),['unit_price_amount'=>'3.0000'],['id'=>$f['target']['order_line_item_id']]));
        $service=new AllocationReview();self::assertWPError($service->current($f['reverse']['declaration_hash'],$f['scope']));
        self::assertWPError($service->reviewedOriginal($f['reverse']['declaration_hash'],$f['scope']));
        self::assertWPError((new AllocationProfitabilityReadModel())->snapshot([$f['reference'],self::reference($a)],self::profitScope($f)));
    }
    public function testPendingOriginalReviewNeverSuppliesOriginalPacket(): void
    {
        $f=$this->fixture();$service=new AllocationReview();
        $pending=$service->propose($f['source']['declaration_hash'],$f['scope'],[['target'=>$f['target'],'amount'=>'10','base_amount'=>'10']],time()-1,time()+600,'pending-original-proposal',0);self::assertIsArray($pending);
        $f['reference']=self::reference($pending);$f=$this->addReversal($f);
        self::assertWPError($this->proposeReverse($f));
    }
    public function testOriginalAuthorAuthorityLossInvalidatesInverseAndAllowsRejection(): void
    {
        $f=$this->addReversal($this->approvedOriginal());$p=$this->proposeReverse($f);self::assertIsArray($p);
        $author=get_userdata($this->actors['author']);self::assertInstanceOf(WP_User::class,$author);$author->add_cap('manage_digiforge_finance',false);
        wp_set_current_user($this->actors['reviewer']);$service=new AllocationReview();
        self::assertWPError($service->decide($f['reverse']['declaration_hash'],$f['scope'],$p['review']['revision'],$p['review']['proposal_hash'],'APPROVED','denied-reversal-approve'));
        $rejected=$service->decide($f['reverse']['declaration_hash'],$f['scope'],$p['review']['revision'],$p['review']['proposal_hash'],'REJECTED','safe-reversal-reject');
        self::assertIsArray($rejected);self::assertSame('REJECTED',$rejected['local_review_status']);self::assertNull($rejected['allocation_packet']);
    }

    public function testTransactionalRecheckRejectsOriginalRevocationAfterOuterPreflight(): void
    {
        $f=$this->addReversal($this->approvedOriginal());$p=$this->proposeReverse($f);self::assertIsArray($p);wp_set_current_user($this->actors['reviewer']);
        global $wpdb;$mutated=false;$audit=self::originalApprovalAudit($f);
        $filter=static function(string $sql) use ($audit,$wpdb,&$mutated): string {
            if (!$mutated && str_contains($sql,'FOR UPDATE') && str_contains($sql,'digiforge_shop_access_state_')) {
                $mutated=true;
                self::assertSame(1,$wpdb->delete(Tables::audit_log(),['id'=>$audit['id'],'object_id'=>$audit['object_id']]));
                self::assertNull($wpdb->get_var($wpdb->prepare('SELECT id FROM '.Tables::audit_log().' WHERE id=%d',$audit['id'])));
            }
            return $sql;
        };
        add_filter('query',$filter);
        try {$result=(new AllocationReview())->decide($f['reverse']['declaration_hash'],$f['scope'],$p['review']['revision'],$p['review']['proposal_hash'],'APPROVED','racing-reversal-approve');self::assertWPError($result);} finally {remove_filter('query',$filter);}
        self::assertTrue($mutated);
    }
}
