<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ListingTransitionDbEvidenceContractTest extends TestCase{
 public function testTransitionSeparatesPersistenceFailureFromConcurrentConflict():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/Repository.php');
  self::assertStringContainsString("'transition_persistence_unavailable'",$s);
  self::assertStringContainsString("\$ok===false||!empty(\$wpdb->last_error)",$s);
  self::assertStringContainsString("if(\$ok!==1)return \$this->error('transition_conflict','State changed concurrently.',409)",$s);
 }
}