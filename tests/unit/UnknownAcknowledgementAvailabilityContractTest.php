<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class UnknownAcknowledgementAvailabilityContractTest extends TestCase {
 public function testFailedAcknowledgementReadIsNotProjectedAsUnacknowledged():void {
  $c=file_get_contents(__DIR__.'/../../includes/POD/PrintifyUnknownOperatorReadModel.php');
  self::assertStringContainsString('ACKNOWLEDGEMENT_EVIDENCE_UNAVAILABLE',$c);
  self::assertStringContainsString('!empty($wpdb->last_error)||!is_numeric($ackRaw)',$c);
  self::assertStringContainsString("'retry_permitted'=>false",$c);
  self::assertStringContainsString("'external_execution_authorized'=>false",$c);
  self::assertStringContainsString("'read_only'=>true",$c);
 }
}
