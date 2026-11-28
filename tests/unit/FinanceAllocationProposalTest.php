<?php
declare(strict_types=1);

use DigiForge\Finance\AllocationProposal;
use DigiForge\Finance\AllocationTargetReferences;
use DigiForge\Finance\DeclarationContract;
use DigiForge\Finance\Validator;
use DigiForge\Tests\FinanceDeclarationFixtures;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FinanceDeclarationFixtures.php';
require_once dirname(__DIR__, 2) . '/includes/Finance/ExactDecimal.php';
require_once dirname(__DIR__, 2) . '/includes/Finance/DeclarationContract.php';
require_once dirname(__DIR__, 2) . '/includes/Finance/DeclarationMatcher.php';
require_once dirname(__DIR__, 2) . '/includes/Finance/AllocationTargetReferences.php';
require_once dirname(__DIR__, 2) . '/includes/Finance/AllocationProposal.php';

final class FinanceAllocationProposalTest extends TestCase
{
    private static function source(array $changes = []): array
    {
        return DeclarationContract::normalize(FinanceDeclarationFixtures::input(array_replace_recursive([
            'scope' => ['product_version_id'=>0,'listing_id'=>0],
        ], $changes)));
    }

    private static function target(int $line = 1): array
    {
        return ['shop_key'=>'digital','business_id'=>1,'store_id'=>2,'order_id'=>7,
            'order_line_item_id'=>$line,'product_id'=>10+$line,'product_version_id'=>20+$line,
            'listing_id'=>30+$line,'provider_mapping_id'=>0,'variant_ref'=>'','environment'=>'test','currency'=>'USD'];
    }

    private static function lines(string $a = '3.3333', string $b = '6.6667'): array
    {
        return [['target'=>self::target(1),'amount'=>$a,'base_amount'=>$a],
            ['target'=>self::target(2),'amount'=>$b,'base_amount'=>$b]];
    }

    private static function resolver(?array $override = null, bool $missing = false): AllocationTargetReferences
    {
        return new class($override, $missing) implements AllocationTargetReferences {
            public function __construct(private ?array $override, private bool $missing) {}
            public function resolve(array $source, array $target): array
            {
                if ($this->missing) throw new InvalidArgumentException('Current stored line missing.');
                $current = array_replace($target, $this->override ?? []);
                return ['target'=>$current,'reference_hash'=>Validator::hash(['current_stored_line'=>$current])];
            }
        };
    }

    private static function build(array $source, array $lines, ?array $history = null, ?AllocationTargetReferences $resolver = null, ?array $original = null): array
    {
        return AllocationProposal::build($history ?? [['ledger_id'=>1,'declaration'=>$source]],
            $source['declaration_hash'], $lines, $resolver ?? self::resolver(), $original);
    }

    public function testExplicitExactAmountsReconcileAndRemainUnreviewedWithoutAuthority(): void
    {
        $source = self::source();
        $packet = self::build($source, self::lines());
        self::assertSame('10.0000', $packet['allocated_amount']);
        self::assertSame('10.0000', $packet['allocated_base_amount']);
        self::assertSame('PROVISIONAL', $packet['state']);
        self::assertSame('UNREVIEWED', $packet['review_status']);
        self::assertSame('UNKNOWN', $packet['authenticity']);
        self::assertFalse($packet['period_approval_authorized']);
        self::assertFalse($packet['money_movement_authorized']);
        self::assertSame($packet, self::build($source, array_reverse(self::lines())));
        self::assertSame($source, $packet['source']);
    }

    public function testNegativeRefundIsExplicitAndUsesSignedBuckets(): void
    {
        $source=self::source(['category'=>'REFUND','amount'=>'-10','base_amount'=>'-10']);
        self::assertSame('-10.0000', self::build($source, self::lines('-3.3333','-6.6667'))['allocated_amount']);
    }

