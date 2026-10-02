<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class EtsyScopedCredentialEvidenceAvailabilityContractTest extends TestCase{
 public function testCredentialReadSeparatesDatabaseFailureFromMissingMaterial():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyScopedCredentialRetriever.php');
  self::assertStringContainsString("\$wpdb->last_error=''",$s);
  self::assertStringContainsString("'evidence_unavailable'",$s);
  self::assertStringContainsString('Authorized Etsy credential evidence could not be read.',$s);
  self::assertStringContainsString('Authorized Etsy credential evidence is unavailable.',$s);
  self::assertStringContainsString("self::error('not_found'",$s);
  self::assertStringContainsString('int $status=409',$s);
  self::assertStringContainsString("['status'=>\$status]",$s);
 }
}
