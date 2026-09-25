<?php
declare(strict_types=1);
namespace DigiForge\Tests;
use PHPUnit\Framework\TestCase;
final class ReadModelSafetyConvergenceTest extends TestCase
{
 /** @dataProvider readOnlyProjectionProvider */
 public function testReadOnlyProjectionsDeclareNoExternalActions(string $path):void{
  $source=(string)file_get_contents(dirname(__DIR__,2).'/'.$path);
  self::assertStringContainsString("'external_actions_performed'=>false",str_replace(' ','',$source),$path);
 }
 public static function readOnlyProjectionProvider():array{
  return [
   ['includes/DigitalFactory/QaAdminSummary.php'],
   ['includes/DigitalFactory/OperationalAdminSummary.php'],
   ['includes/Queue/RecoveryAdminSummary.php'],
  ];
 }
 public function testDigitalReadModelsNeverInferReadiness():void{
  foreach(['includes/DigitalFactory/QaAdminSummary.php','includes/DigitalFactory/OperationalAdminSummary.php'] as $path){
   $source=str_replace(' ','',(string)file_get_contents(dirname(__DIR__,2).'/'.$path));
   self::assertStringContainsString("'readiness_inferred'=>false",$source,$path);
  }
 }
 public function testRecoveryQueryFailureRequiresRecovery():void{
  $source=str_replace(' ','',(string)file_get_contents(dirname(__DIR__,2).'/includes/Queue/RecoveryAdminSummary.php'));
  self::assertStringContainsString("'query_ok'=>false",$source);
  self::assertStringContainsString("'recovery_required'=>true",$source);
 }
}
