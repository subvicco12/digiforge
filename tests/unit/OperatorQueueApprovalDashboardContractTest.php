<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class OperatorQueueApprovalDashboardContractTest extends TestCase {
 public function testQueueDrilldownIsBoundedAndNonAuthorizing():void {
  $c=file_get_contents(__DIR__.'/../../includes/Queue/OperatorQueueReadModel.php');
  foreach(["FAILED","BLOCKED","HUMAN_REVIEW","DEAD_LETTER","min(100,","['read_only']=true","['retry_permitted']=false","['external_execution_authorized']=false"] as $v) self::assertStringContainsString($v,$c);
  self::assertStringNotContainsString('payload',$c);
 }
 public function testPortalKeepsAggregationAndShopContextReadOnly():void {
  $p=file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');$a=file_get_contents(__DIR__.'/../../includes/Portal/U3ApprovalInbox.php');
  self::assertStringContainsString('Dashboard counts remain authoritative global counts unless explicitly labeled shop-scoped.',$p);
  self::assertStringContainsString('<td>NO</td><td>NO</td>',$p);
  self::assertStringContainsString('NO INFERRED APPROVAL',$a);
  self::assertStringContainsString('Every decision must be made in its dedicated governed workflow',$a);
 }
 public function testExactQueueAttentionFailsClosedForMissingAndNonAttentionIds():void {
  require_once __DIR__.'/../../includes/Queue/OperatorQueueReadModel.php';
  $previous=$GLOBALS['wpdb']??null;
  $db=new class {
   public string $prefix='wp_';
   public array $queries=[];
   public function prepare(string $sql,int $id):string {return str_replace('%d',(string)$id,$sql);}
   public function get_row(string $sql,mixed $format):?array {
    $this->queries[]=$sql;
    return str_contains($sql,'id=7 ') ? ['id'=>7,'state'=>'HUMAN_REVIEW'] : null;
   }
  };
  $GLOBALS['wpdb']=$db;
  try {
   $model=new \DigiForge\Queue\OperatorQueueReadModel();
   self::assertNull($model->attentionById(0));
   self::assertSame([], $db->queries);
   $item=$model->attentionById(7);
   self::assertTrue($item['read_only']);
   self::assertFalse($item['retry_permitted']);
   self::assertFalse($item['external_execution_authorized']);
   self::assertNull($model->attentionById(8));
   self::assertStringContainsString("state IN ('FAILED','BLOCKED','HUMAN_REVIEW','DEAD_LETTER')",$db->queries[0]);
  } finally { $GLOBALS['wpdb']=$previous; }
 }
 public function testProviderUnknownFocusUsesStoredNumericEvidenceIdentity():void {
  $model=file_get_contents(__DIR__.'/../../includes/POD/PrintifyUnknownOperatorReadModel.php');
  $portal=file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');
  foreach (['SELECT id,authorization_hash',"'id'=>(int)$"."row['id']",'df_printify_unknown','Requested provider UNKNOWN evidence is unavailable','df_job_id','Requested queue attention record is unavailable'] as $value) {
   self::assertStringContainsString($value,$model.$portal);
  }
  self::assertStringContainsString('Reconcile before any retry',$portal);
 }
}
