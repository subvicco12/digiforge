<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class EtsyOrderExternalReferenceEvidenceContractTest extends TestCase {
 public function testExternalReferenceLookupAndWebhookFailClosed():void {
  $r=(string)file_get_contents(dirname(__DIR__,2).'/includes/Orders/Repository.php');
  $w=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyOrderWebhookLifecycle.php');
  self::assertStringContainsString('findByExternalReference(string $externalReference,string $shopReference): array|WP_Error|null',$r);
  self::assertStringContainsString("'External order reference evidence could not be read.'",$r);
  self::assertSame(2,substr_count($w,'if($existing instanceof WP_Error)return $existing;'));
 }
}
