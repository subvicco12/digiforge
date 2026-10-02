<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class EtsyVerifiedShopEvidenceAvailabilityContractTest extends TestCase{
 public function testDirectIntegrationLookupSeparatesUnavailableEvidenceFromInvalidIntegration():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyVerifiedShopIdentity.php');
  self::assertStringContainsString("\$wpdb->last_error='';\n        \$row=\$wpdb->get_row",$s);
  self::assertStringContainsString("'evidence_unavailable','Configured Etsy integration evidence could not be read.',503",$s);
  self::assertStringContainsString("'integration','A configured Etsy integration is required.'",$s);
 }
 public function testResolveAnySeparatesEnumerationFailureFromNoMatchingIntegration():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyVerifiedShopIdentity.php');
  self::assertStringContainsString("\$wpdb->last_error='';\n        \$ids=\$wpdb->get_col",$s);
  self::assertStringContainsString("!is_array(\$ids)||!empty(\$wpdb->last_error)",$s);
  self::assertStringContainsString("'evidence_unavailable','Configured Etsy integration evidence could not be enumerated.',503",$s);
  self::assertStringContainsString("'scope','No verified Etsy integration matches the approved listing shop scope.'",$s);
 }
 public function testEvidenceUnavailableUses503WithoutChangingExistingDefaultDenials():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyVerifiedShopIdentity.php');
  self::assertStringContainsString("int \$status=409",$s);
  self::assertStringContainsString("['status'=>\$status]",$s);
  foreach(["'unverified'","'scope'"] as $n)self::assertStringContainsString($n,$s);
 }
}
