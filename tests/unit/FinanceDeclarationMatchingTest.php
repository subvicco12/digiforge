<?php
declare(strict_types=1);
use DigiForge\Finance\DeclarationContract;
use DigiForge\Finance\DeclarationMatcher;
use DigiForge\Tests\FinanceDeclarationFixtures;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/FinanceDeclarationFixtures.php';
require_once dirname(__DIR__,2).'/includes/Finance/ExactDecimal.php';
require_once dirname(__DIR__,2).'/includes/Finance/DeclarationContract.php';
require_once dirname(__DIR__,2).'/includes/Finance/DeclarationMatcher.php';

final class FinanceDeclarationMatchingTest extends TestCase
{
    private function entry(array $changes=[],int $id=1):array { return ['ledger_id'=>$id,'declaration'=>DeclarationContract::normalize(FinanceDeclarationFixtures::input($changes))]; }
    public function testMatchingUsesOnlyExplicitIdentityAndNeverAuthenticatesActualSources():void
    {
        $first=$this->entry();$next=$this->entry(['revision'=>2,'previous_revision_hash'=>$first['declaration']['declaration_hash'],'amount'=>'12.0000','base_amount'=>'12.0000'],2);
        $result=DeclarationMatcher::match([$next,$first],'2026-10-31');
        self::assertSame('DECLARED_MATCH',$result['state']);self::assertSame([$next],$result['active']);
        self::assertSame('UNKNOWN',$result['authenticity']);self::assertFalse($result['period_approval_authorized']);
        self::assertContains('SUPERSEDED',$result['rows'][1]['statuses']);
    }
    public function testMissingPredecessorsAndConflictingSameRevisionsBlockMatching():void
    {
        $first=$this->entry();$next=$this->entry(['revision'=>2,'previous_revision_hash'=>$first['declaration']['declaration_hash']],2);
        self::assertSame('MISSING',DeclarationMatcher::match([$next],'2026-10-31')['state']);
        $conflict=$this->entry(['amount'=>'11.0000','base_amount'=>'11.0000'],3);
        self::assertSame('CONFLICT',DeclarationMatcher::match([$first,$conflict],'2026-10-31')['state']);
    }
    public function testExactReversalsLateAndDuplicatesRemainExplicitNonFinalClassifications():void
    {
        $first=$this->entry();$reversal=$this->entry(['external_transaction_id'=>'reverse-7','reversal_of_hash'=>$first['declaration']['declaration_hash'],'amount'=>'-10.0000','base_amount'=>'-10.0000','observed_at'=>'2026-11-01T00:00:00Z'],2);
        $result=DeclarationMatcher::match([$first,$reversal,$first],'2026-10-31');
        self::assertSame('REVERSED',$result['state']);self::assertCount(2,$result['active']);
        self::assertContains('LATE',$result['rows'][1]['statuses']);
        self::assertContains('DUPLICATE',$result['rows'][2]['statuses']);
        self::assertFalse($result['actual_settlement_verified']);
        $partial=$this->entry(['external_transaction_id'=>'partial','reversal_of_hash'=>$first['declaration']['declaration_hash'],'amount'=>'-5.0000','base_amount'=>'-5.0000'],3);
        self::assertSame('CONFLICT',DeclarationMatcher::match([$first,$partial],'2026-10-31')['state']);
    }
    public function testReversalCannotCrossScopesOrReverseTheSameEvidenceTwice():void
    {
        $first=$this->entry();$reverse=$this->entry(['external_transaction_id'=>'reverse-7','reversal_of_hash'=>$first['declaration']['declaration_hash'],'amount'=>'-10.0000','base_amount'=>'-10.0000'],2);
        $other=$this->entry(['external_transaction_id'=>'reverse-other','reversal_of_hash'=>$first['declaration']['declaration_hash'],'amount'=>'-10.0000','base_amount'=>'-10.0000'],3);
        self::assertSame('CONFLICT',DeclarationMatcher::match([$first,$reverse,$other],'2026-10-31')['state']);
        $foreign=$this->entry(['external_transaction_id'=>'foreign','scope'=>['shop_key'=>'jewelry'],'reversal_of_hash'=>$first['declaration']['declaration_hash'],'amount'=>'-10.0000','base_amount'=>'-10.0000'],4);
        self::assertSame('CONFLICT',DeclarationMatcher::match([$first,$foreign],'2026-10-31')['state']);
    }
    public function testScopeKeySerializationOrderCannotChangeAnExactReversal():void
    {
        $first=$this->entry();$reverse=$this->entry(['external_transaction_id'=>'reverse-7','reversal_of_hash'=>$first['declaration']['declaration_hash'],'amount'=>'-10.0000','base_amount'=>'-10.0000'],2);
        $reverse['declaration']['scope']=array_reverse($reverse['declaration']['scope'],true);
        self::assertTrue(DeclarationMatcher::isReversal($reverse['declaration'],$first['declaration']));
        $reverse['declaration']['scope']['store_id']='2';
        self::assertFalse(DeclarationMatcher::isReversal($reverse['declaration'],$first['declaration']));
    }
}
