<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class FinanceTaxReviewDbEvidenceContractTest extends TestCase{
 public function testTaxReviewSeparatesPersistenceFailureFromConflict():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Finance/Repository.php');
  self::assertStringContainsString("'tax_review_persistence_failed'",$s);
  self::assertStringContainsString("\$ok===false||!empty(\$wpdb->last_error)",$s);
  self::assertStringContainsString("'review_conflict','Tax review changed concurrently.'",$s);
 }
}