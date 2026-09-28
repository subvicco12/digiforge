<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class TerminalOutcomeWriteEvidenceAvailabilityContractTest extends TestCase {
 public function testAllTerminalWritersBlockOnUnavailableNonceEvidence():void {
  foreach(['ExecutionReceiptRepository.php','ExecutionFailureRepository.php','ExecutionUnknownRepository.php'] as $file){
   $c=file_get_contents(__DIR__.'/../../includes/POD/'.$file);
   self::assertStringContainsString('digiforge_terminal_evidence_unavailable',$c,$file);
   self::assertStringContainsString('Terminal outcome prerequisite evidence is unavailable; recording is blocked.',$c,$file);
   self::assertStringContainsString("!empty(\$wpdb->last_error)",$c,$file);
   self::assertStringContainsString("'retry_permitted'=>false",$c,$file);
  }
 }
}
