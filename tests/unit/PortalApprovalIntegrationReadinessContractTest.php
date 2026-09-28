<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class PortalApprovalIntegrationReadinessContractTest extends TestCase {
 public function testIntegrationReadinessIsStoredEvidenceOnly():void {
  $c=file_get_contents(__DIR__.'/../../includes/Portal/IntegrationReadinessReadModel.php');
  foreach(['SELECT id,provider,environment,connection_key,display_name,status,enabled,updated_at,config',"'connectivity_test_performed'=>false","'credentials_exposed'=>false","'external_execution_authorized'=>false"] as $v)self::assertStringContainsString($v,$c);
  foreach(['wp_remote_get','wp_remote_post','ConnectionTester'] as $v)self::assertStringNotContainsString($v,$c);
 }
 public function testPortalMakesIntegrationAuthorityBoundaryExplicit():void {
  $c=file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');
  foreach(['opening this page never performs a provider connectivity test','Stored successful tests','Needs attention','External execution authority</span><b>NO','Connector counts are UNKNOWN'] as $v)self::assertStringContainsString($v,$c);
 }
 public function testEvidenceClassificationDoesNotClaimLiveReadiness():void {
  $c=file_get_contents(__DIR__.'/../../includes/Portal/IntegrationReadinessReadModel.php');
  foreach (['TEST_SUCCESS_RECORDED','CONFIGURED_UNVERIFIED','live connectivity unverified','REVIEW_REQUIRED','UNKNOWN',"'external_execution_authorized'=>false"] as $v) self::assertStringContainsString($v,$c);
  $portal=file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');
  foreach (["$".'integrationReadiness[\'items\']','Review reason','Evidence</dt>','connector','integrations'] as $v) self::assertStringContainsString($v,$portal);
  self::assertSame(0,substr_count($portal,'SELECT id,provider,environment,connection_key,display_name,status,enabled,updated_at,config'));
 }
 public function testConnectorEvidenceFailsClosedForMissingAndUnconnectedStatus():void {
  require_once __DIR__.'/../../includes/Portal/IntegrationReadinessReadModel.php';
  $previous=$GLOBALS['wpdb']??null;
  $GLOBALS['wpdb']=new class {
   public string $prefix='wp_';
   public string $last_error='';
   public function get_results(string $sql, mixed $format):array {
    return [
     ['enabled'=>1,'status'=>'CONFIGURED','connection_key'=>'private','config'=>'{"_connection_test":{"ok":true,"checked_at":"2026-09-28 12:00:00","details":{"token":"never-display"}},"credential":"never-display"}'],
     ['enabled'=>1,'status'=>'CONFIGURED','config'=>'{}'],
     ['enabled'=>1,'status'=>'ERROR','config'=>'{"_connection_test":{"ok":true,"checked_at":"2026-09-28 12:00:00"}}'],
     ['enabled'=>0,'status'=>'CONFIGURED','config'=>'{"_connection_test":{"ok":true,"checked_at":"2026-09-28 12:00:00"}}'],
     ['enabled'=>1,'status'=>'CONNECTED','config'=>'{"_connection_test":{"ok":true,"checked_at":"2026-09-28 12:00:00"}}'],
    ];
   }
  };
  try {
   $snapshot=(new \DigiForge\Portal\IntegrationReadinessReadModel())->snapshot();
   self::assertSame(['total'=>5,'enabled'=>4,'configured'=>3,'stored_successful_tests'=>1,'attention'=>3],$snapshot['counts']);
   self::assertSame(['TEST_SUCCESS_RECORDED','CONFIGURED_UNVERIFIED','REVIEW_REQUIRED','DISABLED','REVIEW_REQUIRED'],array_column($snapshot['items'],'evidence_state'));
   self::assertSame('No valid successful stored connection test',$snapshot['items'][1]['attention_reason']);
   self::assertSame('2026-09-28 12:00:00',$snapshot['items'][0]['last_stored_test_at']);
   self::assertArrayNotHasKey('config',$snapshot['items'][0]);
   self::assertArrayNotHasKey('connection_key',$snapshot['items'][0]);
   self::assertStringNotContainsString('never-display',json_encode($snapshot));
   self::assertFalse($snapshot['external_execution_authorized']);
   foreach ($snapshot['items'] as $item) self::assertFalse($item['external_execution_authorized']);
  } finally { $GLOBALS['wpdb']=$previous; }
 }
 public function testDatabaseFailureReportsUnknownInsteadOfZeroConnectors():void {
  require_once __DIR__.'/../../includes/Portal/IntegrationReadinessReadModel.php';
  $previous=$GLOBALS['wpdb']??null;
  $GLOBALS['wpdb']=new class {
   public string $prefix='wp_';
   public string $last_error='fixture failure';
   public function get_results(string $sql,mixed $format):array{return [];}
  };
  try {
   $snapshot=(new \DigiForge\Portal\IntegrationReadinessReadModel())->snapshot();
   self::assertSame('UNAVAILABLE',$snapshot['query_state']);
   self::assertNull($snapshot['counts']);
   self::assertSame([],$snapshot['items']);
   self::assertFalse($snapshot['external_execution_authorized']);
  } finally { $GLOBALS['wpdb']=$previous; }
 }
 public function testApprovalAggregationNeverInfersAuthority():void {
  $c=file_get_contents(__DIR__.'/../../includes/Portal/U3ApprovalInbox.php');
  foreach(['Downstream pending decisions','Approval authority in this aggregate','External execution authority','NO INFERRED APPROVAL','No approval is inferred from this aggregation view'] as $v)self::assertStringContainsString($v,$c);
 }
}
