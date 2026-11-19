<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use DigiForge\Portal\FinanceAllocationReviewInput;

final class FinanceAllocationReviewPortalInputTest extends TestCase
{
    private function input(string $operation='view'):array
    {
        $post=['action'=>'digiforge_finance_allocation_review','_wpnonce'=>'synthetic-nonce','operation'=>$operation,'shop_key'=>'digital','environment'=>'test','business_id'=>'1','store_id'=>'2','source_hash'=>str_repeat('a',64)];
        if($operation==='propose')$post+=['lines_json'=>'[{"target":{},"amount":"10","base_amount":"10"}]','effective_at'=>'100','expires_at'=>'200','request_id'=>'allocation_request_1','expected_revision'=>'0'];
        elseif($operation!=='view')$post+=['proposal_hash'=>str_repeat('b',64),'expected_revision'=>'1','request_id'=>'allocation_request_2'];
        return $post;
    }
    private function loadInput():void
    {
        $path=__DIR__.'/../../includes/Portal/FinanceAllocationReviewInput.php';self::assertFileExists($path);require_once $path;
    }
    public function testExactOperationFieldsNormalizeWithoutInventingAllocationOrDecision():void
    {
        $this->loadInput();$view=FinanceAllocationReviewInput::normalize($this->input(),'digital');
        self::assertSame(['shop_key'=>'digital','business_id'=>1,'store_id'=>2,'environment'=>'test'],$view['scope']);self::assertArrayNotHasKey('decision',$view);
        $proposal=FinanceAllocationReviewInput::normalize($this->input('propose'),'digital');
        self::assertSame('10',$proposal['lines'][0]['amount']);self::assertSame(0,$proposal['expected_revision']);self::assertSame(100,$proposal['effective_at']);
        foreach(['localreview'=>'APPROVED','reject'=>'REJECTED','revoke'=>'REVOKED'] as $operation=>$decision)self::assertSame($decision,FinanceAllocationReviewInput::normalize($this->input($operation),'digital')['decision']);
    }
    public function testMalformedForeignUnexpectedOrUnboundedFieldsReject():void
    {
        $this->loadInput();
        foreach([['shop_key'=>'jewelry'],['environment'=>'Test'],['business_id'=>'01'],['store_id'=>'9223372036854775808'],['source_hash'=>'bad'],['operation'=>'approve'],['unexpected'=>'secret'],['_wpnonce'=>[]],['lines_json'=>'[]']] as $change)$this->reject(array_replace($this->input(),$change));
        foreach([['lines_json'=>'{}'],['lines_json'=>'['],['lines_json'=>str_repeat(' ',32769)],['lines_json'=>json_encode(array_fill(0,101,['target'=>[],'amount'=>'1','base_amount'=>'1']))],['expected_revision'=>'01'],['request_id'=>'short'],['effective_at'=>'0'],['expires_at'=>'100']] as $change)$this->reject(array_replace($this->input('propose'),$change));
        $proposal=$this->input('propose');unset($proposal['effective_at']);$this->reject($proposal);
        $this->reject(array_replace($this->input('localreview'),['proposal_hash'=>'BAD']));
        $this->reject(array_replace($this->input('localreview'),['expected_revision'=>'0']));
    }
    private function reject(array $post):void
    {
        try{FinanceAllocationReviewInput::normalize($post,'digital');self::fail('Malformed local allocation request must reject.');}
        catch(InvalidArgumentException){self::assertTrue(true);}
    }
}
