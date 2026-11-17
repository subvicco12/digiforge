<?php
declare(strict_types=1);
use DigiForgePrintifyConnector\Preparation\{MutationInspectionCoreInterface,VersionedMutationHost,OperatorReadModel,OperatorPanel,PreparationService};
final class PrintifyCompanionOperatorIntegrationTest extends WP_UnitTestCase {
 public function set_up():void {parent::set_up();require_once dirname(__DIR__,2).'/companion/digiforge-printify-connector/digiforge-printify-connector.php';}
 private function intent(int $actor=1):DigiForgePrintifyConnector\Preparation\MutationIntent {[$b,$e,$s]=(require __DIR__.'/../fixtures/printify-connector/stage34/artwork-fixture.php')();return (new PreparationService())->upload($s,$b,$e,['actor_id'=>$actor,'logical_reference'=>'core-asset-1','approval_reference'=>str_repeat('d',64),'authorization_revision'=>1,'product_version'=>1,'expected_product_version'=>0]);}
 public function testDefaultPanelRemainsDeniedAndReadOnlyUnderSafetyLocks():void {
  self::assertTrue(method_exists(DigiForgePrintifyConnector\Plugin::class,'operationPanel'));$routes=rest_get_server()->get_routes();$cron=_get_cron_array();global $wpdb;$count=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options}");$html=DigiForgePrintifyConnector\Plugin::operationPanel([$this->intent()]);self::assertStringContainsString('unavailable',$html);self::assertStringNotContainsString('<form',$html);self::assertSame($count,(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options}"));self::assertSame($routes,rest_get_server()->get_routes());self::assertSame($cron,_get_cron_array());self::assertTrue(DigiForge\Core\Settings::get('stop_all',true));
 }
 public function testScopedOperatorInspectionRevocationAndRedactedExport():void {
  require_once __DIR__.'/../fixtures/printify-connector/batch3/SyntheticInspectionCore.php';$actor=self::factory()->user->create(['role'=>'administrator']);wp_set_current_user($actor);$i=$this->intent($actor);
  $port=new class($actor) implements MutationInspectionCoreInterface {
   public SyntheticInspectionCore $fixture;public function __construct(private int $actor){$this->fixture=new SyntheticInspectionCore();}
   public function version():string{return '1.0.0';}public function inspectionVersion():string{return '1.0.0';}
   public function exchange(array $q):array|WP_Error{return new WP_Error('no_mutations');}
   public function inspect(array $b):array|WP_Error{if(get_current_user_id()!==$this->actor||!current_user_can('manage_options')||$b['actor_id']!==$this->actor)return new WP_Error('synthetic_read_denied');return $this->fixture->inspect($b);}
  };
  $model=new OperatorReadModel(new VersionedMutationHost($port));$port->fixture->fault='revoked_approval';self::assertStringContainsString('APPROVAL_REVOKED',OperatorPanel::render($model,[$i]));$export=$model->export([$i]);self::assertIsString($export);self::assertStringNotContainsString('credential_reference',$export);wp_set_current_user(0);self::assertWPError($model->export([$i]));
 }
 public function testIntactSchemaMarkersAndConsumedEvidenceSurviveReactivation():void {
  DigiForge\Core\Activator::activate();global $wpdb;$keys=['digiforge_db_schema_version','digiforge_db_version'];$before=[];foreach($keys as $key)$before[$key]=get_option($key);$nonce=hash('sha256',wp_generate_uuid4());$auth=hash('sha256',wp_generate_uuid4());$table=DigiForge\Database\Tables::pod_execution_nonces();self::assertSame(1,$wpdb->insert($table,['nonce_hash'=>$nonce,'authorization_hash'=>$auth,'consumed_by'=>1,'consumed_at'=>current_time('mysql',true)]));
  self::assertTrue((new DigiForge\Database\Migrator())->maybe_migrate());DigiForge\Core\Activator::activate();foreach($keys as $key)self::assertSame($before[$key],get_option($key));self::assertSame($auth,$wpdb->get_var($wpdb->prepare("SELECT authorization_hash FROM $table WHERE nonce_hash=%s",$nonce)));self::assertSame(1,(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE nonce_hash=%s",$nonce)));
 }
}
