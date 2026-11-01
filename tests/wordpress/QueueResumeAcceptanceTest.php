<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/ShopAccessFixture.php';

/** Stored error text is private evidence, not safe operator metadata. */
final class QueueResumeAcceptanceTest extends WP_UnitTestCase
{
    protected function setUp():void
    {
        parent::setUp();DigiForge\Core\Activator::activate();DigiForge\Core\Settings::protectProduction();
        ShopAccessFixture::reset();wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));
        ShopAccessFixture::authorizeActor(get_current_user_id(),['digital','personalized_pod','standard_pod','jewelry'],DigiForge\Core\Capabilities::ALL);
    }
    private function job(string $error):array
    {
        $r=new DigiForge\Queue\JobRepository();$id=$r->enqueue('local_queue_privacy',[],wp_generate_uuid4());self::assertIsInt($id);
        self::assertTrue($r->transition($id,'BLOCKED','QUEUED'));self::assertTrue($r->acquireLease($id,'private_worker'));self::assertTrue($r->start($id,'private_worker'));self::assertTrue($r->deadLetter($id,$error));
        global $wpdb;return [$id,$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DigiForge\Database\Tables::jobs().' WHERE id=%d',$id),ARRAY_A)];
    }
    public function testFreeformFailureDetailsNeverReachReadModelsOrActualPortal():void
    {
        $error='Authorization: Bearer SYNTHETIC-PRIVATE-QUEUE-CONTEXT; customer Alice Example';[$id,$before]=$this->job($error);
        $prior=$_GET;
        try{$_GET=['df_view'=>'automation','df_shop'=>'digital','df_job_id'=>$id];$html=(new DigiForge\Portal\Portal())->render();}finally{$_GET=$prior;}
        self::assertStringNotContainsString('SYNTHETIC-PRIVATE-QUEUE-CONTEXT',$html);self::assertStringNotContainsString('Alice Example',$html);self::assertStringContainsString('REDACTED_ERROR_DETAIL',$html);
        $model=new DigiForge\Queue\OperatorQueueReadModel();$state=null;
        foreach([$model->attentionById($id),$model->recentAttention(100,$state),$model->recoveryEvidence()]as$out){
            $json=wp_json_encode($out);self::assertStringNotContainsString('SYNTHETIC-PRIVATE-QUEUE-CONTEXT',$json);self::assertStringNotContainsString('Alice Example',$json);self::assertStringContainsString(hash('sha256',$error),$json);
        }
        self::assertSame('AVAILABLE',$state);
        global $wpdb;self::assertSame($before,$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DigiForge\Database\Tables::jobs().' WHERE id=%d',$id),ARRAY_A));
        self::assertTrue(DigiForge\Core\Settings::safety_locked());self::assertFalse(DigiForge\Core\Settings::get('automation_armed',false));
    }
    public function testUnknownTokenShapedDetailCannotMasqueradeAsSafeCode():void
    {
        $raw='PRIVATE_BUYER_REFERENCE_12345';[$id]=$this->job($raw);$row=(new DigiForge\Queue\OperatorQueueReadModel())->attentionById($id);
        self::assertSame('REDACTED_ERROR_DETAIL',$row['last_error']);self::assertSame(hash('sha256',$raw),$row['last_error_sha256']);self::assertTrue($row['last_error_redacted']);
    }
    public function testEstablishedOperationalCodesRemainVisibleWithoutRetryAuthority():void
    {
        foreach(['LEASE_EXPIRED','digiforge_launch_ai_rate_limited']as$code){[$id]=$this->job($code);$row=(new DigiForge\Queue\OperatorQueueReadModel())->attentionById($id);self::assertSame($code,$row['last_error']);self::assertFalse($row['last_error_redacted']);self::assertSame(hash('sha256',$code),$row['last_error_sha256']);self::assertFalse($row['retry_permitted']);self::assertFalse($row['external_execution_authorized']);}
    }
    public function testBlankErrorAndRevokedOrPartialShopAccessDoNotExposeDrilldown():void
    {
        [$id,$before]=$this->job('');$row=(new DigiForge\Queue\OperatorQueueReadModel())->attentionById($id);self::assertSame('',$row['last_error']);self::assertFalse($row['last_error_redacted']);self::assertNull($row['last_error_sha256']);
        foreach([[],['personalized_pod'],['digital']]as$shops){ShopAccessFixture::reset();if($shops!==[])ShopAccessFixture::authorizeActor(get_current_user_id(),$shops,DigiForge\Core\Capabilities::ALL);$prior=$_GET;try{$_GET=['df_view'=>'automation','df_shop'=>'digital','df_job_id'=>$id];$html=(new DigiForge\Portal\Portal())->render();}finally{$_GET=$prior;}self::assertStringNotContainsString('id="df-job-'.$id.'"',$html);}
        global $wpdb;self::assertSame($before,$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DigiForge\Database\Tables::jobs().' WHERE id=%d',$id),ARRAY_A));
    }
}
