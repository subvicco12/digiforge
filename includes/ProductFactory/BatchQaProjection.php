<?php

declare(strict_types=1);

namespace DigiForge\ProductFactory;

/**
 * Aggregates already-produced local QA results without running production,
 * scheduling jobs, or invoking any provider.
 */
final class BatchQaProjection
{
    /** @param array<int,array<string,mixed>> $items @return array<string,mixed> */
    public static function summarize(array $items): array
    {
        $passed = 0;
        $failed = 0;
        $attention = [];

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                $failed++;
                $attention[] = [
                    'index' => (int) $index,
                    'product_version_id' => 0,
                    'asset_id' => 0,
                    'failed_checks' => ['invalid_item'],
                ];
                continue;
            }
            $checks = isset($item['checks']) && is_array($item['checks']) ? $item['checks'] : [];
            $explicit = $item['passed'] ?? null;
            $checkFailures = [];
            foreach ($checks as $check) {
                if (! is_array($check) || ($check['passed'] ?? false) !== true) {
                    $checkFailures[] = is_array($check) ? (string) ($check['name'] ?? 'unknown_check') : 'invalid_check';
                }
            }
            $ok = $explicit === true && $checks !== [] && $checkFailures === [];
            if ($ok) {
                $passed++;
                continue;
            }
            $failed++;
            $attention[] = [
                'index' => (int) $index,
                'product_version_id' => (int) ($item['product_version_id'] ?? 0),
                'asset_id' => (int) ($item['asset_id'] ?? 0),
                'failed_checks' => array_values(array_unique($checkFailures)),
            ];
        }

        return [
            'total' => $passed + $failed,
            'passed' => $passed,
            'failed' => $failed,
            'attention' => $attention,
            'all_passed' => $failed === 0 && $passed > 0,
            'external_actions_performed' => false,
        ];
    }
}
