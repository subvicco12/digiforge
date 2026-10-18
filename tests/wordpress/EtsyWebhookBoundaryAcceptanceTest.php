<?php
declare(strict_types=1);

use DigiForge\Database\Tables;

final class EtsyWebhookBoundaryAcceptanceTest extends WP_UnitTestCase {
 protected function setUp():void {parent::setUp();DigiForge\Core\Activator::activate();DigiForge\Core\Settings::protectProduction();wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));}
 private function signed(string $body):array {$event='webhook-boundary-'.wp_generate_uuid4();$now=time();$key='synthetic-webhook-boundary';return [$event,$now,'whsec_'.base64_encode($key),['webhook-id'=>$event,'webhook-timestamp'=>(string)$now,'webhook-signature'=>'v1,'.base64_encode(hash_hmac('sha256',$event.'.'.$now.'.'.$body,$key,true))]];}
 public function testOversizeAndUnsupportedAuthenticBodiesNeverPersistWebhookOrOrderEvidence():void {
  global $wpdb;$orders=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Tables::orders());$events=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Tables::webhook_evidence());$intake=new DigiForge\Listings\EtsyWebhookIntake(new DigiForge\Listings\EtsyWebhookDeduplicator());
  foreach([(string)wp_json_encode(['event_type'=>'order.paid','padding'=>str_repeat('x',262144)]),(string)wp_json_encode(['event_type'=>'order.refunded','order_id'=>'1','shop_id'=>'2'])]as $body){[$id,$now,$secret,$headers]=$this->signed($body);self::assertWPError($intake->accept($body,$headers,$secret,$now));}
  self::assertSame($orders,(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Tables::orders()));self::assertSame($events,(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Tables::webhook_evidence()));
 }
 public function testShippedAndDeliveredArePersistedInformationalEventsWithoutOrderOrProductionStateMutation():void {
  $repo=new DigiForge\Orders\Repository();$order=$repo->createOrder(['channel'=>'etsy','environment'=>'production','shop_reference'=>'912002','external_order_reference'=>'912001']);self::assertNotWPError($order);$intake=new DigiForge\Listings\EtsyWebhookIntake(new DigiForge\Listings\EtsyWebhookDeduplicator());global $wpdb;
  foreach(['order.shipped','order.delivered']as $type){$body=(string)wp_json_encode(['event_type'=>$type,'order_id'=>'912001','shop_id'=>'912002','tracking_code'=>'synthetic-tracking','carrier_name'=>'Synthetic carrier']);[$id,$now,$secret,$headers]=$this->signed($body);$result=$intake->accept($body,$headers,$secret,$now);self::assertNotWPError($result);self::assertSame('ETSY_ORDER_EVENT_RECORDED',$result['state']);self::assertFalse($result['evidence']['authoritative_fulfillment_state']);self::assertFalse($result['fulfillment_authorized']);self::assertFalse($result['external_execution_performed']);self::assertSame('RECEIVED',$repo->findByExternalReference('912001','912002')['state']);self::assertSame('PROCESSED',$wpdb->get_var($wpdb->prepare('SELECT processing_status FROM '.Tables::webhook_evidence().' WHERE event_id=%s',$id)));self::assertNotWPError($intake->accept($body,$headers,$secret,$now));}
  self::assertTrue(DigiForge\Core\Settings::safety_locked());
 }
 public function testRequestSuppliedSigningSecretCannotConfigureServerBoundaryAndReadinessNeverReturnsSecret():void {
  self::assertFalse(defined('DIGIFORGE_ETSY_WEBHOOK_SECRET'),'This fixture deliberately exercises an unconfigured server.');$controller=new DigiForge\REST\EtsyWebhookController();$request=new WP_REST_Request('POST','/digiforge/v1/etsy/webhook');$request->set_param('signing_secret','whsec_'.base64_encode('request-only-fixture'));$request->set_header('x-webhook-secret','request-only-fixture');$request->set_body((string)wp_json_encode(['secret'=>'request-only-fixture']));$result=$controller->receive($request);self::assertWPError($result);self::assertSame('digiforge_etsy_webhook_unconfigured',$result->get_error_code());
  $readiness=$controller->readiness()->get_data();self::assertSame('server_constant',$readiness['configuration_source']);self::assertSame('CONFIGURATION_REQUIRED',$readiness['state']);self::assertFalse($readiness['secret_exposed']);self::assertFalse($readiness['signing_secret_configured']);self::assertStringNotContainsString('request-only-fixture',(string)wp_json_encode($readiness));
 }
}
