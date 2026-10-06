<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class GovernedAnalyticsSnapshotContractTest extends TestCase
{
 public function testRepositoryBoundsAnalyticsDimensionsAndKeepsSnapshotsLocalImmutable():void
 {
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Finance/Repository.php');
  foreach(['product','listing','order','provider','shop','portfolio'] as $dimension)self::assertStringContainsString("'".$dimension."'",$s);
  self::assertStringContainsString("Portfolio analytics dimension_id must be zero.",$s);
  self::assertStringContainsString("Analytics dimension requires a positive local identity.",$s);
  self::assertStringContainsString("Analytics metrics must not be empty.",$s);\n  self::assertStringContainsString("validateAnalyticsDimension(\$dimension,\$dimensionId,\$environment)",$s);\n  self::assertStringContainsString("'provider'=>Tables::pod_mappings()",$s);\n  self::assertStringContainsString("'shop'=>Tables::stores()",$s);\n  self::assertStringContainsString("Analytics dimension identity was not found.",$s);\n  self::assertStringContainsString("Analytics dimension environment must match.",$s);
  self::assertStringContainsString("hash('sha256',\$metricsJson)",$s);
  self::assertStringContainsString("Tables::analytics_snapshots()",$s);
  self::assertStringNotContainsString('wp_remote_',$s);
 }
 public function testAnalyticsCreationUsesExistingFinanceMutationGuards():void
 {
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/REST/FinanceController.php');
  self::assertStringContainsString("'/finance/analytics'",$s);
  self::assertStringContainsString("'methods'=>'POST'",$s);
  self::assertStringContainsString("'permission_callback'=>[\$this,'canManage']",$s);
  self::assertStringContainsString("finance_analytics_create",$s);
  self::assertStringContainsString("createAnalyticsSnapshot",$s);
  self::assertStringContainsString("return \$this->mutate(\$request",$s);
 }
}
