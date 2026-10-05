<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class FinalBusinessAttributionEvidenceContractTest extends TestCase{
 public function testAttributionCannotCollapseDatabaseFailureToMissing():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/POD/BusinessAttributionReadModel.php');
  self::assertStringContainsString('array|WP_Error|null',$s);
  self::assertStringContainsString("\$wpdb->last_error='';\$rows=\$wpdb->get_results",$s);
  self::assertStringContainsString('pod_business_attribution_evidence_unavailable',$s);
  self::assertStringContainsString("'external_execution_authorized'=>false",$s);
  self::assertStringNotContainsString("ARRAY_A)?:[]",$s);
 }
}
