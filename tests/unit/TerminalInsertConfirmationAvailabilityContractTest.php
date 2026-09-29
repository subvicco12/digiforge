<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class TerminalInsertConfirmationAvailabilityContractTest extends TestCase { public function testAllTerminalWritersTreatFailedWinnerReadAsUncertain():void { foreach(['ExecutionReceiptRepository.php','ExecutionFailureRepository.php','ExecutionUnknownRepository.php'] as $file){ $c=file_get_contents(__DIR__.'/../../includes/POD/'.$file); self::assertStringContainsString('digiforge_terminal_insert_confirmation_unavailable',$c,$file); self::assertStringContainsString('Terminal outcome may have persisted but confirmation is unavailable; do not retry.',$c,$file); self::assertStringContainsString("'retry_permitted'=>false",$c,$file); self::assertStringContainsString("'external_execution_authorized'=>false",$c,$file); } } }
