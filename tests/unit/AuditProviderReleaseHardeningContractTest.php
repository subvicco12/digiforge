<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class AuditProviderReleaseHardeningContractTest extends TestCase {
 public function testAuditCorrelationIsBoundedAndNeverExposesContext():void {
  $c=file_get_contents(__DIR__.'/../../includes/Operations/AuditCorrelationReadModel.php');
  foreach(['min(100,','SELECT id,event_type,actor_id,object_type,object_id,created_at',"'context_exposed']=false","'retry_permitted']=false","'external_execution_authorized']=false"] as $v)self::assertStringContainsString($v,$c);
  self::assertStringNotContainsString('SELECT *',$c);
 }
 public function testProviderStatusRequiresReconciliationAndNeverAuthorizesProduction():void {
  $c=file_get_contents(__DIR__.'/../../includes/POD/ProviderStatusReadModel.php');
  foreach(["'reconcile_before_retry'=>true","'provider_execution_authorized'=>false","'production_authorized'=>false","'retry_permitted'=>false"] as $v)self::assertStringContainsString($v,$c);
 }
 public function testPortalStatesProviderAndAuditSafetyBoundaries():void {
  $c=file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');
  foreach(['UNKNOWN provider outcomes require reconciliation before any retry','Provider execution authority','Bounded non-secret audit identities available','Audit context payloads are excluded'] as $v)self::assertStringContainsString($v,$c);
 }
}