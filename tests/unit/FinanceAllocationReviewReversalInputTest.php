<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use DigiForge\Portal\FinanceAllocationReviewInput;
use DigiForge\Portal\FinanceAllocationReviewProjection;

/** Synthetic transport pins only; callers cannot supply retained original packets. */
final class FinanceAllocationReviewReversalInputTest extends TestCase
{
    private function post(string $operation='propose'):array
    {
        $post=['action'=>'digiforge_finance_allocation_review','_wpnonce'=>'synthetic-nonce','operation'=>$operation,'shop_key'=>'digital','environment'=>'test','business_id'=>'1','store_id'=>'2','source_hash'=>str_repeat('a',64)];
        if($operation==='propose')$post+=['lines_json'=>'[{"target":{},"amount":"-10","base_amount":"-10"}]','effective_at'=>'100','expires_at'=>'200','request_id'=>'reversal_portal_request_1','expected_revision'=>'0'];
        elseif($operation!=='view')$post+=['proposal_hash'=>str_repeat('b',64),'expected_revision'=>'1','request_id'=>'reversal_portal_request_2'];
        return $post;
    }
    private function reference():array
    {
        return ['source_hash'=>str_repeat('b',64),'revision'=>2,'state_hash'=>str_repeat('c',64),'proposal_hash'=>str_repeat('d',64)];
    }
    protected function setUp():void
    {
        require_once dirname(__DIR__,2).'/includes/Portal/FinanceAllocationReviewInput.php';
        require_once dirname(__DIR__,2).'/includes/Portal/FinanceAllocationReviewProjection.php';
    }
    public function testAbsentAndBlankOptionalReferencePreserveExactOrdinaryProposalShape():void
    {
        $ordinary=FinanceAllocationReviewInput::normalize($this->post(),'digital');
        self::assertArrayNotHasKey('original_review_reference',$ordinary);
        foreach(['',"  \n  "] as $blank)self::assertSame($ordinary,FinanceAllocationReviewInput::normalize($this->post()+['original_review_reference_json'=>$blank],'digital'));
    }
    public function testExactTypedReferenceIsPassedWithoutOriginalPacketOrInferredMoney():void
    {
        $post=$this->post()+['original_review_reference_json'=>json_encode($this->reference(),JSON_THROW_ON_ERROR)];
        $request=FinanceAllocationReviewInput::normalize($post,'digital');
        self::assertSame($this->reference(),$request['original_review_reference']);
        self::assertSame('-10',$request['lines'][0]['amount']);
        self::assertArrayNotHasKey('original_packet',$request);
    }
    public function testMalformedUntypedForeignAndUnboundedReferenceFieldsReject():void
    {
        $ref=$this->reference();
        foreach(['null','[]','{}','[','"private"',str_repeat(' ',1025),json_encode([$ref]),json_encode($ref+['original_packet'=>[]]),json_encode(array_replace($ref,['revision'=>'2'])),json_encode(array_replace($ref,['revision'=>2.0]),JSON_PRESERVE_ZERO_FRACTION),json_encode(array_replace($ref,['revision'=>0])),json_encode(array_replace($ref,['source_hash'=>str_repeat('B',64)])),json_encode(array_replace($ref,['state_hash'=>[]]))] as $json)$this->reject($this->post()+['original_review_reference_json'=>$json]);
        $this->reject($this->post()+['original_review_reference_json'=>[]]);
        $this->reject($this->post()+['original_review_reference_json'=>json_encode($ref),'original_packet'=>[]]);
        foreach(['view','localreview','reject','revoke'] as $operation)$this->reject($this->post($operation)+['original_review_reference_json'=>json_encode($ref)]);
    }
    private function recovery():array
    {
        return ['scope'=>['shop_key'=>'digital','business_id'=>1,'store_id'=>2,'environment'=>'test'],'source_hash'=>str_repeat('a',64),'local_review_status'=>'STALE','authenticity'=>'UNKNOWN','source_completeness'=>'UNKNOWN','actual_settlement_verified'=>false,'period_approval_authorized'=>false,'external_execution_authorized'=>false,'money_movement_authorized'=>false,'tax_filing_authorized'=>false,'review'=>['revision'=>2,'version'=>1,'author_id'=>1,'reviewer_id'=>2,'effective_at'=>100,'expires_at'=>200,'proposal_hash'=>str_repeat('e',64),'state_hash'=>str_repeat('f',64)],'allocation_packet'=>null,'original_review_reference'=>$this->reference(),'private'=>'NEVER_ECHO_PACKET'];
    }
    public function testRecoveryProjectionKeepsOnlyFixedTypedOriginalLinkAndNoAmountsOrPacket():void
    {
        $result=$this->recovery();$projection=FinanceAllocationReviewProjection::fromResult($result,$result['scope'],$result['source_hash']);
        self::assertSame($this->reference(),$projection['original_review_reference']);self::assertNull($projection['allocation']);self::assertArrayNotHasKey('allocation_packet',$projection);self::assertArrayNotHasKey('private',$projection);self::assertFalse($projection['period_approval_authorized']);
        unset($result['original_review_reference']);self::assertArrayNotHasKey('original_review_reference',FinanceAllocationReviewProjection::fromResult($result,$result['scope'],$result['source_hash']));
    }
    public function testMalformedOriginalLinkHistoricalStatusAndAuthorityCannotBecomeOrdinaryReviewProjection():void
    {
        foreach([['original_review_reference'=>null],['original_review_reference'=>$this->reference()+['original_packet'=>[]]],['original_review_reference'=>array_replace($this->reference(),['revision'=>'2'])],['local_review_status'=>'HISTORICAL_REVIEWED_LOCAL_ALLOCATION'],['period_approval_authorized'=>true]] as $change){
            $result=array_replace($this->recovery(),$change);
            try{FinanceAllocationReviewProjection::fromResult($result,$result['scope'],$result['source_hash']);self::fail('A historical linkage cannot confer ordinary current review or authority.');}
            catch(InvalidArgumentException){self::assertTrue(true);}
        }
    }
    private function reject(array $post):void
    {
        try{FinanceAllocationReviewInput::normalize($post,'digital');self::fail('Only an exact bounded typed original review pin is accepted.');}
        catch(InvalidArgumentException){self::assertTrue(true);}
    }
}
