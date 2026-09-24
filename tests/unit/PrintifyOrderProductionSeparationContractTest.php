<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class PrintifyOrderProductionSeparationContractTest extends TestCase
{
 public function testProviderOrderAndProductionUseDistinctAuthorizationScopes():void
 {
  $issue=(string)file_get_contents(dirname(__DIR__,2).'/includes/POD/ExecutionAuthorization.php');
  $verify=(string)file_get_contents(dirname(__DIR__,2).'/includes/POD/ExecutionAuthorizationVerifier.php');
  foreach(['PROVIDER_ORDER_SUBMIT','PROVIDER_PRODUCTION_AUTHORIZE'] as $scope){self::assertStringContainsString($scope,$issue);self::assertStringContainsString($scope,$verify);}
  self::assertStringContainsString("(\$a['action']??'')!==\$requiredAction",$verify);
 }
 public function testPrintifyPlanAuthorizesNeitherOrderNorProduction():void
 {
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/POD/PrintifyControlledExecutionPlan.php');
  self::assertStringContainsString("'order_creation_authorized'=>false",$s);
  self::assertStringContainsString("'production_authorized'=>false",$s);
  self::assertStringNotContainsString("'order_creation_authorized'=>true",$s);
  self::assertStringNotContainsString("'production_authorized'=>true",$s);
 }
}
