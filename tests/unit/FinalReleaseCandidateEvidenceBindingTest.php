<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class FinalReleaseCandidateEvidenceBindingTest extends TestCase {
 public function testPackagingBindsArtifactToExactCandidateAndLockedManifest():void {
  $s=file_get_contents(__DIR__.'/../../bin/package-plugin.sh');$w=file_get_contents(__DIR__.'/../../.github/workflows/digiforge-foundation-audit.yml');$g=file_get_contents(__DIR__.'/../../docs/releases/RELEASE_CANDIDATE_EVIDENCE_GATE.md');
  foreach(['plugin_version','database_schema_version','commit_sha','sha256','external_actions_performed','production_activation_authorized'] as $field)self::assertStringContainsString($field,$s);
  self::assertStringContainsString('sha256sum --check digiforge.zip.sha256',$s);
  self::assertStringContainsString('github.event.pull_request.head.sha || github.sha',$w);
  self::assertStringContainsString('DIGIFORGE_EXPECTED_CANDIDATE_SHA',$w);
  self::assertStringContainsString('getenv("DIGIFORGE_EXPECTED_CANDIDATE_SHA")',$w);
  self::assertStringContainsString('UNKNOWN is not success',$g);
  self::assertStringContainsString('Missing evidence is REVIEW_REQUIRED',$g);
  self::assertStringContainsString('No release-readiness result grants Etsy publish or POD production authority',$g);
 }
 public function testFinalMetadataRemainsV10103CoreSchema23():void {
  $p=file_get_contents(__DIR__.'/../../digiforge.php');self::assertStringContainsString("const DIGIFORGE_VERSION = '1.0.103';",$p);self::assertStringContainsString("const DIGIFORGE_DB_VERSION = '23';",$p);
 }
}
