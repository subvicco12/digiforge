<?php
declare(strict_types=1);
final class PrintifyCatalogResponseSafetyTest extends WP_UnitTestCase {
 public function testLegacyCatalogResponseCannotReturnEscapedCredentialOrProtectedFields():void {
  foreach(['{"variants":[{"id":202,"title":"\\u006ffixture-catalog-secret"}]}','{"variants":[{"id":202,"access_token":"untrusted"}]}']as $body){$client=new DigiForge\POD\PrintifyCatalogClient(static fn()=>['response'=>['code'=>200],'body'=>$body]);self::assertWPError($client->variantsWithEvidence('fixture-catalog-secret',101,2));}
 }
 public function testLegacyCatalogTransportIsBoundedAndExceptionsAreSanitized():void {
  $client=new DigiForge\POD\PrintifyCatalogClient(static function($url,$args){self::assertSame(1048577,$args['limit_response_size']??null);return ['response'=>['code'=>200],'body'=>str_repeat('x',1048577)];});self::assertWPError($client->blueprint('fixture-catalog-secret',101));
  $client=new DigiForge\POD\PrintifyCatalogClient(static function(){throw new RuntimeException('fixture-catalog-secret');});$result=$client->blueprint('fixture-catalog-secret',101);self::assertWPError($result);self::assertStringNotContainsString('fixture-catalog-secret',wp_json_encode($result));
 }
 public function testSafeLegacyCatalogRetainsExactRawBodyDigestAndAbsoluteObservation():void {
  $body='{"variants":[{"id":202,"title":"offline only"}]}';$client=new DigiForge\POD\PrintifyCatalogClient(static fn()=>['response'=>['code'=>200],'body'=>$body]);$result=$client->variantsWithEvidence('fixture-catalog-secret',101,2);self::assertIsArray($result);self::assertSame(hash('sha256',$body),$result['source_evidence']['response_sha256']);self::assertNotFalse(strtotime($result['source_evidence']['observed_at']));self::assertSame('offline only',$result['data']['variants'][0]['title']);
 }
}
