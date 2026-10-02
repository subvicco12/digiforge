<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class EtsyDigitalAttachmentEvidenceAvailabilityContractTest extends TestCase{
 public function testReadModelAndPortalSeparateUnavailableFromEmptyEvidence():void{
  $m=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyDigitalAttachmentReadModel.php');
  self::assertStringContainsString('?string &$evidenceState = null',$m);
  self::assertStringContainsString("\$evidenceState = 'UNAVAILABLE'",$m);
  self::assertStringContainsString("\$wpdb->last_error = ''",$m);
  self::assertStringContainsString("\$evidenceState = 'AVAILABLE'",$m);
  $p=(string)file_get_contents(dirname(__DIR__,2).'/includes/Portal/Portal.php');
  self::assertStringContainsString("\$attachmentEvidenceState==='UNAVAILABLE'",$p);
  self::assertStringContainsString('Digital file upload evidence is unavailable. No absence of confirmed uploads is asserted.',$p);
  self::assertStringContainsString('No confirmed digital file upload evidence in the recent window.',$p);
  self::assertStringContainsString('NO UPLOAD / NO PUBLISH',$p);
 }
}
