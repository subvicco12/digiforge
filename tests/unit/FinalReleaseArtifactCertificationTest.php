<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FinalReleaseArtifactCertificationTest extends TestCase
{
    public function testPackageBuildProducesChecksumAndReleaseManifest(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/package-plugin.sh');

        foreach ([
            'digiforge.zip.sha256',
            'digiforge.release-manifest.json',
            'digiforge-release-manifest-v1',
            'plugin_version',
            'database_schema_version',
            'commit_sha',
            'sha256',
            'file_count',
            'external_actions_performed',
            'production_activation_authorized',
            'sha256sum --check'
        ] as $needle) {
            self::assertStringContainsString($needle, $script);
        }
    }

    public function testAuditVerifiesAndUploadsExactReleaseEvidence(): void
    {
        $workflow = (string) file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/digiforge-foundation-audit.yml');

        foreach ([
            'Build reproducible plugin package',
            'Verify package boundaries',
            'digiforge.release-manifest.json',
            'digiforge.zip.sha256',
            'Upload audited package',
            'github.event.pull_request.head.sha || github.sha',
            'DIGIFORGE_EXPECTED_CANDIDATE_SHA'
        ] as $needle) {
            self::assertStringContainsString($needle, $workflow);
        }
    }

    public function testReleaseDocumentationMatchesCurrentPluginMetadata(): void
    {
        $plugin = (string) file_get_contents(dirname(__DIR__, 2) . '/digiforge.php');
        $readiness = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/final-readiness-completion.md');

        self::assertStringContainsString("const DIGIFORGE_VERSION = '1.0.103';", $plugin);
        self::assertStringContainsString("const DIGIFORGE_DB_VERSION = '23';", $plugin);
        self::assertStringContainsString('Plugin release: 1.0.103.', $readiness);
        self::assertStringContainsString('Core plugin database version: v23; additive catalog acceptance schema: v24.', $readiness);
        self::assertStringContainsString('READY_LOCKED', $readiness);
    }

 public function testReleaseCandidateEvidenceGateIsFailClosed():void{
  $gate=file_get_contents(__DIR__.'/../../docs/releases/RELEASE_CANDIDATE_EVIDENCE_GATE.md');self::assertIsString($gate);self::assertStringContainsString('UNKNOWN is not success',$gate);self::assertStringContainsString('No release-readiness result grants Etsy publish or POD production authority',$gate);self::assertStringContainsString('production_activation_authorized=false',$gate);
 }

 public function testPullRequestAuditPackagesExactCandidateHeadRatherThanSyntheticMergeCommit():void{
  $workflow=(string)file_get_contents(dirname(__DIR__,2).'/.github/workflows/digiforge-foundation-audit.yml');self::assertStringContainsString('ref: ${{ github.event.pull_request.head.sha || github.sha }}',$workflow);self::assertStringContainsString('DIGIFORGE_EXPECTED_CANDIDATE_SHA: ${{ github.event.pull_request.head.sha || github.sha }}',$workflow);self::assertStringContainsString('getenv("DIGIFORGE_EXPECTED_CANDIDATE_SHA")',$workflow);self::assertStringNotContainsString('getenv("GITHUB_SHA")',$workflow);
 }

 public function testCurrentDeploymentPlanFailsClosedAndMatchesCertifiedRelease():void{
  $plan=(string)file_get_contents(dirname(__DIR__,2).'/docs/releases/V1.0.103_CONTROLLED_DEPLOYMENT_PLAN.md');
  self::assertStringContainsString('Candidate: DigiForge 1.0.103',$plan);
  self::assertStringContainsString('Core package schema metadata: 23',$plan);
  self::assertStringContainsString('Expected runtime schema after additive catalog-acceptance migration: 24',$plan);
  self::assertStringContainsString('post-install readiness must report runtime schema 24/24',$plan);
  self::assertStringContainsString('current STOP ALL / activation / automation posture',$plan);
  self::assertStringContainsString('must be preserved',$plan);
  self::assertStringContainsString('Historical external-action evidence MUST NOT be cleared or rewritten',$plan);
  self::assertStringContainsString('Do not manually set schema-version options',$plan);
  self::assertStringContainsString('restoring the last compatible audited plugin + database checkpoint together',$plan);
  self::assertStringContainsString('Deployment is BLOCKED',$plan);
  self::assertStringContainsString('body-key exception applies only to this local raster-and-QA workflow',$plan);
  self::assertStringContainsString('Existing mutation routes retain their required `Idempotency-Key` header contracts',$plan);
  self::assertStringContainsString('QA success is not human approval',$plan);
 }
 public function testFinalConvergenceDocumentationCannotRegressToPre987Scope():void{
  $plan=(string)file_get_contents(dirname(__DIR__,2).'/docs/releases/V1.0.103_CONTROLLED_DEPLOYMENT_PLAN.md');
  $evidence=(string)file_get_contents(dirname(__DIR__,2).'/docs/releases/V1.0.103_FINAL_CONVERGENCE_EVIDENCE.md');
  foreach(['governed Product Factory AI generation boundary','authoritative shop policy/preflight','deterministic attempt identity','Stage-F dashboard attention-evidence correction','UNAVAILABLE rather than zero'] as $needle)self::assertStringContainsString($needle,$plan);
  foreach(['Deferred means REVIEW_REQUIRED, never PASS','production deployment remain separate explicit authority boundaries','external_actions_performed=false','production_activation_authorized=false'] as $needle)self::assertStringContainsString($needle,$evidence);
 }

 public function testPostInstallRuntimeAcceptanceRemainsFailClosed():void{
  $checklist=(string)file_get_contents(dirname(__DIR__,2).'/docs/releases/V1.0.103_POST_INSTALL_RUNTIME_ACCEPTANCE.md');
  foreach(['Plugin version | 1.0.103','Core plugin DB version | 23','Runtime schema after additive catalog-acceptance migration | 24 / 24','STOP ALL | ON','Activation authorization | OFF','Automation armed | FALSE','UNAVAILABLE is not PASS','does not grant Etsy publish, POD production','neither performs nor authorizes a destructive staging restore'] as $needle)self::assertStringContainsString($needle,$checklist);
 }

}
