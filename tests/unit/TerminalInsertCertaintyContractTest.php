<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class TerminalInsertCertaintyContractTest extends TestCase {
 public function testReceiptAndFailureRequireExactlyOneInsertAndFailClosedOnMissingWinner():void {
  foreach(['ExecutionReceiptRepository.php','ExecutionFailureRepository.php'] as $file){
   $s=(string)file_get_contents(__DIR__.'/../../includes/POD/'.$file);
   self::assertStringContainsString("if(\$wpdb->insert(\$table,\$row)!==1)",$s);
   self::assertStringContainsString("if(!is_array(\$winner))return new WP_Error('digiforge_terminal_insert_confirmation_unavailable'",$s);
   self::assertStringContainsString("'retry_permitted'=>false,'external_execution_authorized'=>false",$s);
  }
 }
}
