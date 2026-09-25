<?php

declare(strict_types=1);

namespace DigiForge\Listings;

/**
 * Pure portfolio projection for local listing-package readiness.
 * It never creates a listing, draft package, intent, or provider request.
 */
final class BatchListingProjection
{
    /** @param array<int,array<string,mixed>> $items @return array<string,mixed> */
    public static function summarize(array $items): array
    {
        $ready = 0;
        $blocked = 0;
        $attention = [];

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                $blocked++;
                $attention[] = self::attention((int) $index, 0, ['invalid_item']);
                continue;
            }

            $reasons = [];
            $rawProductVersionId = $item['product_version_id'] ?? null;
            $validProductVersionId = is_int($rawProductVersionId)
                ? $rawProductVersionId > 0
                : (is_string($rawProductVersionId)
                    && preg_match('/^[1-9][0-9]*$/', $rawProductVersionId) === 1);
            $productVersionId = $validProductVersionId ? (int) $rawProductVersionId : 0;
            if (! $validProductVersionId) {
                $reasons[] = 'invalid_product_version';
            }
            if (($item['product_approved'] ?? false) !== true) {
                $reasons[] = 'gate2_not_approved';
            }
            if (($item['release_bundle_ready'] ?? false) !== true) {
                $reasons[] = 'release_bundle_not_ready';
            }
            if (($item['listing_spec_complete'] ?? false) !== true) {
                $reasons[] = 'listing_spec_incomplete';
            }

            if ($reasons === []) {
                $ready++;
            } else {
                $blocked++;
                $attention[] = self::attention((int) $index, $productVersionId, $reasons);
            }
        }

        return [
            'total' => $ready + $blocked,
            'ready_for_local_listing_preparation' => $ready,
            'blocked' => $blocked,
            'attention' => $attention,
            'gate3_approval_required' => true,
            'draft_packages_created' => 0,
            'etsy_api_invoked' => false,
            'external_actions_performed' => false,
        ];
    }

    /** @param list<string> $reasons @return array<string,mixed> */
    private static function attention(int $index, int $productVersionId, array $reasons): array
    {
        return ['index' => $index, 'product_version_id' => $productVersionId, 'reasons' => $reasons];
    }
}
