<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class AiPromptVersionUniquenessEvidenceContractTest extends TestCase{
 public function testUniquenessReadSeparatesDatabaseFailureFromAvailableVersionLabel():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/AI/Repository.php');
  self::assertStringContainsString("\$wpdb->last_error = ''",$s);
  self::assertStringContainsString("'evidence_unavailable'",$s);
  self::assertStringContainsString('Prompt version uniqueness evidence could not be read.',$s);
  self::assertStringContainsString("'immutable_version'",$s);
  self::assertStringContainsString('Prompt versions are immutable; create a new version label.',$s);
 }
}
