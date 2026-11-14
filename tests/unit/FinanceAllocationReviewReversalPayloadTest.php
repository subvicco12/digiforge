<?php
declare(strict_types=1);
use DigiForge\Finance\AllocationProposal;
use DigiForge\Finance\AllocationReviewPayload;
use DigiForge\Finance\AllocationTargetReferences;
use DigiForge\Finance\DeclarationContract;
use DigiForge\Finance\Validator;
use DigiForge\Tests\FinanceDeclarationFixtures;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/FinanceDeclarationFixtures.php';
require_once dirname(__DIR__,2).'/includes/Finance/ExactDecimal.php';
require_once dirname(__DIR__,2).'/includes/Finance/DeclarationContract.php';
require_once dirname(__DIR__,2).'/includes/Finance/DeclarationMatcher.php';
require_once dirname(__DIR__,2).'/includes/Finance/AllocationTargetReferences.php';
require_once dirname(__DIR__,2).'/includes/Finance/AllocationProposal.php';
require_once dirname(__DIR__,2).'/includes/Finance/AllocationReviewPayload.php';
final class FinanceAllocationReviewReversalPayloadTest extends TestCase
{
    private static function fixture(): array
    {
        $source=DeclarationContract::normalize(FinanceDeclarationFixtures::input());
        $scope=['shop_key'=>'digital','business_id'=>1,'store_id'=>2,'environment'=>'test'];
        $target=['shop_key'=>'digital','business_id'=>1,'store_id'=>2,'order_id'=>7,'order_line_item_id'=>1,'product_id'=>1,'product_version_id'=>3,'listing_id'=>4,'provider_mapping_id'=>0,'variant_ref'=>'','environment'=>'test','currency'=>'USD'];
        $references=new class implements AllocationTargetReferences { public function resolve(array $source,array $target): array { return ['target'=>$target,'reference_hash'=>str_repeat('b',64)]; } };
        $original=AllocationProposal::build([['ledger_id'=>1,'declaration'=>$source]],$source['declaration_hash'],[['target'=>$target,'amount'=>'10','base_amount'=>'10']],$references);
        $reversal=DeclarationContract::normalize(FinanceDeclarationFixtures::input(['external_transaction_id'=>'reviewed-reversal','amount'=>'-10','base_amount'=>'-10','reversal_of_hash'=>$source['declaration_hash']]));
        $packet=AllocationProposal::build([['ledger_id'=>1,'declaration'=>$source],['ledger_id'=>2,'declaration'=>$reversal]],$reversal['declaration_hash'],[['target'=>$target,'amount'=>'-10','base_amount'=>'-10']],$references,$original);
        $reference=['source_hash'=>$source['declaration_hash'],'revision'=>2,'state_hash'=>str_repeat('c',64),'proposal_hash'=>str_repeat('d',64)];
        return [$reversal['declaration_hash'],$scope,$packet,$reference,$original];
    }
    public function testExactReversalUsesV2AndRetainsOnlyTypedOriginalReference(): void
    {
        [$hash,$scope,$packet,$reference]=self::fixture();
        $payload=AllocationReviewPayload::create($hash,$scope,$packet,$reference);
        self::assertSame('digiforge-finance-allocation-review-v2',$payload['schema']);
        self::assertSame($reference,$payload['original_review_reference']);
        self::assertSame($packet,AllocationReviewPayload::packet($payload,$hash,$scope));
    }
    /** @dataProvider invalidReferences */
    public function testReversalWithoutExactReferenceFailsClosed(string $case): void
    {
        [$hash,$scope,$packet,$reference,$original]=self::fixture();
        if ($case==='missing') $reference=null;
        elseif ($case==='foreign') $reference['source_hash']=str_repeat('f',64);
        elseif ($case==='extra') $reference['packet']=$original;
        elseif ($case==='revision') $reference['revision']='2';
        elseif ($case==='digest') $reference['state_hash']='';
        elseif ($case==='ordinary') { $hash=$reference['source_hash'];$packet=$original; }
        $this->expectException(InvalidArgumentException::class);
        AllocationReviewPayload::create($hash,$scope,$packet,$reference);
    }
    public static function invalidReferences(): array {return [['missing'],['foreign'],['extra'],['revision'],['digest'],['ordinary']];}
    public function testReversalCannotBeDowngradedToV1OrRelabelledOrdinary(): void
    {
        [$hash,$scope,$packet,$reference]=self::fixture();$payload=AllocationReviewPayload::create($hash,$scope,$packet,$reference);
        $payload['schema']='digiforge-finance-allocation-review-v1';unset($payload['original_review_reference']);
        $this->expectException(InvalidArgumentException::class);AllocationReviewPayload::packet($payload,$hash,$scope);
    }
}
