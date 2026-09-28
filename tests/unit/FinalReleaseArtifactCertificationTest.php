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
            'GITHUB_SHA'
        ] as $needle) {
            self::assertStringContainsString($needle, $workflow);
        }
    }

    public function testReleaseDocumentationMatchesCurrentPluginMetadata(): void
    {
        $plugin = (string) file_get_contents(dirname(__DIR__, 2) . '/digiforge.php');
        $readiness = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/final-readiness-completion.md');

        self::assertStringContainsString("const DIGIFORGE_VERSION = '1.0.65';", $plugin);
        self::assertStringContainsString("const DIGIFORGE_DB_VERSION = '22';", $plugin);
        self::assertStringContainsString('Plugin release: 1.0.65.', $readiness);
        self::assertStringContainsString('Database schema: v17.', $readiness);
        self::assertStringContainsString('READY_LOCKED', $readiness);
    }

 public function testReleaseCandidateEvidenceGateIsFailClosed():void{
  $gate=file_get_contents(__DIR__.'/../../docs/releases/RELEASE_CANDIDATE_EVIDENCE_GATE.md');self::assertIsString($gate);self::assertStringContainsString('UNKNOWN is not success',$gate);self::assertStringContainsString('No release-readiness result grants Etsy publish or POD production authority',$gate);self::assertStringContainsString('production_activation_authorized=false',$gate);
 }
}
