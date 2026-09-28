<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class DigitalDescendantEvidenceAvailabilityContractTest extends TestCase {
 public function testDescendantCountFailuresCannotBecomeZeroBeforeUpdate():void {
  $c=file_get_contents(__DIR__.'/../../includes/DigitalFactory/Repository.php');
  $start=strpos($c,'private function validate_descendants');
  $end=strpos($c,'private function key(',$start);
  $method=substr($c,$start,$end-$start);
  self::assertStringNotContainsString('(int) $wpdb->get_var',$method);
  self::assertStringContainsString('relationship_evidence_unavailable',$method);
  self::assertStringContainsString('Descendant relationship evidence is unavailable; update is blocked.',$method);
  self::assertStringContainsString('!empty($wpdb->last_error)||!is_numeric($raw)',$method);
 }
 public function testDescendantValidationStillPrecedesDigitalEntityUpdate():void {
  $c=file_get_contents(__DIR__.'/../../includes/DigitalFactory/Repository.php');
  $validation=strpos($c,'$descendants = $this->validate_descendants');
  $update=strpos($c,'$wpdb->update',$validation);
  self::assertNotFalse($validation);self::assertNotFalse($update);self::assertLessThan($update,$validation);
 }
}