    public function testForeignCurrencyRequiresExplicitSourceAndBaseConservation(): void
    {
        $source=self::source(['currency'=>'EUR','base_currency'=>'USD','amount'=>'10','base_amount'=>'12','fx_snapshot_id'=>1]);
        $lines=self::lines('3','7');
        $lines[0]['target']['currency']=$lines[1]['target']['currency']='EUR';
        $lines[0]['base_amount']='3.6000';$lines[1]['base_amount']='8.4000';
        $packet=self::build($source,$lines);
        self::assertSame('12.0000',$packet['allocated_base_amount']);
        $lines[1]['base_amount']='8.3999';
        $this->expectException(InvalidArgumentException::class);self::build($source,$lines);
    }

    public function testExactStorageBoundaryAndExplicitZeroDoNotAllocateAnyRemainder(): void
    {
        $source=self::source(['amount'=>'9999999999.9999','base_amount'=>'9999999999.9999']);
        self::assertSame('9999999999.9999',self::build($source,self::lines('9999999999.9998','0.0001'))['allocated_amount']);
        $source=self::source(['amount'=>'0','base_amount'=>'0']);
        self::assertSame('0.0000',self::build($source,self::lines('0','0'))['allocated_amount']);
    }

    public function testSourceHashCannotBeReplacedWithACallerDigest(): void
    {
        $source=self::source();$source['amount']='11.0000';
        $this->expectException(InvalidArgumentException::class);self::build($source,self::lines());
    }

    public function testLaterLineResolutionCannotLeaveAnEarlierTargetStale(): void
    {
        $references=new class implements AllocationTargetReferences {
            private int $firstVersion=1;
            public function resolve(array $source,array $target): array
            {
                if ($target['order_line_item_id'] === 2) $this->firstVersion=2;
                return ['target'=>$target,'reference_hash'=>Validator::hash(['line'=>$target['order_line_item_id'],
                    'version'=>$target['order_line_item_id'] === 1 ? $this->firstVersion : 1])];
            }
        };
        $this->expectException(InvalidArgumentException::class);
        self::build(self::source(),self::lines(),null,$references);
    }

    /** @dataProvider invalidLines */
    public function testNoImplicitAllocationOrScopeRepair(array $lines): void
    {
        $this->expectException(InvalidArgumentException::class);
        self::build(self::source(), $lines);
    }

    public static function invalidLines(): array
    {
        $duplicate=self::lines();$duplicate[1]['target']=$duplicate[0]['target'];
        $missing=self::lines();unset($missing[0]['target']['order_line_item_id']);
        $foreign=self::lines();$foreign[0]['target']['shop_key']='jewelry';
        $environment=self::lines();$environment[0]['target']['environment']='production';
        $currency=self::lines();$currency[0]['target']['currency']='EUR';
        $float=self::lines();$float[0]['amount']=3.3333;
        $review=self::lines();$review[0]['reviewed']=true;
        $offset=self::lines('-2','12');
        $base=self::lines();$base[0]['base_amount']='3.3334';
        return [[$duplicate],[$missing],[$foreign],[$environment],[$currency],[$float],[$review],[$offset],[$base],
            [self::lines('3','6')],[[]]];
    }

    /** @dataProvider changedLineage */
    public function testIndependentCurrentLineageConflictsCannotBeOverridden(array $current): void
    {
        $this->expectException(InvalidArgumentException::class);
        self::build(self::source(), self::lines(), null, self::resolver($current));
    }

    public static function changedLineage(): array
    {
        return [[['order_id'=>8]],[['product_id'=>90]],[['product_version_id'=>90]],
            [['listing_id'=>90]],[['business_id'=>9]],[['store_id'=>9]],
            [['provider_mapping_id'=>9,'variant_ref'=>'other']],[['currency'=>'EUR']]];
    }

