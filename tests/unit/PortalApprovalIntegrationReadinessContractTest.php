<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class PortalApprovalIntegrationReadinessContractTest extends TestCase {
 public function testIntegrationReadinessIsStoredEvidenceOnly():void {
  $c=file_get_contents(__DIR__.'/../../includes/Portal/IntegrationReadinessReadModel.php');
  foreach(['SELECT id,provider,environment,connection_key,display_name,status,enabled,updated_at',"'connectivity_test_performed'=>false","'credentials_exposed'=>false","'external_execution_authorized'=>false"] as $v)self::assertStringContainsString($v,$c);
  foreach(['wp_remote_get','wp_remote_post','ConnectionTester'] as $v)self::assertStringNotContainsString($v,$c);
 }
 public function testPortalMakesIntegrationAuthorityBoundaryExplicit():void {
  $c=file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');
  foreach(['opening this page never performs a provider connectivity test','Connected evidence','Needs attention','External execution authority</span><b>NO'] as $v)self::assertStringContainsString($v,$c);
 }
 public function testEvidenceClassificationDoesNotClaimLiveReadiness():void {
  $c=file_get_contents(__DIR__.'/../../includes/Portal/IntegrationReadinessReadModel.php');
  foreach (['CONNECTED_RECORDED','live connectivity unverified','No status recorded','REVIEW_REQUIRED','UNKNOWN',"'external_execution_authorized'=>false"] as $v) self::assertStringContainsString($v,$c);
  $portal=file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');
  foreach (["$".'integrationReadiness[\'items\']','Review reason','Evidence</dt>','connector','integrations'] as $v) self::assertStringContainsString($v,$portal);
  self::assertSame(0,substr_count($portal,'SELECT id,provider,environment,connection_key,display_name,status,enabled,updated_at'));
 }
 public function testConnectorEvidenceFailsClosedForMissingAndUnconnectedStatus():void {
  require_once __DIR__.'/../../includes/Portal/IntegrationReadinessReadModel.php';
  $previous=$GLOBALS['wpdb']??null;
  $GLOBALS['wpdb']=new class {
   public string $prefix='wp_';
   public function get_results(string $sql, mixed $format):array {
    return [
     ['enabled'=>1,'status'=>'CONNECTED'],
     ['enabled'=>1,'status'=>''],
     ['enabled'=>1,'status'=>'DISCONNECTED'],
     ['enabled'=>0,'status'=>'CONNECTED'],
    ];
   }
  };
  try {
   $snapshot=(new \DigiForge\Portal\IntegrationReadinessReadModel())->snapshot();
   self::assertSame(['total'=>4,'enabled'=>3,'connected'=>2,'attention'=>2],$snapshot['counts']);
   self::assertSame(['CONNECTED_RECORDED','UNKNOWN','REVIEW_REQUIRED','DISABLED'],array_column($snapshot['items'],'evidence_state'));
   self::assertSame('No status recorded',$snapshot['items'][1]['attention_reason']);
   self::assertFalse($snapshot['external_execution_authorized']);
   foreach ($snapshot['items'] as $item) self::assertFalse($item['external_execution_authorized']);
  } finally { $GLOBALS['wpdb']=$previous; }
 }
 public function testApprovalAggregationNeverInfersAuthority():void {
  $c=file_get_contents(__DIR__.'/../../includes/Portal/U3ApprovalInbox.php');
  foreach(['Downstream pending decisions','Approval authority in this aggregate','External execution authority','NO INFERRED APPROVAL','No approval is inferred from this aggregation view'] as $v)self::assertStringContainsString($v,$c);
 }
}
