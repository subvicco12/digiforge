<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class TerminalConflictEvidenceAvailabilityContractTest extends TestCase {
 public function testAllTerminalWritersBlockOnUnavailableConflictEvidence():void {
  foreach(['ExecutionReceiptRepository.php','ExecutionFailureRepository.php','ExecutionUnknownRepository.php'] as $file){
   $c=file_get_contents(__DIR__.'/../../includes/POD/'.$file);
   self::assertStringContainsString('digiforge_terminal_conflict_evidence_unavailable',$c,$file);
   self::assertStringContainsString('Terminal conflict evidence is unavailable; recording is blocked.',$c,$file);
   self::assertStringContainsString("'retry_permitted'=>false",$c,$file);
  }
 }
 public function testUnknownWriterChecksEachConflictingTerminalRead():void {
  $c=file_get_contents(__DIR__.'/../../includes/POD/ExecutionUnknownRepository.php');
  self::assertStringContainsString('$conflict=$wpdb->get_row',$c);
  self::assertGreaterThanOrEqual(2,substr_count($c,'digiforge_terminal_conflict_evidence_unavailable'));
 }
}
