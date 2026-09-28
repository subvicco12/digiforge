<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ReconciliationOperatorDrilldownContractTest extends TestCase {
 public function testEtsyProjectionIsBoundedReadOnlyAndNonAuthorizing():void {
  $c=file_get_contents(__DIR__.'/../../includes/Listings/EtsyReconciliationOperatorReadModel.php');
  foreach(["min(100,","UNKNOWN","RECONCILIATION_REQUIRED","RECONCILE_BEFORE_ANY_RETRY","['read_only']=true","['retry_permitted']=false","['external_execution_authorized']=false"] as $v) self::assertStringContainsString($v,$c);
  self::assertStringNotContainsString('reconciliation_evidence',$c);
 }
 public function testAuditSurfaceOnlyNavigatesToGovernedWorkflows():void {
  $c=file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');
  foreach(['Audit / Reconciliation','Open Listings & Etsy','Open POD workflow','This view cannot retry, publish, produce, fulfill, refund, change tax state, or move money.'] as $v) self::assertStringContainsString($v,$c);
  self::assertStringNotContainsString('Retry operation',$c);
 }
 public function testExactEtsyFocusRemainsReadOnlyAndMissingIdsFailClosed():void {
  require_once __DIR__.'/../../includes/Listings/EtsyReconciliationOperatorReadModel.php';
  $previous=$GLOBALS['wpdb']??null;
  $db=new class {
   public string $prefix='wp_';
   public array $queries=[];
   public function prepare(string $sql,int $id):string {return str_replace('%d',(string)$id,$sql);}
   public function get_row(string $sql,mixed $format):?array {
    $this->queries[]=$sql;
    return str_contains($sql,'id=7 ') ? ['id'=>7,'state'=>'UNKNOWN'] : null;
   }
  };
  $GLOBALS['wpdb']=$db;
  try {
   $model=new \DigiForge\Listings\EtsyReconciliationOperatorReadModel();
   self::assertNull($model->byId(0));
   self::assertSame([], $db->queries);
   $row=$model->byId(7);
   self::assertSame('RECONCILE_BEFORE_ANY_RETRY',$row['operator_action']);
   self::assertFalse($row['retry_permitted']);
   self::assertFalse($row['external_execution_authorized']);
   self::assertNull($model->byId(9));
   self::assertStringContainsString("state IN ('UNKNOWN','RECONCILIATION_REQUIRED')",$db->queries[0]);
  } finally { $GLOBALS['wpdb']=$previous; }
 }
 public function testAlertAndEtsyFocusOnlyUseNumericIdentifiers():void {
  $c=file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');
  foreach (['df_alert_id','df_etsy_operation','Requested operational alert is unavailable or closed','Requested Etsy reconciliation evidence is unavailable','df-etsy-operation-','df-alert-'] as $v) self::assertStringContainsString($v,$c);
  self::assertStringContainsString("(string)$"."alert['source_type']==='etsy_operation'",$c);
  self::assertStringContainsString("$".'wpdb->prepare(', $c);
 }
}
