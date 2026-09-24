<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class EtsyPublishAuthorizationGateContractTest extends TestCase
{
 public function testGateRevalidatesDurableScopeApprovalReadinessAndRuntimeLocks():void
 {
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyPublishAuthorizationGate.php');
  foreach(['ETSY_PREPUBLISH_EVIDENCE_READY','Tables::etsy_intents()','Tables::etsy_draft_packages()','Tables::listings()','APPROVED_INTENT',"['approved_by']", "['approved_at']", 'Repository())->readiness','hash_equals($currentHash,$packageHash)','hash_equals($currentHash,$readinessHash)','EtsyExecutionPolicy::OP_PUBLISH','Settings::safety_locked()',"Settings::get('activation_authorized',false)","Settings::get('automation_armed',false)","Settings::get('stop_all',true)!==false","Settings::is_enabled('etsy_publish')","'durable_approval_verified'=>true","'network_request_permitted'=>false","'etsy_api_invoked'=>false","'external_execution_performed'=>false"] as $n)self::assertStringContainsString($n,$s);
 }
 public function testGateDoesNotTrustCallerBooleanOrExecuteOrChangeControls():void
 {
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyPublishAuthorizationGate.php');
  foreach(['$humanApproved','wp_remote_','curl_exec(','CredentialVault','EtsyScopedCredentialRetriever','Settings::set(','update_option(','wp_schedule_','as_schedule_'] as $n)self::assertStringNotContainsString($n,$s);
 }
}
