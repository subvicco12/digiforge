<?php
declare(strict_types=1);

use DigiForge\Core\Activator;
use DigiForge\Database\Tables;
use DigiForge\Finance\Repository;

/** Synthetic local transactions only; no accounting classification or financial approval. */
final class FinanceAtomicAuditTest extends WP_UnitTestCase
{
    /** @dataProvider mutationCases */
    public function testAuditFailureCannotPersistAnUnauditedMutation(string $case):void
    {
        Activator::activate();wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));global $wpdb;$repo=new Repository();$id=0;
        $ledger=['environment'=>'test','source_type'=>'manual','source_id'=>0,'entry_type'=>'COGS','currency'=>'USD','amount'=>1,'effective_date'=>'2026-09-10'];
        if($case==='period_state'){
            $row=$repo->calculatePeriod(['environment'=>'test','period_start'=>'2026-09-01','period_end'=>'2026-09-30','base_currency'=>'USD']);self::assertIsArray($row);$id=(int)$row['id'];
        }
        if($case==='tax_review'){$row=$repo->createTaxClassification(['environment'=>'test','currency'=>'USD','amount_basis'=>1,'classification'=>'REVIEW_REQUIRED']);self::assertIsArray($row);$id=(int)$row['id'];}
        $table=match($case){'ledger'=>Tables::finance_ledger(),'fx'=>Tables::fx_snapshots(),'tax','tax_review'=>Tables::tax_classifications(),default=>Tables::finance_periods()};
        $before=$wpdb->get_results('SELECT * FROM '.$table,ARRAY_A);
        $filter=static fn(string $sql):string=>str_starts_with($sql,'INSERT INTO `'.Tables::audit_log().'`')?str_replace(Tables::audit_log(),'missing_finance_audit_fixture',$sql):$sql;
        $old=$wpdb->suppress_errors(true);add_filter('query',$filter);
        try{$result=match($case){
            'ledger'=>$repo->createLedger($ledger),
            'fx'=>$repo->createFx(['environment'=>'test','base_currency'=>'USD','quote_currency'=>'EUR','rate'=>1]),
            'tax'=>$repo->createTaxClassification(['environment'=>'test','currency'=>'USD','amount_basis'=>1,'classification'=>'REVIEW_REQUIRED']),
            'period'=>$repo->calculatePeriod(['environment'=>'test','period_start'=>'2026-09-01','period_end'=>'2026-09-30','base_currency'=>'USD']),
            'period_state'=>$repo->transition('period',$id,'REVIEW_REQUIRED'),
            'tax_review'=>$repo->reviewTaxClassification($id,'APPROVED'),
        };}finally{remove_filter('query',$filter);$wpdb->suppress_errors($old);}
        self::assertWPError($result);self::assertSame($before,$wpdb->get_results('SELECT * FROM '.$table,ARRAY_A));
    }
    public static function mutationCases():array{return array_map(static fn(string $v):array=>[$v],['ledger','fx','tax','period','period_state','tax_review']);}

    public function testSuccessfulAppendHasOneAuditAndReplayCannotDuplicateEither():void
    {
        Activator::activate();wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));global $wpdb;$repo=new Repository();
        $input=['environment'=>'test','source_type'=>'manual','source_id'=>0,'entry_type'=>'COGS','currency'=>'USD','amount'=>0,'effective_date'=>'2026-09-10'];
        $row=$repo->createLedger($input,'synthetic-atomic-replay');self::assertIsArray($row);
        $replay=$repo->createLedger($input,'synthetic-atomic-replay');self::assertIsArray($replay);self::assertTrue($replay['idempotent_replay']);self::assertSame($row['id'],$replay['id']);
        self::assertSame('1',$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Tables::audit_log().' WHERE object_type=%s AND object_id=%s AND event_type=%s','finance_ledger',$row['id'],'finance_ledger_created')));
        self::assertSame('1',$wpdb->get_var("SELECT COUNT(*) FROM ".Tables::finance_ledger()." WHERE idempotency_key='synthetic-atomic-replay'"));
        self::assertTrue(DigiForge\Core\Settings::safety_locked());
    }

    /** @dataProvider transactionFailures */
    public function testUnknownEngineAndTransactionFailureDenyBeforeUnauditedPersistence(string $fault):void
    {
        Activator::activate();wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));global $wpdb;
        $before=$wpdb->get_results('SELECT * FROM '.Tables::finance_ledger(),ARRAY_A);$hit=false;
        $filter=static function(string $sql)use($fault,&$hit):string{
            $matches=match($fault){'engine'=>str_starts_with($sql,'SHOW CREATE TABLE'), 'status'=>$sql==='SELECT @@session.in_transaction', 'begin'=>str_starts_with($sql,'SAVEPOINT df_finance_')||$sql==='START TRANSACTION', default=>str_starts_with($sql,'RELEASE SAVEPOINT df_finance_')||$sql==='COMMIT'};
            if(!$hit&&$matches){$hit=true;return 'SELECT * FROM missing_finance_transaction_fixture';}return $sql;
        };
        $old=$wpdb->suppress_errors(true);add_filter('query',$filter);
        try{$row=(new Repository())->createLedger(['environment'=>'test','source_type'=>'manual','source_id'=>0,'entry_type'=>'COGS','currency'=>'USD','amount'=>1,'effective_date'=>'2026-09-10']);}finally{remove_filter('query',$filter);$wpdb->suppress_errors($old);}
        self::assertTrue($hit);self::assertWPError($row);self::assertSame($before,$wpdb->get_results('SELECT * FROM '.Tables::finance_ledger(),ARRAY_A));
    }
    public static function transactionFailures():array{return [['engine'],['status'],['begin'],['commit']];}

    /** @dataProvider auditDrift */
    public function testReplayCannotAuthenticateMissingConflictingOrUnavailableCreationAudit(string $case):void
    {
        Activator::activate();wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));global $wpdb;$repo=new Repository();
        $input=['environment'=>'test','source_type'=>'manual','source_id'=>0,'entry_type'=>'COGS','currency'=>'USD','amount'=>1,'effective_date'=>'2026-09-10'];
        $key='synthetic-audit-drift-'.$case;$row=$repo->createLedger($input,$key);self::assertIsArray($row);
        $where=['object_type'=>'finance_ledger','object_id'=>(string)$row['id'],'event_type'=>'finance_ledger_created'];
        if($case==='missing')$wpdb->delete(Tables::audit_log(),$where);
        if($case==='actor')$wpdb->update(Tables::audit_log(),['actor_id'=>get_current_user_id()+1000],$where);
        if($case==='duplicate')self::assertTrue(DigiForge\Security\Logger::write('finance_ledger_created',['idempotency_key'=>'[PRESENT]'],'finance_ledger',(string)$row['id']));
        $filter=static fn(string $sql):string=>$case==='outage'&&str_starts_with($sql,'SELECT actor_id,context FROM '.Tables::audit_log())?'SELECT * FROM missing_finance_replay_audit_fixture':$sql;
        $old=$wpdb->suppress_errors(true);add_filter('query',$filter);
        try{$replay=$repo->createLedger($input,$key);}finally{remove_filter('query',$filter);$wpdb->suppress_errors($old);}
        self::assertWPError($replay);self::assertSame('1',$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Tables::finance_ledger().' WHERE idempotency_key=%s',$key)));
    }
    public static function auditDrift():array{return [['missing'],['actor'],['duplicate'],['outage']];}

    public function testSavepointRollbackPreservesCallerWorkAndSuccessfulAppendDoesNotCommitCaller():void
    {
        Activator::activate();wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));global $wpdb;
        self::assertNotFalse($wpdb->query('SAVEPOINT df_finance_outer_fixture'));
        $input=['environment'=>'test','source_type'=>'manual','source_id'=>0,'entry_type'=>'COGS','currency'=>'USD','amount'=>1,'effective_date'=>'2026-09-10'];$repo=new Repository();$baseline=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Tables::finance_ledger());
        $first=$repo->createLedger($input,'caller-first');self::assertIsArray($first);
        $filter=static fn(string $sql):string=>str_starts_with($sql,'INSERT INTO `'.Tables::audit_log().'`')?str_replace(Tables::audit_log(),'missing_finance_outer_audit_fixture',$sql):$sql;
        $old=$wpdb->suppress_errors(true);add_filter('query',$filter);
        try{self::assertWPError($repo->createLedger($input,'caller-second'));}finally{remove_filter('query',$filter);$wpdb->suppress_errors($old);}
        self::assertSame($baseline+1,(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Tables::finance_ledger()));
        self::assertNotFalse($wpdb->query('ROLLBACK TO SAVEPOINT df_finance_outer_fixture'));self::assertSame($baseline,(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Tables::finance_ledger()));
        self::assertNotFalse($wpdb->query('RELEASE SAVEPOINT df_finance_outer_fixture'));
    }
}
