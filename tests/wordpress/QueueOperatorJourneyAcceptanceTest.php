<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/ShopAccessFixture.php';

/** Persisted local jobs and actual operator HTML; no dispatch or external HTTP. */
final class QueueOperatorJourneyAcceptanceTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DigiForge\Core\Activator::activate();
        DigiForge\Core\Settings::protectProduction();
        ShopAccessFixture::reset();wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));ShopAccessFixture::authorizeActor(get_current_user_id(),['digital','personalized_pod','standard_pod','jewelry'],DigiForge\Core\Capabilities::ALL);
    }

    private function render(string $view, int $id = 0): string
    {
        $prior = $_GET;
        try {
            $_GET = ['df_view'=>$view, 'df_shop'=>'digital', 'df_job_id'=>$id];
            return (new DigiForge\Portal\Portal())->render();
        } finally { $_GET = $prior; }
    }

    public function testGlobalDashboardShowsStoredQueueHealthAndReadOnlyDrilldown(): void
    {
        global $wpdb;
        $before=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.DigiForge\Database\Tables::jobs()." WHERE state='DEAD_LETTER'");
        $id=(new DigiForge\Queue\JobRepository())->enqueue('dashboard_queue_fixture',[],'dashboard_'.wp_generate_uuid4());
        self::assertIsInt($id);
        self::assertSame(1,$wpdb->update(DigiForge\Database\Tables::jobs(),['state'=>'DEAD_LETTER'],['id'=>$id]));
        $stored=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DigiForge\Database\Tables::jobs().' WHERE id=%d',$id),ARRAY_A);
        $html=$this->render('dashboard');
        self::assertStringContainsString('Global queue health',$html);
        self::assertStringContainsString('<span>Dead letters</span><b>'.($before+1).'</b>',$html);
        self::assertStringContainsString('<span>Queue state</span><b>ATTENTION REQUIRED</b>',$html);
        self::assertStringContainsString('df_view=automation',$html);
        self::assertStringContainsString('Global evidence across all four shops',$html);
        self::assertSame($stored,$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DigiForge\Database\Tables::jobs().' WHERE id=%d',$id),ARRAY_A));
        self::assertTrue(DigiForge\Core\Settings::safety_locked());
        self::assertFalse(DigiForge\Core\Settings::get('automation_armed',false));
    }

    public function testGlobalDashboardQueueReadFailureCannotAppearAsZeroOrHealthy(): void
    {
        global $wpdb;$table=DigiForge\Database\Tables::jobs();
        $fail=static fn(string $sql):string=>str_contains($sql,'FROM '.$table)?str_replace($table,$table.'_missing_dashboard',$sql):$sql;
        $prior=$wpdb->suppress_errors(true);add_filter('query',$fail);
        try {$html=$this->render('dashboard');}
        finally {remove_filter('query',$fail);$wpdb->suppress_errors($prior);}
        self::assertStringContainsString('Global queue health',$html);
        self::assertStringContainsString('<span>Queue state</span><b>UNAVAILABLE</b>',$html);
        self::assertStringContainsString('<span>Dead letters</span><b>UNAVAILABLE</b>',$html);
        self::assertStringNotContainsString('<span>Dead letters</span><b>0</b>',$html);
    }

    public function testPersistedDeadLetterDrilldownShowsAttemptsAndNoRetryAuthority(): void
    {
        global $wpdb;
        $repository = new DigiForge\Queue\JobRepository();
        $id = $repository->enqueue('operator_journey', [], 'operator_'.wp_generate_uuid4());
        self::assertIsInt($id);
        $wpdb->update(DigiForge\Database\Tables::jobs(), ['state'=>'DEAD_LETTER','attempts'=>3,'last_error'=>'review <unsafe>'], ['id'=>$id]);
        $before = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.DigiForge\Database\Tables::jobs().' WHERE id=%d', $id), ARRAY_A);
        $html = $this->render('automation', $id);
        foreach (['id="df-job-'.$id.'"', 'operator_journey', 'DEAD_LETTER', '<td>3/3</td>', 'review &lt;unsafe&gt;', 'df_job_id='.$id, 'Automatic retry from view', 'NO'] as $text) {
            self::assertStringContainsString($text, $html);
        }
        self::assertStringNotContainsString('name="retry"', $html);
        self::assertStringNotContainsString('review <unsafe>', $html);
        self::assertSame($before, $wpdb->get_row($wpdb->prepare('SELECT * FROM '.DigiForge\Database\Tables::jobs().' WHERE id=%d', $id), ARRAY_A));
        self::assertTrue(DigiForge\Core\Settings::safety_locked());
        self::assertFalse(DigiForge\Core\Settings::get('automation_armed', false));
    }

    public function testUnavailableQueueShowsFailureRatherThanEmptyOrRecoveryPermission(): void
    {
        global $wpdb;
        $table = DigiForge\Database\Tables::jobs();
        $filter = static fn(string $sql): string => str_contains($sql, 'FROM '.$table) ? str_replace($table, $table.'_unavailable', $sql) : $sql;
        $previous = $wpdb->suppress_errors(true);
        add_filter('query', $filter);
        try { $html = $this->render('automation'); }
        finally { remove_filter('query', $filter); $wpdb->suppress_errors($previous); $wpdb->last_error = ''; }
        self::assertStringContainsString('Queue attention evidence unavailable', $html);
        self::assertStringContainsString('no empty attention queue is inferred', $html);
        self::assertStringNotContainsString('No queue items currently require operator attention.', $html);
        self::assertStringContainsString('Execution authority</span><b>NO', $html);
        self::assertTrue(DigiForge\Core\Settings::safety_locked());
    }

    public function testBlockedAndFailedRetryEvidenceIsVisibleButCannotBeExecutedFromView(): void
    {
        global $wpdb;
        $repository = new DigiForge\Queue\JobRepository();
        $blocked = $repository->enqueue('blocked_journey', [], 'blocked_'.wp_generate_uuid4());
        $failed = $repository->enqueue('failed_journey', [], 'failed_'.wp_generate_uuid4());
        self::assertIsInt($blocked); self::assertIsInt($failed);
        $wpdb->update(DigiForge\Database\Tables::jobs(), ['state'=>'FAILED','attempts'=>1,'last_error'=>'429 rate limit review'], ['id'=>$failed]);
        $html = $this->render('automation', $blocked);
        foreach (['blocked_journey', 'failed_journey', 'BLOCKED', 'FAILED', '<td>1/3</td>', '429 rate limit review', 'RATE_LIMIT_EVIDENCE_REQUIRES_BACKOFF_REVIEW', 'df_job_id='.$blocked, 'df_job_id='.$failed, '<td>NO</td><td>NO</td>'] as $text) {
            self::assertStringContainsString($text, $html);
        }
        self::assertStringNotContainsString('name="retry"', $html);
        self::assertSame('BLOCKED', $wpdb->get_var($wpdb->prepare('SELECT state FROM '.DigiForge\Database\Tables::jobs().' WHERE id=%d', $blocked)));
        self::assertSame('FAILED', $wpdb->get_var($wpdb->prepare('SELECT state FROM '.DigiForge\Database\Tables::jobs().' WHERE id=%d', $failed)));
        self::assertTrue(DigiForge\Core\Settings::safety_locked());
    }
}
