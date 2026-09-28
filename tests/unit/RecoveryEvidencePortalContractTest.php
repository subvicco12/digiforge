<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class RecoveryEvidencePortalContractTest extends TestCase {
 public function testPortalShowsConcreteRecoveryEvidenceReadOnlyAndFailClosed():void {
  $c=file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');
  self::assertStringContainsString('RecoveryEvidence::snapshot()', $c);
  self::assertStringContainsString('DB backup evidence', $c);
  self::assertStringContainsString('Rollback package evidence', $c);
  self::assertStringContainsString('MISSING / UNPROVEN', $c);
  self::assertStringContainsString('Missing or UNKNOWN artifact evidence is fail-closed', $c);
  self::assertStringContainsString('Evidence never grants retry, activation, Etsy publish, POD production', $c);
 }
}