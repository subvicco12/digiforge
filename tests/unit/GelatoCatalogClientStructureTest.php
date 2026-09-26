<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class GelatoCatalogClientStructureTest extends TestCase
{
 private function source():string{$s=file_get_contents(dirname(__DIR__,2).'/includes/POD/GelatoCatalogClient.php');self::assertIsString($s);return $s;}
 public function testOfficialReadOnlyCatalogBoundary():void{$s=$this->source();self::assertStringContainsString("https://product.gelatoapis.com/v3/",$s);self::assertStringContainsString('wp_remote_get',$s);self::assertStringContainsString('wp_remote_post',$s);self::assertStringNotContainsString('order.gelatoapis.com',$s);self::assertStringNotContainsString('/orders',$s);self::assertStringContainsString('approvedEndpoint',$s);self::assertStringContainsString("'redirection'=>0",$s);self::assertStringContainsString("'sslverify'=>true",$s);}
 public function testCredentialAndErrorsAreSanitized():void{$s=$this->source();self::assertStringContainsString("'X-API-KEY'=>\$apiKey",$s);self::assertStringContainsString('digiforge_gelato_credentials',$s);self::assertStringContainsString('digiforge_gelato_transport',$s);self::assertStringNotContainsString('error_log',$s);self::assertStringNotContainsString('Logger::',$s);self::assertStringNotContainsString('get_error_message',$s);}
 public function testBoundsAndRateLimitAreExplicit():void{$s=$this->source();self::assertStringContainsString('$limit<1||$limit>100||$offset<0',$s);self::assertStringContainsString('MAX_ATTEMPTS=3',$s);self::assertStringContainsString('MAX_RETRY_AFTER_SECONDS=60',$s);self::assertStringContainsString('$status===429',$s);self::assertStringContainsString('retryAfterSeconds',$s);}
 public function testNoExecutionSurface():void{$s=$this->source();self::assertStringContainsString("/products:search'",$s);self::assertStringNotContainsString('createOrder',$s);self::assertStringNotContainsString('submitOrder',$s);self::assertStringNotContainsString('fulfill',$s);}
}