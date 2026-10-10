<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class StageFFinancePortalConvergenceContractTest extends TestCase
{
 public function testFinanceProjectionIsBoundedHashVerifiedAndNonAuthorizing():void{
  $r=(string)file_get_contents(__DIR__.'/../../includes/Finance/OperationsReadModel.php');
  foreach(['min(50,','metrics_valid',"'money_movement_authorized'=>false","'tax_filing_authorized'=>false","'external_execution_authorized'=>false"] as $v)self::assertStringContainsString($v,$r);
  self::assertStringNotContainsString('wp_remote_',$r);
 }
 public function testPeriodHashUsesFullWriterCanonicalContractWhileAnalyticsUsesMetricsOnly():void{
  $r=(string)file_get_contents(__DIR__.'/../../includes/Finance/OperationsReadModel.php');
  $writer=(string)file_get_contents(__DIR__.'/../../includes/Finance/Repository.php');
  foreach(["'environment'=>", "'period_start'=>", "'period_end'=>", "'base_currency'=>", "'metrics'=>", "'calculation_version'=>"] as $field)self::assertStringContainsString($field,$r);
  self::assertStringContainsString('Validator::hash($canonical)',$r);
  self::assertStringContainsString('array_map($normalizePeriod,$periods)',$r);
  self::assertStringContainsString('array_map($normalizeAnalytics,$analytics)',$r);
  self::assertStringContainsString('metrics_hash\'=>Validator::hash($canonical)',$writer);
  self::assertStringContainsString("'metrics_hash'=>hash('sha256',\$metricsJson)",$writer);
 }
 public function testEmptyFinanceLedgerCannotProduceCertifiedZeroProfit():void{
  $writer=(string)file_get_contents(__DIR__.'/../../includes/Finance/Repository.php');
  self::assertStringContainsString("if(\$rows===[])return \$this->error('ledger_evidence_missing'",$writer);
  self::assertStringContainsString("'No ledger entries exist for this period; a zero-profit result would be unsupported.'",$writer);
 }
 public function testPortalSurfacesProfitabilityWithoutFinancialAuthority():void{
  $p=(string)file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');
  foreach(['Profitability periods','Net operating profit','Margin','HASH VERIFIED','Profitability evidence cannot move money'] as $v)self::assertStringContainsString($v,$p);
 }
 public function testAnalyticsPortalFailsClosedAndVerifiesSnapshotEvidence():void{
  $p=(string)file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');
  foreach(["\$kpi['query_state']","\$denominators['query_state']","Operational analytics evidence unavailable","Analytics snapshot evidence unavailable","No empty history is inferred","HASH VERIFIED","Analytics snapshots cannot authorize external execution"] as $v)self::assertStringContainsString($v,$p);
 }
}
