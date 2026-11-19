<?php
declare(strict_types=1);
require_once __DIR__.'/Support/ReviewedLocalFixture.php';
require_once __DIR__.'/Support/ReviewedOwnershipFixture.php';
require_once __DIR__.'/Support/ScopedProviderConnectionFixture.php';
use DigiForge\POD\FC01NativeEvidencePacket as Packet;
use DigiForge\Security\ReviewedLocalEvidence as Review;
final class FC01NativeSourceTimestampTest extends WP_UnitTestCase {
 private array $actors; private array $packet; private array $sources; private array $connection;
 protected function setUp():void {
  parent::setUp();DigiForge\Core\Activator::activate();DigiForge\Core\Settings::protectProduction();if(!defined('DIGIFORGE_MAIN_SITE_URL'))define('DIGIFORGE_MAIN_SITE_URL',site_url());$this->actors=ReviewedLocalFixture::actors('personalized_pod');$old=ReviewedOwnershipFixture::create($this);$id=$old['integration_id'];
  self::assertTrue((new DigiForge\Integrations\Repository())->storeSecret($id,'personal_access_token','synthetic-only-packet-material'));
  $this->connection=ScopedProviderConnectionFixture::approve($id,$this->actors['author']);$context=DigiForge\POD\PrintifyConnectionBoundary::context('personalized_pod',$id,'printify.catalog.read');self::assertNotWPError($context);
  $this->packet=json_decode(file_get_contents(__DIR__.'/fixtures/fc01-native-evidence-synthetic.json'),true);$this->packet['connection']=$context;$this->packet['concept_spec_sha256']=DigiForge\POD\FC01OfflineConcept::identity()['spec_sha256'];$this->packet['sources'][0]['observed_at']=gmdate('c',time()-60);$this->packet['sources'][0]['expires_at']=gmdate('c',time()+3600);foreach($this->packet['sources']as &$source){$source['observed_at']=gmdate('c',time()-60);$source['expires_at']=gmdate('c',time()+3600);}unset($source);$png=file_get_contents(dirname(__DIR__,2).'/assets/pod-designs/fc01-v2/sample-preview.png');$this->sources=['synthetic-source'=>'synthetic offline evidence only','preview'=>$png,'final'=>$png];
 }
 private function approve():array {$id=Packet::reviewIdentity($this->packet);$p=Review::propose('policy','personalized_pod',$id['key'],$id['payload'],time()-1,time()+3600,wp_generate_uuid4(),0);self::assertNotWPError($p);wp_set_current_user($this->actors['reviewer']);$a=Review::decide('policy','personalized_pod',$id['key'],$p['revision'],$p['proposal_hash'],'APPROVED',wp_generate_uuid4());self::assertNotWPError($a);wp_set_current_user($this->actors['author']);return $a;}

 /** @dataProvider malformedTimestampProvider */
 public function testMalformedSourceTimestampNeverMatchesReviewedEvidence(string $value):void {
  $this->packet['sources'][0]['observed_at']=$value;
  $this->approve();
  self::assertSame(0,Packet::inspect($this->packet,$this->sources)['matched_section_count']);
 }
 public static function malformedTimestampProvider():array {
  return [
   'offset hour overflow'=>['2026-01-01T00:00:00+24:00'],
   'negative offset hour overflow'=>['2026-01-01T00:00:00-24:00'],
   'offset minute overflow'=>['2026-01-01T00:00:00+00:60'],
   'calendar overflow'=>['2026-02-30T00:00:00Z'],
   'hour overflow'=>['2026-01-01T24:01:00Z'],
   'minute overflow'=>['2026-01-01T00:60:00Z'],
   'second overflow'=>['2026-01-01T00:00:60Z'],
   'missing timezone'=>['2026-01-01T00:00:00'],
  ];
 }
 /** @dataProvider validTimezoneProvider */
 public function testAbsoluteSourceTimestampKeepsItsInstantAndExpiry(string $offset):void {
  $zone=new DateTimeZone($offset);
  foreach($this->packet['sources'] as &$source){
   $source['observed_at']=(new DateTimeImmutable('@'.(time()-60)))->setTimezone($zone)->format('Y-m-d\TH:i:sP');
   $source['expires_at']=(new DateTimeImmutable('@'.(time()+3600)))->setTimezone($zone)->format('Y-m-d\TH:i:sP');
  }unset($source);
  $this->approve();self::assertSame(8,Packet::inspect($this->packet,$this->sources)['matched_section_count']);
 }
 public static function validTimezoneProvider():array {return [['UTC'],['+05:30'],['-04:00']];}
}
