<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/ShopAccessFixture.php';
require_once __DIR__.'/Support/ScopedProviderConnectionFixture.php';
require_once __DIR__.'/fixtures/PrintifyCatalogReadFixture.php';
use DigiForge\POD\PrintifyCatalogReadRepository as Reads;
final class PrintifyCatalogResponseSafetyTest extends WP_UnitTestCase {
 use PrintifyCatalogReadFixture;
 private function readResponse(callable $sender, string $key):array {
  $this->unlocked();
  $r=new Reads(new DigiForge\POD\PrintifyCatalogClient($sender));
  $grant=$r->approve($this->input,'response-'.$key);
  self::assertIsArray($grant);
  return [$r,$grant];
 }
 public function testCatalogResponseCannotReturnEscapedCredentialOrProtectedFields():void {
  foreach(['{"variants":[{"id":202,"title":"\\u006fffline-catalog-fixture-credential"}]}','{"variants":[{"id":202,"access_token":"untrusted"}]}']as $i=>$body){
   $calls=0;[$r,$grant]=$this->readResponse(static function()use($body,&$calls){++$calls;return ['response'=>['code'=>200],'body'=>$body];},'protected-'.$i);
   $result=$r->read($grant['sha256'],0,'protected-request');self::assertWPError($result);self::assertSame('catalog_read_response',$result->get_error_code());
   self::assertSame(1,$calls);
   self::assertWPError($r->evidence($grant['sha256'],hash('sha256','protected-request')));
  }
 }
 public function testCatalogTransportIsBoundedAndExceptionsAreSanitized():void {
  $calls=0;[$r,$grant]=$this->readResponse(static function($url,$args)use(&$calls){++$calls;self::assertSame(1048577,$args['limit_response_size']??null);self::assertSame(0,$args['redirection']);return ['response'=>['code'=>200],'body'=>str_repeat('x',1048577)];},'bounded');
  $result=$r->read($grant['sha256'],0,'bounded-request');self::assertWPError($result);self::assertSame('catalog_read_response',$result->get_error_code());self::assertSame(1,$calls);
  $throwCalls=0;[$r,$grant]=$this->readResponse(static function()use(&$throwCalls){++$throwCalls;throw new RuntimeException('offline-catalog-fixture-credential');},'thrown');
  $result=$r->read($grant['sha256'],0,'thrown-request');self::assertWPError($result);self::assertSame('catalog_read_transport',$result->get_error_code());self::assertSame(1,$throwCalls);self::assertStringNotContainsString('offline-catalog-fixture-credential',wp_json_encode($result));
 }
 public function testSafeCatalogRetainsExactRawBodyDigestAndAbsoluteObservation():void {
  $body='{"variants":[{"id":202,"title":"offline only"}]}';
  [$r,$grant]=$this->readResponse(static fn()=>['response'=>['code'=>200],'body'=>$body],'exact-digest');
  $result=$r->read($grant['sha256'],0,'digest-request');self::assertIsArray($result);self::assertSame(hash('sha256',$body),$result['response_sha256']);self::assertNotFalse(strtotime($result['observed_at']));self::assertSame('offline only',$result['data']['title']);
 }
 public function testRateLimitConsumesReservationWithoutAutomaticRetryOrReplay():void {
  $calls=0;[$r,$grant]=$this->readResponse(static function()use(&$calls){++$calls;return ['response'=>['code'=>429],'headers'=>['retry-after'=>'999999999'],'body'=>'{}'];},'rate-limit');
  $result=$r->read($grant['sha256'],0,'rate-request');self::assertWPError($result);self::assertSame(1,$calls);
  self::assertWPError($r->read($grant['sha256'],0,'rate-request'));self::assertSame(1,$calls);
  self::assertWPError($r->evidence($grant['sha256'],hash('sha256','rate-request')));
 }
 public function testRevocationPreventsTransportEvenWithPermissiveLegacyCallback():void {
  $calls=0;$client=new DigiForge\POD\PrintifyCatalogClient(static function()use(&$calls){++$calls;return [];},null,static fn()=>true);
  $r=new Reads($client);$grant=$r->approve($this->input,'revocation');self::assertIsArray($grant);$this->unlocked();self::assertTrue($r->revoke($grant['sha256']));self::assertWPError($r->read($grant['sha256'],0,'revoked-request'));self::assertSame(0,$calls);
 }
}
