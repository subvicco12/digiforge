<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CredentialEvidenceVersionTest extends TestCase
{
    public function testVersionIsOrderIndependentAndChangesWithCredentialFingerprint(): void
    {
        require_once __DIR__.'/../../includes/Integrations/CredentialEvidenceVersion.php';
        $first=[
            ['secret_name'=>'access_token','fingerprint'=>'abcdef0123456789'],
            ['secret_name'=>'refresh_token','fingerprint'=>'0123456789abcdef'],
        ];
        $version=\DigiForge\Integrations\CredentialEvidenceVersion::fromMetadata($first);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/',(string)$version);
        self::assertSame($version,\DigiForge\Integrations\CredentialEvidenceVersion::fromMetadata(array_reverse($first)));
        $rotated=$first;
        $rotated[0]['fingerprint']='fedcba9876543210';
        self::assertNotSame($version,\DigiForge\Integrations\CredentialEvidenceVersion::fromMetadata($rotated));
        self::assertStringNotContainsString('abcdef0123456789',(string)$version);
    }

    public function testMissingMalformedAndDuplicateMetadataNeverProducesAVersion(): void
    {
        require_once __DIR__.'/../../includes/Integrations/CredentialEvidenceVersion.php';
        $model=\DigiForge\Integrations\CredentialEvidenceVersion::class;
        self::assertNull($model::fromMetadata([]));
        self::assertNull($model::fromMetadata([['secret_name'=>'api_key']]));
        self::assertNull($model::fromMetadata([['secret_name'=>'api_key','fingerprint'=>'not-a-fingerprint']]));
        self::assertNull($model::fromMetadata([
            ['secret_name'=>'api_key','fingerprint'=>'abcdef0123456789'],
            ['secret_name'=>'api_key','fingerprint'=>'0123456789abcdef'],
        ]));
    }
}
