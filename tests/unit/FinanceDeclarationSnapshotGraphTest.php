<?php
declare(strict_types=1);

use DigiForge\Finance\DeclarationContract;
use DigiForge\Finance\DeclarationReadModel;
use DigiForge\Tests\FinanceDeclarationFixtures;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/FinanceDeclarationFixtures.php';
foreach (['ExactDecimal','ProvisionalProfitMetrics','DeclarationContract','DeclarationMatcher','DeclarationReadModel'] as $file) {
    require_once dirname(__DIR__,2).'/includes/Finance/'.$file.'.php';
}
require_once dirname(__DIR__,2).'/includes/Database/QueryEvidence.php';

/**
 * Storage-only doubles; the production matcher and report perform every graph/amount decision.
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class FinanceDeclarationSnapshotGraphTest extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('ARRAY_A')) define('ARRAY_A','ARRAY_A');
        eval('namespace DigiForge\\Finance; final class Repository {
            public function declarationEvidence(array $row):array { DeclarationContract::fromStored($row["declaration"]); return $row; }
            public function declarationHistory(array $declaration):array { global $wpdb;return array_values(array_filter($wpdb->entries,static fn(array $entry):bool=>DeclarationContract::identityKey($entry["declaration"])===DeclarationContract::identityKey($declaration))); }
            public function declarationByHash(string $hash,string $environment):array { global $wpdb;foreach($wpdb->entries as $entry)if($entry["declaration"]["declaration_hash"]===$hash)return $entry;throw new \\RuntimeException("Missing fixture original"); }
        }');
        global $wpdb;
        $wpdb=new class {
            public string $prefix='synthetic_';
            public string $last_error='';
            public array $entries=[];
            public int $reads=0;
            public ?Closure $beforeRead=null;
            public function prepare(string $sql,mixed ...$args):string
            {
                foreach ($args as $arg) $sql=preg_replace('/%[sd]/',is_int($arg)?(string)$arg:"'".$arg."'",$sql,1);
                return $sql;
            }
            public function esc_like(string $value):string { return addcslashes($value,'_%\\'); }
            public function get_results(string $sql,string $mode):?array
            {
                if ($this->beforeRead!==null) ($this->beforeRead)(++$this->reads,$this);
                else ++$this->reads;
                if ($this->last_error!=='') return null;
                $rows=$this->entries;
                // Emulate the old period-filtered query as well as the complete bounded query.
                if (preg_match("/effective_date BETWEEN '([^']+)' AND '([^']+)'/",$sql,$dates)) {
                    $rows=array_values(array_filter($rows,static fn(array $entry):bool=>substr($entry['declaration']['occurred_at'],0,10)>=$dates[1]&&substr($entry['declaration']['occurred_at'],0,10)<=$dates[2]));
                }
                if (preg_match('/LIMIT ([0-9]+)/',$sql,$limit)) $rows=array_slice($rows,0,(int)$limit[1]);
                return $rows;
            }
        };
    }

    private static function entry(array $changes=[],int $id=1):array
    {
        return ['ledger_id'=>$id,'product_id'=>9,'declaration'=>DeclarationContract::normalize(FinanceDeclarationFixtures::input(array_replace_recursive(['scope'=>['shop_key'=>'personalized_pod']],$changes)))];
    }
    private static function inverse(array $original,string $transaction='november-reversal',int $id=2):array
    {
        return self::entry(['external_transaction_id'=>$transaction,'amount'=>'-10','base_amount'=>'-10',
            'reversal_of_hash'=>$original['declaration']['declaration_hash'],
            'occurred_at'=>'2026-11-10T12:00:00Z','observed_at'=>'2026-11-10T12:01:00Z'],$id);
    }
    private static function report(string $start='2026-10-01',string $end='2026-10-31',int $limit=100):array
    { return (new DeclarationReadModel())->snapshot('personalized_pod','test',$start,$end,'USD',$limit); }

    public function testForwardInversePreservesEachPeriodsAmountsAndShowsTheConnectedStatus():void
    {
        global $wpdb;$original=self::entry();$wpdb->entries=[$original,self::inverse($original)];
        $october=self::report();self::assertSame('PROVISIONAL',$october['state']);
        self::assertSame('10.0000',$october['totals']['gross_revenue']);self::assertSame('REVERSED',$october['matching']['state']);
        self::assertCount(1,$october['gst_review']['rows']);
        $november=self::report('2026-11-01','2026-11-30');self::assertSame('-10.0000',$november['totals']['gross_revenue']);
        self::assertCount(1,$november['gst_review']['rows']);
    }
    public function testConflictingForwardInversesBlockTheOriginalPeriod():void
    {
        global $wpdb;$original=self::entry();$wpdb->entries=[$original,self::inverse($original),self::inverse($original,'second-inverse',3)];
        $report=self::report();self::assertSame('BLOCKED',$report['state']);self::assertSame('CONFLICT',$report['matching']['state']);
        self::assertNull($report['totals']);self::assertSame([],$report['gst_review']['rows']);
    }
    public function testOffPeriodReversalRevisionDriftBlocksTheOriginalPeriod():void
    {
        global $wpdb;$original=self::entry();$reverse=self::inverse($original);$revision=self::entry([
            'external_transaction_id'=>'november-reversal','revision'=>2,'previous_revision_hash'=>$reverse['declaration']['declaration_hash'],
            'amount'=>'-9','base_amount'=>'-9','reversal_of_hash'=>$original['declaration']['declaration_hash'],
            'occurred_at'=>'2026-12-01T12:00:00Z','observed_at'=>'2026-12-01T12:01:00Z'],3);
        $wpdb->entries=[$original,$reverse,$revision];$report=self::report();
        self::assertSame('BLOCKED',$report['state']);self::assertSame('CONFLICT',$report['matching']['state']);self::assertNull($report['totals']);
    }
    public function testUnrelatedOffPeriodAndForeignShopAmountsDoNotEnterTotalsOrProvenance():void
    {
        global $wpdb;$wpdb->entries=[self::entry(),self::entry(['external_transaction_id'=>'other-period','amount'=>'500','base_amount'=>'500',
            'occurred_at'=>'2026-11-01T12:00:00Z','observed_at'=>'2026-11-01T12:01:00Z'],2),
            self::entry(['external_transaction_id'=>'other-shop','scope'=>['shop_key'=>'digital'],'amount'=>'900','base_amount'=>'900'],3)];
        $report=self::report();self::assertSame('PROVISIONAL',$report['state']);self::assertSame('10.0000',$report['totals']['gross_revenue']);
        self::assertCount(1,$report['matching']['rows']);self::assertCount(1,$report['gst_review']['rows']);self::assertCount(1,$report['groups']['shop']);
    }
    public function testConnectedForeignShopConflictBlocksWithoutDisclosingForeignEvidence():void
    {
        global $wpdb;$original=self::entry();$foreign=self::entry(['external_transaction_id'=>'foreign-inverse',
            'scope'=>['shop_key'=>'digital'],'amount'=>'-10','base_amount'=>'-10',
            'reversal_of_hash'=>$original['declaration']['declaration_hash'],
            'occurred_at'=>'2026-11-10T12:00:00Z','observed_at'=>'2026-11-10T12:01:00Z'],99001);
        $wpdb->entries=[$original,$foreign];
        $scope=['shop_key'=>'personalized_pod','environment'=>'test','period_start'=>'2026-10-01','period_end'=>'2026-10-31','base_currency'=>'USD'];
        foreach ([self::report(),DeclarationReadModel::project($wpdb->entries,$scope)] as $report) {
            self::assertSame('BLOCKED',$report['state']);self::assertNull($report['totals']);
            $json=json_encode($report);self::assertStringNotContainsString($foreign['declaration']['declaration_hash'],$json);
            self::assertStringNotContainsString('99001',$json);
        }
    }
    public function testEnvironmentBoundAndExplicitReportLimitNeverReturnPartialTotals():void
    {
        global $wpdb;$wpdb->entries=[self::entry()];
        for ($i=2;$i<=201;++$i) $wpdb->entries[]=self::entry(['external_transaction_id'=>'off-period-'.$i,
            'occurred_at'=>'2026-11-01T12:00:00Z','observed_at'=>'2026-11-01T12:01:00Z'],$i);
        $report=self::report();self::assertSame('BLOCKED',$report['state']);self::assertTrue($report['truncated']);self::assertNull($report['totals']);
        $wpdb->entries=[self::entry(),self::entry(['external_transaction_id'=>'second-seed'],2)];
        self::assertSame('BLOCKED',self::report('2026-10-01','2026-10-31',1)['state']);
    }
    public function testExistingFiftyRevisionHistoryBoundStillBlocksExcessConnectedEvidence():void
    {
        global $wpdb;$previous=self::entry();$wpdb->entries=[$previous];
        for ($revision=2;$revision<=51;++$revision) {
            $previous=self::entry(['revision'=>$revision,'previous_revision_hash'=>$previous['declaration']['declaration_hash']],$revision);
            $wpdb->entries[]=$previous;
        }
        $report=self::report();self::assertSame('BLOCKED',$report['state']);self::assertNull($report['totals']);
    }
    /** @dataProvider changesDuringRead */
    public function testFinalSourceReadRejectsObservedAdditionsDeletionsAndReadFailure(string $change):void
    {
        global $wpdb;$original=self::entry();$wpdb->entries=[$original,self::inverse($original)];
        $wpdb->beforeRead=static function(int $read,object $db) use ($change,$original):void {
            if ($read!==2) return;
            if ($change==='addition') $db->entries[]=self::inverse($original,'late-second-inverse',3);
            elseif ($change==='deletion') array_pop($db->entries);
            else $db->last_error='Synthetic final source failure';
        };
        $report=self::report();self::assertSame('BLOCKED',$report['state']);self::assertNull($report['totals']);self::assertSame([],$report['gst_review']['rows']);
        if ($change==='failure') self::assertSame('UNAVAILABLE',$report['query_state']);
    }
    public static function changesDuringRead():array { return [['addition'],['deletion'],['failure']]; }
}
