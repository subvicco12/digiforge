<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ProductFactoryTransitionDbEvidenceContractTest extends TestCase {
 public function testTransitionSeparatesDbFailureFromConflict():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/ProductFactory/Repository.php');
  self::assertStringContainsString("\$wpdb->last_error = '';",$s);
  self::assertStringContainsString("\$updated === false || (string) \$wpdb->last_error !== ''",$s);
  self::assertStringContainsString("'transition_failed'",$s);
  self::assertStringContainsString("'transition_conflict'",$s);
 }
}