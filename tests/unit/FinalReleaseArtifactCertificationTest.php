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

        self::assertStringContainsString("const DIGIFORGE_VERSION = '1.0.45';", $plugin);
        self::assertStringContainsString("const DIGIFORGE_DB_VERSION = '15';", $plugin);
        self::assertStringContainsString('Plugin release: 1.0.45.', $readiness);
        self::assertStringContainsString('Database schema: v15.', $readiness);
        self::assertStringContainsString('READY_LOCKED', $readiness);
    }
}
