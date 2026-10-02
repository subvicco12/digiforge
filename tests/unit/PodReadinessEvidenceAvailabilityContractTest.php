<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class PodReadinessEvidenceAvailabilityContractTest extends TestCase {
 public function testReadinessCountFailuresAreUnavailableNotZero():void {
  $c=file_get_contents(__DIR__.'/../../includes/POD/Repository.php');
  $start=strpos($c,'public function readiness(int $mappingId)');
  $end=strpos($c,'public function list(',$start);
  $method=substr($c,$start,$end-$start);
  self::assertStringNotContainsString('(int)$wpdb->get_var',$method);
  self::assertStringContainsString('pod_readiness_evidence_unavailable',$method);
  self::assertStringContainsString('POD readiness evidence is unavailable; readiness is blocked.',$method);
  self::assertStringContainsString('!empty($wpdb->last_error)||!is_numeric($raw)',$method);
  self::assertGreaterThanOrEqual(4,substr_count($method,'$count('));
 }
 public function testEconomicEvidenceReadAlsoChecksDatabaseFailure():void {
  $c=file_get_contents(__DIR__.'/../../includes/POD/Repository.php');
  $start=strpos($c,'public function readiness(int $mappingId)');
  $end=strpos($c,'public function list(',$start);
  $method=substr($c,$start,$end-$start);
  self::assertStringContainsString('pod_readiness_evidence_unavailable',$method);
  self::assertGreaterThanOrEqual(2,substr_count($method,'!empty($wpdb->last_error)'));
 }
    public function testFulfillmentPlanPersonalizationEvidenceQueryFailsClosed(): void
    {
        $source=(string)file_get_contents(__DIR__.'/../../includes/Orders/Repository.php');
        self::assertStringContainsString("\$wpdb->last_error='';\$rows=\$wpdb->get_results", $source);
        self::assertStringContainsString("personalization_evidence_unavailable", $source);
        self::assertStringContainsString("!is_array(\$rows)||!empty(\$wpdb->last_error)", $source);
    }

 public function testReadinessCountClearsStaleDatabaseErrorsBeforeEachCount():void{$s=(string)file_get_contents(__DIR__.'/../../includes/Orders/Repository.php');self::assertStringContainsString("\$wpdb->last_error='';\n        \$value=\$wpdb->get_var(\$sql);",$s);}
}
