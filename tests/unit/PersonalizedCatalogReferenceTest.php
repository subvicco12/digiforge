<?php

declare(strict_types=1);

use DigiForge\POD\BusinessScope;
use DigiForge\POD\MasterCatalogImportContract;
use DigiForge\POD\PersonalizedCatalogReference;
use PHPUnit\Framework\TestCase;

final class PersonalizedCatalogReferenceTest extends TestCase
{
    /** @dataProvider foreignHistoricalIdentities */
    public function testHistoricalCatalogCannotReplaceOneOfItsFiveHundredIdentities(string $identity): void
    {
        $rows = [];
        for ($i = 1; $i <= 500; $i++) {
            $rows[] = [sprintf('DG-%03d', $i), 'W1', 'Synthetic family', 'Synthetic concept', 'PHOTO_TEXT', 'PP-001', 'PRINTIFY_PRIMARY', 'TEMPLATE_RESEARCH', '', '', '', ''];
        }
        $rows[499][0] = $identity;
        $this->expectException(InvalidArgumentException::class);
        MasterCatalogImportContract::normalize(MasterCatalogImportContract::REQUIRED, $rows);
    }

    public static function foreignHistoricalIdentities(): array
    {
        return [['DG-000'], ['DG-501']];
    }

    public function testMaster500ReferenceIsPersonalizedPodOnlyAndNonExecuting(): void
    {
        $metadata = PersonalizedCatalogReference::metadata();

        self::assertSame('digicraftifygoods-master-500-v1', $metadata['catalog_key']);
        self::assertSame(BusinessScope::DIGICRAFTIFY_GOODS, $metadata['business_key']);
        self::assertSame(BusinessScope::PERSONALIZED_POD, $metadata['product_program']);
        self::assertSame(500, $metadata['listing_count']);
        self::assertSame(16, $metadata['personalization_engine_count']);
        self::assertSame(10, $metadata['template_rule_count']);
        self::assertSame('IMMUTABLE_REFERENCE', $metadata['source_state']);
        self::assertFalse($metadata['production_authority']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $metadata['source_sha256']);
    }
}
