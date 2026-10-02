<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class EtsyReconciliationReadStaleErrorIsolationContractTest extends TestCase{
 public function testBothReconciliationQueriesResetStaleDatabaseErrors():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyReconciliationOperatorReadModel.php');
  self::assertSame(2,substr_count($s,"\$wpdb->last_error=''"));
  self::assertStringContainsString("\$row=\$wpdb->get_row",$s);
  self::assertStringContainsString("\$rows=\$wpdb->get_results",$s);
  self::assertGreaterThanOrEqual(2,substr_count($s,"\$queryState='UNAVAILABLE'"));
  self::assertStringContainsString("\$queryState='NOT_FOUND'",$s);
  self::assertGreaterThanOrEqual(2,substr_count($s,"\$queryState='AVAILABLE'"));
 }
}
