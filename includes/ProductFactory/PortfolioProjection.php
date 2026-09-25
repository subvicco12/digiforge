<?php

declare(strict_types=1);

namespace DigiForge\ProductFactory;

/**
 * Pure, side-effect-free portfolio projection for operator/admin surfaces.
 *
 * This deliberately does not schedule work or call external providers. It
 * aggregates already-known product workflow facts so one failed product
 * cannot hide the state of the rest of a batch.
 */
final class PortfolioProjection
{
    /**
     * @param array<int,array<string,mixed>> $products
     * @return array<string,mixed>
     */
    public static function summarize(array $products): array
    {
        $stages = [];
        $attention = [];
        $readyForProductReview = 0;
        $readyForListingReview = 0;

        foreach ($products as $index => $facts) {
            if (! is_array($facts)) {
                continue;
            }

            $stage = Workflow::project($facts);
            $stages[$stage] = ($stages[$stage] ?? 0) + 1;

            if ($stage === Workflow::PRODUCT_REVIEW_REQUIRED) {
                ++$readyForProductReview;
            }
            if ($stage === Workflow::LISTING_REVIEW_REQUIRED) {
                ++$readyForListingReview;
            }
            if (in_array($stage, [Workflow::QA_FAILED, Workflow::RESEARCH_PENDING], true)) {
                $attention[] = [
                    'index' => (int) $index,
                    'product_id' => max(0, (int) ($facts['product_id'] ?? 0)),
                    'product_version_id' => max(0, (int) ($facts['product_version_id'] ?? 0)),
                    'stage' => $stage,
                ];
            }
        }

        ksort($stages);

        return [
            'total' => array_sum($stages),
            'stages' => $stages,
            'ready_for_product_review' => $readyForProductReview,
            'ready_for_listing_review' => $readyForListingReview,
            'attention' => $attention,
            'external_actions_performed' => false,
        ];
    }
}
