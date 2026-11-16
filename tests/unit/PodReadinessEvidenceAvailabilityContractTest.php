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
        self::assertStringContainsString('(new PersonalizationCurrentness($this->personalizationContext))->forPlan(', $source);
        self::assertStringContainsString('if(is_wp_error($personalization))return $personalization;', $source);
        $current=(string)file_get_contents(__DIR__.'/../../includes/Orders/PersonalizationCurrentness.php');
        self::assertStringContainsString("\$wpdb->last_error='';\$rows=\$wpdb->get_results", $current);
        self::assertStringContainsString("self::error('evidence_unavailable'", $current);
        self::assertStringContainsString("!is_array(\$rows)||\$wpdb->last_error!==''||count(\$rows)>100", $current);
        self::assertStringContainsString('if(is_wp_error($rows))return $rows;', $current);
    }

 public function testReadinessCountClearsStaleDatabaseErrorsBeforeEachCount():void
 {
  $source=(string)file_get_contents(__DIR__.'/../../includes/Orders/Repository.php');
  $start=strpos($source,'private function readinessCount(string $sql): ?int');
  self::assertNotFalse($start);
  $end=strpos($source,'public function list(',$start);
  self::assertNotFalse($end);
  $method=substr($source,$start,$end-$start);
  // Only the validated context supplies the suffix; context/query failures must stay unavailable, not zero.
  self::assertStringContainsString('$suffix=$this->contextReadSuffix();if(is_wp_error($suffix))return null;', $method);
  self::assertStringContainsString("\$wpdb->last_error='';\n        \$value=\$wpdb->get_var(\$sql.\$suffix);", $method);
  self::assertStringContainsString('return is_numeric($value)&&empty($wpdb->last_error)?(int)$value:null;', $method);
  self::assertStringContainsString("return \$this->personalizationContext===null?'':\$this->personalizationContext->currentReadSuffix();", $source);
 }

}
