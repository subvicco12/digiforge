<?php
declare(strict_types=1);
require_once __DIR__.'/Support/ReviewedLocalFixture.php';
use DigiForge\Security\ReviewedLocalEvidence as Evidence;
use DigiForge\Database\Tables;

final class ReviewedLocalEvidenceTest extends WP_UnitTestCase
{
    private array $actors;
    protected function setUp(): void
    {
        parent::setUp();
        DigiForge\Core\Activator::activate();
        DigiForge\Core\Settings::protectProduction();
        $this->actors=ReviewedLocalFixture::actors();
    }
    private function propose(int $revision=0,string $request='local-proposal-request-1'): array
    {
        $state=Evidence::propose('policy','digital','production:SHOP::research',['enabled'=>true],time()-1,time()+600,$request,$revision);
        self::assertNotWPError($state); return $state;
    }
    private function approve(array $state): array
    {
        wp_set_current_user($this->actors['reviewer']);
        $approved=Evidence::decide('policy','digital','production:SHOP::research',$state['revision'],$state['proposal_hash'],'APPROVED','local-approval-request-1');
        self::assertNotWPError($approved); return $approved;
    }
    public function testDurableSeparateReviewAndReplayAreBoundToExactRevision(): void
    {
        $p=$this->propose();
        self::assertWPError(Evidence::decide('policy','digital','production:SHOP::research',1,$p['proposal_hash'],'APPROVED','self-review-request-1'));
        $a=$this->approve($p);self::assertSame(2,$a['revision']);
        self::assertNotWPError(Evidence::approved('policy','digital','production:SHOP::research'));
        self::assertWPError(Evidence::decide('policy','digital','production:SHOP::research',1,$p['proposal_hash'],'APPROVED','stale-review-request-1'));
        self::assertWPError(Evidence::decide('policy','digital','production:SHOP::research',2,$a['proposal_hash'],'REVOKED','local-approval-request-1'));
        self::assertFalse($a['external_execution_authorized']);
    }
    public function testSnapshotTamperingAndRollbackAgainstAuditFailClosed(): void
    {
        global $wpdb;$p=$this->propose();
        $stream=$wpdb->get_var("SELECT object_id FROM ".Tables::audit_log()." WHERE object_type='reviewed_local_evidence' ORDER BY id DESC LIMIT 1");
        $name='digiforge_local_review_'.$stream;$old=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$name));
        $this->approve($p);
        $wpdb->update($wpdb->options,['option_value'=>$old],['option_name'=>$name]);
        self::assertWPError(Evidence::approved('policy','digital','production:SHOP::research'));
        $wpdb->delete($wpdb->options,['option_name'=>$name]);
        self::assertWPError(Evidence::read('policy','digital','production:SHOP::research'));
    }
    public function testAuditDeletionAndActorTamperingFailClosed(): void
    {
        global $wpdb;$this->approve($this->propose());
        $id=$wpdb->get_var("SELECT id FROM ".Tables::audit_log()." WHERE object_type='reviewed_local_evidence' ORDER BY id DESC LIMIT 1");
        $wpdb->update(Tables::audit_log(),['actor_id'=>$this->actors['author']],['id'=>$id]);
        self::assertWPError(Evidence::approved('policy','digital','production:SHOP::research'));
        $wpdb->delete(Tables::audit_log(),['id'=>$id]);
        self::assertWPError(Evidence::read('policy','digital','production:SHOP::research'));
    }
    public function testNewPendingVersionInvalidatesOldApprovalAndPreservesHistory(): void
    {
        $approved=$this->approve($this->propose());wp_set_current_user($this->actors['author']);
        $next=$this->propose($approved['revision'],'local-proposal-request-2');
        self::assertSame(2,$next['version']);self::assertSame(3,$next['revision']);
        self::assertWPError(Evidence::approved('policy','digital','production:SHOP::research'));
        self::assertNotSame($approved['proposal_hash'],$next['proposal_hash']);
    }
    public function testRevokedActorAssignmentInvalidatesPreviouslyApprovedEvidence(): void
    {
        $this->approve($this->propose());
        // Revoke the author's explicit synthetic authority without modifying the record.
        ShopAccessFixture::provision([]);
        self::assertWPError(Evidence::approved('policy','digital','production:SHOP::research'));
    }
    public function testNestedTransactionNeverCommitsCallerWork(): void
    {
        global $wpdb;
        $wpdb->query('COMMIT');
        $wpdb->query('START TRANSACTION');
        $name='df_synthetic_outer_review_transaction';
        $wpdb->insert($wpdb->options,['option_name'=>$name,'option_value'=>'pending','autoload'=>'no']);
        $this->propose();
        $wpdb->query('ROLLBACK');
        self::assertNull($wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$name)));
        self::assertNull(Evidence::read('policy','digital','production:SHOP::research'));
        $wpdb->query('START TRANSACTION');
    }
    public function testConcurrentAssignmentWriterCannotBeBypassed(): void
    {
        global $wpdb;
        // Publish only this test's explicit synthetic assignments before the second connection.
        $wpdb->query('COMMIT');
        $other=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);
        $timeout=$wpdb->get_var('SELECT @@session.innodb_lock_wait_timeout');
        $other->query('START TRANSACTION');
        $other->get_var($other->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s FOR UPDATE",'digiforge_shop_access_state_digital'));
        $wpdb->query('SET SESSION innodb_lock_wait_timeout=1');
        $prior=$wpdb->suppress_errors(true);
        try {
            self::assertWPError(Evidence::propose('policy','digital','production:SHOP::research',['enabled'=>true],time(),time()+600,'concurrent-proposal-request',0));
        } finally {
            $other->query('ROLLBACK');$other->close();$wpdb->suppress_errors($prior);
            $wpdb->query('SET SESSION innodb_lock_wait_timeout='.(int)$timeout);
        }
        self::assertNull(Evidence::read('policy','digital','production:SHOP::research'));
        $this->propose();
        $wpdb->query('START TRANSACTION');
    }
    public function testDeniedReviewIsAuditedAfterRollback(): void
    {
        global $wpdb;$p=$this->propose();
        self::assertWPError(Evidence::decide('policy','digital','production:SHOP::research',1,$p['proposal_hash'],'APPROVED','self-denied-request-1'));
        $row=$wpdb->get_row("SELECT context FROM ".Tables::audit_log()." WHERE event_type='local_review_denied' ORDER BY id DESC LIMIT 1",ARRAY_A);
        self::assertIsArray($row);
        self::assertSame('digiforge_local_review_invalid_transition',json_decode($row['context'],true)['reason']);
        self::assertSame('PENDING',Evidence::read('policy','digital','production:SHOP::research')['status']);
    }
    public function testMissingForeignAndUnassignedScopesFailClosed(): void
    {
        self::assertNull(Evidence::read('policy','digital','production:SHOP::research'));
        self::assertWPError(Evidence::read('policy','jewelry','production:SHOP::research'));
        $id=self::factory()->user->create(['role'=>'administrator']);wp_set_current_user($id);
        self::assertWPError(Evidence::read('policy','digital','production:SHOP::research'));
    }
    public function testCredentialAndOversizedOrInvalidWindowsNeverPersist(): void
    {
        foreach ([['access_token'=>'not-a-real-value'],['nested'=>['credential'=>'not-a-real-value']],['value'=>str_repeat('x',4097)]] as $input) {
            self::assertWPError(Evidence::propose('policy','digital','key',$input,time(),time()+600,'invalid-payload-request',0));
        }
        self::assertWPError(Evidence::propose('policy','digital','key',[],time(),time(),'invalid-window-request',0));
        self::assertWPError(Evidence::propose('unapproved','digital','key',[],time(),time()+600,'invalid-kind-request',0));
    }
}
