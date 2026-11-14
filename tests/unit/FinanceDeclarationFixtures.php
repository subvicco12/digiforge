<?php
declare(strict_types=1);

namespace DigiForge\Tests;

final class FinanceDeclarationFixtures
{
    public static function input(array $overrides = []): array
    {
        return array_replace_recursive([
            'schema' => 'digiforge-finance-declaration-v1', 'provider' => 'etsy', 'environment' => 'test',
            'external_transaction_id' => 'offline-payment-7', 'revision' => 1, 'previous_revision_hash' => '',
            'category' => 'REVENUE', 'currency' => 'USD', 'amount' => '10.0000',
            'base_currency' => 'USD', 'base_amount' => '10.0000', 'fx_snapshot_id' => 0,
            'occurred_at' => '2026-10-10T12:00:00Z', 'observed_at' => '2026-10-10T12:01:00Z',
            'source' => ['kind' => 'DECLARATION', 'document_ref' => 'offline/statement-7', 'document_hash' => str_repeat('a', 64)],
            'scope' => ['shop_key' => 'digital', 'business_id' => 1, 'store_id' => 2, 'order_id' => 7,
                'product_version_id' => 3, 'listing_id' => 4, 'provider_mapping_id' => 0, 'variant_ref' => ''],
            'reversal_of_hash' => '',
        ], $overrides);
    }
}
