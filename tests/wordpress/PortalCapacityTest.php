<?php
use DigiForge\PortalAccess\{Store,Policy};
require_once __DIR__.'/fixtures/PortalAccessFixture.php';
final class PortalCapacityTest extends WP_UnitTestCase {
 protected function setUp():void{parent::setUp();PortalAccessFixture::reset();}
 protected function tearDown():void{global $wpdb;$wpdb->query('COMMIT');PortalAccessFixture::reset();$wpdb->query('COMMIT');$wpdb->query('START TRANSACTION');parent::tearDown();}
 private function fill(int $id,int $n):array{global $wpdb;$prior='';for($v=1;$v<=$n;$v++){$s=['actor_id'=>$id,'role'=>'Viewer','enabled'=>true,'revision'=>$v,'generation'=>$v,'shops'=>['personalized_pod'=>['manage_digiforge','manage_digiforge_research']]];$e=['target_id'=>$id,'actor_id'=>get_current_user_id(),'revision'=>$v,'request_id'=>hash('sha256',$id.':fixture:'.$v),'prior_hash'=>$prior,'state_hash'=>Store::hash($s),'state'=>$s];$wpdb->insert($wpdb->options,['option_name'=>'digiforge_portal_event_'.$id.'_'.$v,'option_value'=>wp_json_encode($e),'autoload'=>'no']);\DigiForge\Security\Logger::write('portal_profile_changed',$e,'portal_access_profile',(string)$id);$prior=$e['state_hash'];}$wpdb->insert($wpdb->options,['option_name'=>Store::key($id),'option_value'=>wp_json_encode($s),'autoload'=>'no']);self::assertSame($s,Store::read($id));return $s;}
 public function testCapacityRejectsBeforeWritingUnreadableRevision1001():void{PortalAccessFixture::enroll();$id=self::factory()->user->create(['role'=>'administrator']);$s=$this->fill($id,1000);$n=$s;$n['revision']++;$n['generation']++;self::assertWPError(Store::save($n,1000,wp_generate_uuid4()));self::assertSame($s,Store::read($id));}
 public function testTwoPhaseEnableRequiresTwoFreeHistorySlots():void{PortalAccessFixture::enroll();$id=self::factory()->user->create(['role'=>'administrator']);$s=$this->fill($id,999);self::assertWPError(Store::change($id,'Viewer',['personalized_pod'=>['research']],true,999,wp_generate_uuid4()));self::assertSame($s,Store::read($id));}
}
