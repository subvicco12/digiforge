<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class PrintifyConnectorNativeHostTest extends TestCase
{
 public function testNativeAdapterRequiresProductionAuthorizationAndSingleVaultBoundary():void {
  $source=file_get_contents(dirname(__DIR__,2).'/includes/Connector/Printify/NativeHost.php');
  self::assertStringContainsString('ShopAccess::authorize',$source);
  self::assertStringContainsString('PrintifyScopedCredentialRetriever',$source);
  self::assertStringNotContainsString('CredentialVault::decrypt',$source);
  self::assertStringNotContainsString('manage_options',$source);
 }
 public function testBudgetUsesDurableAtomicStorageAndNeverDeletesIntentHistory():void {
  $source=file_get_contents(dirname(__DIR__,2).'/includes/Connector/Printify/ReadBudget.php');
  foreach(['FOR UPDATE','START TRANSACTION','COMMIT','INNODB','wp_get_session_token','intentId'] as $required) self::assertStringContainsString($required,$source);
  self::assertStringNotContainsString('delete_option',$source);
 }
 public function testBehavioralBudgetScenarios():void {
  foreach(['duplicate','minute','cross_session','five','noninnodb','index','nested','commit','rollback','serialization','host_locked','host_missing','host_wrong_scope','reference_identity','audit'] as $scenario){
   $command=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/fixtures/printify-read-budget.php').' '.escapeshellarg($scenario);
   $result=json_decode((string)shell_exec($command),true,512,JSON_THROW_ON_ERROR);
   self::assertNotEmpty($result,$scenario);
   foreach($result as $passed)self::assertTrue($passed,$scenario);
  }
 }
}
