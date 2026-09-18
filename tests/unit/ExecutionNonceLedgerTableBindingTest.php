<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ExecutionNonceLedgerTableBindingTest extends TestCase{
 public function testNonceLedgerUsesCanonicalTableHelper():void{
  $s=file_get_contents(dirname(__DIR__,2).'/includes/POD/ExecutionNonceLedger.php');self::assertIsString($s);
  self::assertStringContainsString('Tables::pod_execution_nonces()',$s);
  self::assertStringNotContainsString("\$wpdb->prefix.'digiforge_pod_execution_nonces'",$s);
 }
}
