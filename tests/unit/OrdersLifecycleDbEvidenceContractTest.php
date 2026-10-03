<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class OrdersLifecycleDbEvidenceContractTest extends TestCase{
 public function testLifecycleWriteSeparatesDbFailureFromConflict():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Orders/Repository.php');
  self::assertStringContainsString("'transition_failed'",$s);
  self::assertStringContainsString("\$updated===false||!empty(\$wpdb->last_error)",$s);
  self::assertStringContainsString("'transition_conflict','State changed concurrently.'",$s);
 }
}