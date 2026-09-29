<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class MainReleaseAuditTriggerContractTest extends TestCase {
 public function testMainPushRemainsAnAuditedReleaseArtifactTrigger():void {
  $w=file_get_contents(__DIR__.'/../../.github/workflows/digiforge-foundation-audit.yml');
  self::assertStringContainsString("push:\n    branches:\n      - main",$w);
  self::assertStringContainsString('github.event.pull_request.head.sha || github.sha',$w);
  self::assertStringContainsString('Upload audited package',$w);
  self::assertStringContainsString('digiforge.release-manifest.json',$w);
  self::assertStringContainsString('digiforge.zip.sha256',$w);
 }
}
