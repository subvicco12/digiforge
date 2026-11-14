<?php
declare(strict_types=1);
require_once __DIR__.'/Support/ReviewedLocalFixture.php';
require_once __DIR__.'/Support/ReviewedOwnershipFixture.php';
use DigiForge\Core\Settings;
use DigiForge\Database\Tables;
use DigiForge\POD\ReviewedOwnershipBinding;
use DigiForge\POD\NativePlacementPlan;
use DigiForge\Security\ReviewedLocalEvidence;

/** Connected local binding -> placement planning -> REST evidence. Never provider ownership certification. */
final class ComposedOwnershipLifecycleAcceptanceTest extends WP_UnitTestCase
{
    protected function setUp(): void {parent::setUp();DigiForge\Core\Activator::activate();Settings::protectProduction();}
    protected function tearDown(): void {self::assertTrue(Settings::safety_locked());self::assertTrue(Settings::get('stop_all'));self::assertFalse(Settings::get('automation_armed'));parent::tearDown();}
    private function reviewed(int $duration=600): array
    {
        $actors=ReviewedLocalFixture::actors('personalized_pod');$input=ReviewedOwnershipFixture::create($this);$repo=new ReviewedOwnershipBinding();
        $p=$repo->propose('personalized_pod',$input,time()-1,time()+$duration,wp_generate_uuid4(),0);self::assertNotWPError($p);self::assertArrayHasKey('binding_key',$p);$key=$p['binding_key'];
        wp_set_current_user($actors['reviewer']);$approved=$repo->decide('personalized_pod',$key,$p['revision'],$p['proposal_hash'],'APPROVED',wp_generate_uuid4());self::assertNotWPError($approved);
        self::assertNotWPError((new NativePlacementPlan())->prepare('personalized_pod',$key));
        return [$repo,$key,$input,$approved,$actors];
    }
    private function read(string $key): array
    {
        $r=new WP_REST_Request('GET','/digiforge/v1/reviewed-contracts/personalized_pod/ownership');$r->set_url_params(['shop'=>'personalized_pod','kind'=>'ownership']);$r->set_query_params(['key'=>$key]);
        $response=(new DigiForge\REST\ReviewedContractsController())->read($r);self::assertInstanceOf(WP_REST_Response::class,$response);return $response->get_data();
    }
    public function testRevocationInvalidatesConnectedPlanAndRestQualificationButPreservesHistory(): void
    {
        [$repo,$key,$input,$approved]=$this->reviewed();$before=$this->read($key);self::assertFalse($before['native_placement_plan']['supplier_certified']);
        self::assertNotWPError($repo->decide('personalized_pod',$key,$approved['revision'],$approved['proposal_hash'],'REVOKED',wp_generate_uuid4()));
        self::assertWPError((new NativePlacementPlan())->prepare('personalized_pod',$key));$after=$this->read($key);self::assertSame('REVOKED',$after['status']);self::assertFalse($after['native_placement_plan']['current_evidence_available']);
        $history=ReviewedLocalEvidence::read('ownership','personalized_pod',$key);self::assertSame($approved['payload'],$history['payload']);self::assertSame($approved['proposal_hash'],$history['proposal_hash']);self::assertFalse($history['external_execution_authorized']);
    }
    public function testActualExpiryInvalidatesCurrentQualificationWithoutDeletingApprovedReceipt(): void
    {
        [$repo,$key,$input,$approved]=$this->reviewed(3);$deadline=microtime(true)+5;
        while(time()<$approved['expires_at']&&microtime(true)<$deadline){usleep(20000);}
        self::assertGreaterThanOrEqual($approved['expires_at'],time());self::assertWPError($repo->verified('personalized_pod',$key));self::assertWPError((new NativePlacementPlan())->prepare('personalized_pod',$key));
        $after=$this->read($key);self::assertSame('APPROVED',$after['status']);self::assertFalse($after['native_placement_plan']['current_evidence_available']);self::assertFalse($after['external_execution_authorized']);
        self::assertSame($approved['proposal_hash'],ReviewedLocalEvidence::read('ownership','personalized_pod',$key)['proposal_hash']);
    }
    public function testAccountIdentityDriftAndAmbiguityCannotReuseCurrentPlanOrApproval(): void
    {
        [$repo,$key,$input,$approved]=$this->reviewed();$integration=(new DigiForge\Integrations\Repository())->find($input['integration_id']);self::assertNotWPError($integration);
        $config=$integration['config'];$config['account_reference']='synthetic-changed-'.wp_generate_uuid4();global $wpdb;self::assertSame(1,$wpdb->update(Tables::integrations(),['config'=>wp_json_encode($config)],['id'=>$input['integration_id']]));
        self::assertWPError((new NativePlacementPlan())->prepare('personalized_pod',$key));self::assertWPError($repo->verified('personalized_pod',$key));
        self::assertSame(1,$wpdb->update(Tables::integrations(),['config'=>wp_json_encode($integration['config'])],['id'=>$input['integration_id']]));
        $duplicate=(new DigiForge\Integrations\Repository())->create(['provider'=>'printify','environment'=>'test','status'=>'CONFIGURED','connection_key'=>'ambiguous-'.wp_generate_uuid4(),'config'=>$integration['config']]);self::assertNotWPError($duplicate);
        self::assertWPError((new NativePlacementPlan())->prepare('personalized_pod',$key));self::assertSame($approved['payload'],ReviewedLocalEvidence::read('ownership','personalized_pod',$key)['payload']);
        self::assertWPError(ReviewedLocalEvidence::read('ownership','digital',$key));
    }
}
