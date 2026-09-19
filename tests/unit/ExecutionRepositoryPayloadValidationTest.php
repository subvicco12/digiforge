<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ExecutionRepositoryPayloadValidationTest extends TestCase{
 public function testRepositoriesValidatePayloadBeforeDatabaseWrite():void{
  $root=dirname(__DIR__,2).'/includes/POD/';
  $receipt=(string)file_get_contents($root.'ExecutionReceiptRepository.php');
  self::assertStringContainsString('digiforge_receipt_payload',$receipt);
  self::assertStringContainsString("\$actor<1",$receipt);
  self::assertStringContainsString("\$executedAt<1",$receipt);
  $failure=(string)file_get_contents($root.'ExecutionFailureRepository.php');
  self::assertStringContainsString('digiforge_failure_payload',$failure);
  self::assertStringContainsString('sanitize_key',$failure);
  self::assertStringContainsString("\$recordedAt<1",$failure);
 }
}
