<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class OperationalExceptionEvidenceContractTest extends TestCase {
 public function testExceptionProjectionIsBoundedAndNonAuthorizing():void {
  $c=file_get_contents(__DIR__.'/../../includes/Operations/OperationalExceptionReadModel.php');
  foreach(['min(100,','fulfillment_intents','finance_ledger','tax_classifications',"'retry_permitted']=false","'external_execution_authorized']=false","'money_movement_authorized']=false","'tax_filing_authorized']=false"] as $v) self::assertStringContainsString($v,$c);
  self::assertStringNotContainsString('input_payload',$c);
  self::assertStringNotContainsString('evidence longtext',$c);
 }
 public function testPortalMakesExceptionEvidenceNonExecutable():void {
  $c=file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');
  foreach(['Fulfillment exceptions requiring evidence review','Finance / GST exceptions','Money movement authority</span><b>NO','Tax filing authority</span><b>NO','Exception evidence never files GST/tax, changes tax classification, issues refunds, or moves money.'] as $v) self::assertStringContainsString($v,$c);
 }
 public function testFulfillmentTotalIsIndependentOfTheBoundedRows():void {
  require_once __DIR__.'/../../includes/Database/Tables.php';
  require_once __DIR__.'/../../includes/Operations/OperationalExceptionReadModel.php';
  $previous=$GLOBALS['wpdb']??null;
  $GLOBALS['wpdb']=new class {
   public string $prefix='wp_';
   public string $countQuery='';
   public function prepare(string $query,int $limit):string{return str_replace('%d',(string)$limit,$query);}
   public function get_results(string $query,mixed $format):array{return [];}
   public function get_var(string $query):int{$this->countQuery=$query;return 73;}
  };
  try {
   $result=(new \DigiForge\Operations\OperationalExceptionReadModel())->snapshot(50);
   self::assertSame([],$result['fulfillment']);
   self::assertSame(73,$result['fulfillment_total']);
   self::assertStringContainsString("state IN ('BLOCKED','FAILED','HUMAN_REVIEW','UNKNOWN')",$GLOBALS['wpdb']->countQuery);
   self::assertStringNotContainsString('LIMIT',$GLOBALS['wpdb']->countQuery);
   self::assertFalse($result['external_execution_authorized']);
   $portal=file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');
   self::assertStringContainsString("$"."exceptions['fulfillment_total']",$portal);
   self::assertStringContainsString('The most recent 25 are displayed',$portal);
   self::assertStringContainsString('No orders found in the recent window.',$portal);
  } finally { $GLOBALS['wpdb']=$previous; }
 }
}
