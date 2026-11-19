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
if (is_file(dirname(__DIR__,2).'/includes/Finance/AllocationReviewPayload.php')) require_once dirname(__DIR__,2).'/includes/Finance/AllocationReviewPayload.php';
final class FinanceAllocationReviewPayloadTest extends TestCase
{
    private static function fixture(): array
    {
        $source=DeclarationContract::normalize(FinanceDeclarationFixtures::input());
        $scope=['shop_key'=>'digital','business_id'=>1,'store_id'=>2,'environment'=>'test'];
        $target=['shop_key'=>'digital','business_id'=>1,'store_id'=>2,'order_id'=>7,'order_line_item_id'=>1,'product_id'=>1,'product_version_id'=>3,'listing_id'=>4,'provider_mapping_id'=>0,'variant_ref'=>'','environment'=>'test','currency'=>'USD'];
        $lines=[['target'=>$target,'amount'=>'10','base_amount'=>'10']];
        $references=new class implements AllocationTargetReferences { public function resolve(array $source,array $target): array { return ['target'=>$target,'reference_hash'=>str_repeat('b',64)]; } };
        $packet=AllocationProposal::build([['ledger_id'=>1,'declaration'=>$source]],$source['declaration_hash'],$lines,$references);
        return [$source['declaration_hash'],$scope,$packet];
    }
    public function testExactVersionedEnvelopePreservesFullPacketAndCanonicalScope(): void
    {
        self::assertTrue(class_exists(AllocationReviewPayload::class),'Missing bounded typed allocation review payload contract.');
        [$hash,$scope,$packet]=self::fixture();$payload=AllocationReviewPayload::create($hash,$scope,$packet);
        self::assertSame('digiforge-finance-allocation-review-v1',$payload['schema']);
        self::assertSame($packet,AllocationReviewPayload::packet($payload,$hash,$scope));
        self::assertSame($payload['scope'],AllocationReviewPayload::scope($hash,array_reverse($scope,true)));
    }
    /** @dataProvider invalidCases */
    public function testInvalidOrForeignEnvelopeFailsClosed(string $case): void
    {
        self::assertTrue(class_exists(AllocationReviewPayload::class),'Missing bounded typed allocation review payload contract.');
        [$hash,$scope,$packet]=self::fixture();$payload=AllocationReviewPayload::create($hash,$scope,$packet);
        if ($case==='schema') $payload['schema']='future';
        elseif ($case==='scope') $payload['scope']['business_id']=9;
        elseif ($case==='extra') $payload['authority']=true;
        elseif ($case==='source') $payload['source_hash']=str_repeat('c',64);
        elseif ($case==='reversal') $payload['packet']['reversal_of_proposal_hash']=str_repeat('a',64);
        elseif ($case==='lines') $payload['packet']['lines']=array_fill(0,101,$packet['lines'][0]);
        elseif ($case==='large') $payload['packet']['source']['source']['document_ref']=str_repeat('a',4097);
        $this->expectException(InvalidArgumentException::class);AllocationReviewPayload::packet($payload,$hash,$scope);
    }
    public static function invalidCases(): array { return [['schema'],['scope'],['extra'],['source'],['reversal'],['lines'],['large']]; }
}
