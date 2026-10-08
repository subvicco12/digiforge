<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class PodAuthorizationQueryOutcomeContractTest extends TestCase {
 public function testAllAuthorizationRowReadsCheckFalseQueryOutcomes():void {
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/POD/ProductionAuthorizationRepository.php');
  self::assertSame(5,substr_count($s,'$queryResult===false'));
  self::assertSame(5,substr_count($s,'$wpdb->last_result[0]'));
  self::assertSame(5,substr_count($s,'$wpdb->flush();'));
 }
}
