<?php
declare(strict_types=1);
require_once __DIR__.'/Support/ReviewedLocalFixture.php';
use DigiForge\Security\ReviewedLocalEvidence as Evidence;

/** Synthetic local consistency review only; never financial-period authority. */
final class FinanceReviewedEvidenceAuthorityTest extends WP_UnitTestCase
{
    private array $actors;
    protected function setUp():void
    {
        parent::setUp();DigiForge\Core\Activator::activate();DigiForge\Core\Settings::protectProduction();
        $this->actors=ReviewedLocalFixture::actors();
        foreach(['author','reviewer']as$key)ShopAccessFixture::authorizeActor($this->actors[$key],['digital'],['manage_digiforge','manage_digiforge_finance','manage_digiforge_settings']);
        wp_set_current_user($this->actors['author']);
    }
    private function propose():array
    {
        $p=Evidence::propose('finance_allocation','digital','synthetic-allocation',['schema'=>'synthetic-local-only'],time()-1,time()+600,wp_generate_uuid4(),0,static fn()=>true);
        self::assertNotWPError($p);return $p;
    }
    public function testFinanceReviewRequiresIndependentScopedActorsAndPreservesNoExecutionAuthority():void
    {
        $p=$this->propose();self::assertWPError(Evidence::decide('finance_allocation','digital','synthetic-allocation',$p['revision'],$p['proposal_hash'],'APPROVED',wp_generate_uuid4(),static fn()=>true));
        wp_set_current_user($this->actors['reviewer']);$a=Evidence::decide('finance_allocation','digital','synthetic-allocation',$p['revision'],$p['proposal_hash'],'APPROVED',wp_generate_uuid4(),static fn()=>true);
        self::assertNotWPError($a);self::assertFalse($a['external_execution_authorized']);self::assertNotWPError(Evidence::approved('finance_allocation','digital','synthetic-allocation'));
        wp_get_current_user()->add_cap('manage_digiforge_finance',false);self::assertWPError(Evidence::read('finance_allocation','digital','synthetic-allocation'));
        wp_set_current_user($this->actors['author']);self::assertWPError(Evidence::approved('finance_allocation','digital','synthetic-allocation'));
    }
    public function testReviewedFinanceStateRequiresAuthorsAndReviewersCurrentBaseAuthority():void
    {
        $p=$this->propose();wp_set_current_user($this->actors['reviewer']);
        self::assertNotWPError(Evidence::decide('finance_allocation','digital','synthetic-allocation',$p['revision'],$p['proposal_hash'],'APPROVED',wp_generate_uuid4(),static fn()=>true));
        $author=get_userdata($this->actors['author']);$author->add_cap('manage_digiforge',false);
        self::assertWPError(Evidence::approved('finance_allocation','digital','synthetic-allocation'));
        $author->add_cap('manage_digiforge',true);self::assertNotWPError(Evidence::approved('finance_allocation','digital','synthetic-allocation'));
        wp_get_current_user()->add_cap('manage_digiforge',false);wp_set_current_user($this->actors['author']);
        self::assertWPError(Evidence::approved('finance_allocation','digital','synthetic-allocation'));
    }
    public function testOrdinaryPolicyReaderCannotReadOrReviewFinancePayload():void
    {
        $p=$this->propose();$other=self::factory()->user->create(['role'=>'administrator']);ShopAccessFixture::authorizeActor($other,['digital'],['manage_digiforge','manage_digiforge_settings']);wp_set_current_user($other);
        self::assertWPError(Evidence::read('finance_allocation','digital','synthetic-allocation'));self::assertWPError(Evidence::decide('finance_allocation','digital','synthetic-allocation',$p['revision'],$p['proposal_hash'],'APPROVED',wp_generate_uuid4(),static fn()=>true));
    }
    public function testAdditionalConditionCanOnlyDenyAndFailureRollsBackReviewState():void
    {
        self::assertWPError(Evidence::propose('finance_allocation','digital','missing-condition',['schema'=>'synthetic'],time()-1,time()+600,wp_generate_uuid4(),0));
        $p=$this->propose();wp_set_current_user($this->actors['reviewer']);
        foreach([static fn()=>false,static fn()=>new WP_Error('synthetic_source_drift','Synthetic context changed.')]as$condition){
            self::assertWPError(Evidence::decide('finance_allocation','digital','synthetic-allocation',$p['revision'],$p['proposal_hash'],'APPROVED',wp_generate_uuid4(),$condition));
            $current=Evidence::read('finance_allocation','digital','synthetic-allocation');self::assertNotWPError($current);self::assertSame('PENDING',$current['status']);self::assertSame($p['revision'],$current['revision']);
        }
    }
}
