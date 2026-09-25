<?php

declare(strict_types=1);

namespace DigiForge\ProductFactory;

/** Pure read-model summary for operator/admin portfolio visibility. */
final class PortfolioAdminSummary
{
    /** @param array<string,mixed> $workflow @param array<string,mixed> $qa @param array<string,mixed> $listing @return array<string,mixed> */
    public static function compose(array $workflow, array $qa, array $listing): array
    {
        return [
            'products_total' => self::nonNegativeInt($workflow['total'] ?? null),
            'product_review_ready' => self::nonNegativeInt($workflow['ready_for_product_review'] ?? null),
            'listing_review_ready' => self::nonNegativeInt($workflow['ready_for_listing_review'] ?? null),
            'qa_failed' => self::nonNegativeInt($qa['failed'] ?? null),
            'listing_preparation_ready' => self::nonNegativeInt($listing['ready_for_local_listing_preparation'] ?? null),
            'listing_blocked' => self::nonNegativeInt($listing['blocked'] ?? null),
            'attention_total' => count(is_array($workflow['attention'] ?? null) ? $workflow['attention'] : [])
                + count(is_array($qa['attention'] ?? null) ? $qa['attention'] : [])
                + count(is_array($listing['attention'] ?? null) ? $listing['attention'] : []),
            'gate3_approval_required' => true,
            'external_execution_locked' => true,
            'external_actions_performed' => false,
        ];
    }

    private static function nonNegativeInt(mixed $value): int
    {
        return is_int($value) && $value >= 0 ? $value : 0;
    }
}
