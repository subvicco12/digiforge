<?php

declare(strict_types=1);

use DigiForge\Operations\RecoveryDrillEvidence;
use DigiForge\Operations\RecoveryEvidence;
use PHPUnit\Framework\TestCase;

if (! function_exists('get_option')) {
    function get_option(string $option, mixed $default = false): mixed
    {
        return $GLOBALS['digiforge_test_options'][$option] ?? $default;
    }
}
if (! function_exists('update_option')) {
    function update_option(string $option, mixed $value, mixed $autoload = null): bool
    {
        $GLOBALS['digiforge_test_options'][$option] = $value;
        return true;
    }
}

final class FinalReleaseIdentityTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $savedOptions = [];

    protected function setUp(): void
    {
        $this->savedOptions = $GLOBALS['digiforge_test_options'] ?? [];
        $GLOBALS['digiforge_test_options'] = [
            'digiforge_recovery_database_backup_evidence' => [
                'identifier' => 'backup', 'captured_at' => gmdate('c'), 'location' => 'backup.sql',
                'retrievable' => true, 'verification_status' => 'VERIFIED',
                'verification_method' => 'checksum', 'verified_at' => gmdate('c'), 'verified_by' => 'reviewer',
            ],
            'digiforge_recovery_plugin_package_evidence' => [
                'identifier' => 'package', 'version' => '1.0.103', 'location' => 'digiforge.zip',
                'source_commit' => str_repeat('a', 40), 'sha256' => str_repeat('b', 64),
                'retrievable' => true, 'checksum_verified' => true,
            ],
        ];
    }

    protected function tearDown(): void
    {
        $GLOBALS['digiforge_test_options'] = $this->savedOptions;
    }

    /** @return array<string,mixed> */
    private function drill(): array
    {
        return [
            'drill_id' => 'drill', 'performed_at' => gmdate('c'), 'environment' => 'staging',
            'database_backup_identifier' => 'backup', 'plugin_package_identifier' => 'package',
            'performed_by' => 'reviewer', 'restore_verified' => true,
            'schema_verified' => true, 'application_health_verified' => true,
        ];
    }

    public function testLegacyIdentifierOnlyDrillFailsClosed(): void
    {
        $GLOBALS['digiforge_test_options']['digiforge_recovery_drill_evidence'] = $this->drill();
        self::assertFalse(RecoveryDrillEvidence::snapshot()['passed']);
    }

    public function testSameIdentifierChangedPackageAndBackupInvalidateDrill(): void
    {
        foreach (['sha256', 'source_commit', 'location', 'backup_location'] as $field) {
            self::assertTrue(RecoveryDrillEvidence::store($this->drill()));
            self::assertTrue(RecoveryDrillEvidence::snapshot()['passed']);
            $key = $field === 'backup_location'
                ? 'digiforge_recovery_database_backup_evidence' : 'digiforge_recovery_plugin_package_evidence';
            $record = $GLOBALS['digiforge_test_options'][$key];
            $name = $field === 'backup_location' ? 'location' : $field;
            $GLOBALS['digiforge_test_options'][$key][$name] = match ($field) {
                'sha256' => str_repeat('c', 64), 'source_commit' => str_repeat('d', 40),
                default => 'different-file',
            };
            self::assertFalse(RecoveryDrillEvidence::snapshot()['passed'], $field);
            $GLOBALS['digiforge_test_options'][$key] = $record;
        }
    }

    public function testChangedArtifactsCannotBeStoredAgainstExpectedVerifiedIdentity(): void
    {
        self::assertTrue(RecoveryDrillEvidence::store($this->drill()));
        $record = RecoveryDrillEvidence::snapshot()['evidence'];
        $GLOBALS['digiforge_test_options']['digiforge_recovery_plugin_package_evidence']['sha256'] = str_repeat('e', 64);
        self::assertFalse(RecoveryDrillEvidence::store($record));
        self::assertFalse(RecoveryDrillEvidence::snapshot()['passed']);
    }

    public function testCorruptStoredPackageCannotClaimIdentityOrChecksumVerified(): void
    {
        foreach (['sha256', 'source_commit'] as $field) {
            $original = $GLOBALS['digiforge_test_options']['digiforge_recovery_plugin_package_evidence'][$field];
            $GLOBALS['digiforge_test_options']['digiforge_recovery_plugin_package_evidence'][$field] = 'invalid';
            $evidence = RecoveryEvidence::snapshot();
            self::assertFalse($evidence['plugin_package_identity_recorded'], $field);
            self::assertFalse($evidence['checksum_verified'], $field);
            self::assertFalse(RecoveryDrillEvidence::store($this->drill()), $field);
            $GLOBALS['digiforge_test_options']['digiforge_recovery_plugin_package_evidence'][$field] = $original;
        }
    }
}
