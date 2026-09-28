<?php
declare(strict_types=1);

use DigiForge\Listings\EtsyDigitalAttachmentReadModel;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/Listings/EtsyDigitalAttachmentReadModel.php';

final class EtsyPortalReadinessEvidenceTest extends TestCase
{
    public function testConfirmedUploadIdentityRequiresMatchingListingFileAndImmutableHashes(): void
    {
        $row = [
            'id' => 6, 'intent_id' => 3, 'draft_package_id' => 4,
            'operation_type' => 'UPLOAD_FILE', 'state' => 'CONFIRMED_SUCCESS',
            'resource_reference' => '123456', 'external_reference' => '123456',
            'external_asset_reference' => '987654',
            'request_fingerprint' => str_repeat('a', 64), 'evidence_hash' => str_repeat('b', 64),
        ];
        $valid = EtsyDigitalAttachmentReadModel::project($row);
        self::assertSame('CONFIRMED_IDENTITY_RECORDED', $valid['evidence_state']);
        self::assertFalse($valid['provider_live_verified']);
        self::assertFalse($valid['upload_permitted']);
        self::assertFalse($valid['publish_permitted']);
        self::assertFalse($valid['external_execution_authorized']);
        foreach ([
            ['external_asset_reference' => ''],
            ['external_reference' => '999'],
            ['request_fingerprint' => ''],
            ['intent_id' => 0],
            ['state' => 'UNKNOWN'],
            ['operation_type' => 'ATTACH_IMAGE'],
        ] as $change) {
            self::assertSame('REVIEW_REQUIRED', EtsyDigitalAttachmentReadModel::project(array_replace($row, $change))['evidence_state']);
        }
    }

    public function testProjectionSelectsOnlyBoundedNonSecretLedgerFields(): void
    {
        $source = file_get_contents(__DIR__ . '/../../includes/Listings/EtsyDigitalAttachmentReadModel.php');
        $portal = file_get_contents(__DIR__ . '/../../includes/Portal/Portal.php');
        self::assertStringContainsString("operation_type='UPLOAD_FILE' AND state='CONFIRMED_SUCCESS'", $source);
        self::assertStringNotContainsString('reconciliation_evidence', $source);
        self::assertStringNotContainsString('idempotency_key', $source);
        self::assertStringContainsString('Confirmed digital file identity evidence', $portal);
        self::assertStringContainsString('Etsy webhook configuration', $portal);
        self::assertStringContainsString('does not verify Etsy subscription delivery', $portal);
        self::assertStringContainsString('NO UPLOAD / NO PUBLISH', $portal);
    }
}