    public function testMissingCurrentLineIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        self::build(self::source(),self::lines(),null,self::resolver(null,true));
    }

    public function testCurrentSourceRevisionSupersedesOriginalEvenWhenCallerRehashesIt(): void
    {
        $first=self::source();
        $second=self::source(['revision'=>2,'previous_revision_hash'=>$first['declaration_hash'],'amount'=>'11','base_amount'=>'11']);
        $this->expectException(InvalidArgumentException::class);
        self::build($first,self::lines(),[['ledger_id'=>1,'declaration'=>$first],['ledger_id'=>2,'declaration'=>$second]]);
    }

    public function testMissingPredecessorAndForgedSourceHashAreRejected(): void
    {
        $source=self::source(['revision'=>2,'previous_revision_hash'=>str_repeat('a',64)]);
        $this->expectException(InvalidArgumentException::class);
        self::build($source,self::lines());
    }

    public function testSourceScopeRestrictionsCannotBeExpandedByAllocation(): void
    {
        $source=self::source(['scope'=>['product_version_id'=>21,'listing_id'=>31]]);
        $this->expectException(InvalidArgumentException::class);
        self::build($source,self::lines());
    }

    public function testReversalRequiresExactInverseOfOriginalPacketAndCurrentOriginalSource(): void
    {
        $source=self::source();$original=self::build($source,self::lines());
        $reversal=self::source(['external_transaction_id'=>'offline-reversal','amount'=>'-10','base_amount'=>'-10','reversal_of_hash'=>$source['declaration_hash']]);
        $history=[['ledger_id'=>1,'declaration'=>$source],['ledger_id'=>2,'declaration'=>$reversal]];
        $packet=self::build($reversal,self::lines('-3.3333','-6.6667'),$history,null,$original);
        self::assertSame($original['proposal_hash'],$packet['reversal_of_proposal_hash']);
        self::assertSame('EXPLICIT_REVERSAL_PROPOSAL',$packet['reconciliation_state']);
        $this->expectException(InvalidArgumentException::class);
        self::build($reversal,self::lines('-4','-6'),$history,null,$original);
    }

    public function testReversalRechecksOriginalTargetsAfterAllOriginalLineReads(): void
    {
        $source=self::source();$original=self::build($source,self::lines());
        $reversal=self::source(['external_transaction_id'=>'offline-reversal','amount'=>'-10','base_amount'=>'-10','reversal_of_hash'=>$source['declaration_hash']]);
        $history=[['ledger_id'=>1,'declaration'=>$source],['ledger_id'=>2,'declaration'=>$reversal]];
        $references=new class implements AllocationTargetReferences {
            private int $calls=0;
            private bool $changed=false;
            public function resolve(array $source,array $target): array
            {
                ++$this->calls;
                if ($this->calls === 4) $this->changed=true;
                $snapshot=['current_stored_line'=>$target];
                if ($this->changed && $target['order_line_item_id'] === 1) $snapshot['stored_revision']=2;
                return ['target'=>$target,'reference_hash'=>Validator::hash($snapshot)];
            }
        };
        $this->expectException(InvalidArgumentException::class);
        self::build($reversal,self::lines('-3.3333','-6.6667'),$history,$references,$original);
    }

    public function testReversedOriginalCannotBeAllocatedAgain(): void
    {
        $source=self::source();
        $reversal=self::source(['external_transaction_id'=>'offline-reversal','amount'=>'-10','base_amount'=>'-10','reversal_of_hash'=>$source['declaration_hash']]);
        $this->expectException(InvalidArgumentException::class);
        self::build($source,self::lines(),[['ledger_id'=>1,'declaration'=>$source],['ledger_id'=>2,'declaration'=>$reversal]]);
    }

    public function testReversalCannotTrustCallerReviewFlagsOrTamperedOriginalPacket(): void
    {
        $source=self::source();$original=self::build($source,self::lines());$original['review_status']='APPROVED';
        $reversal=self::source(['external_transaction_id'=>'offline-reversal','amount'=>'-10','base_amount'=>'-10','reversal_of_hash'=>$source['declaration_hash']]);
        $this->expectException(InvalidArgumentException::class);
        self::build($reversal,self::lines('-3.3333','-6.6667'),[['ledger_id'=>1,'declaration'=>$source],['ledger_id'=>2,'declaration'=>$reversal]],null,$original);
    }
}
