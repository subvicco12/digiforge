<?php

declare(strict_types=1);

namespace DigiForge\Listings;

/** Pure read-model aggregation for listing state visibility. */
final class AdminStateSummary
{
    private const VALID_STATES = ['DRAFT','VALIDATED','REVIEW_REQUIRED','APPROVED','REJECTED','SUPERSEDED'];

    /** @param array<int,mixed> $rows @return array<string,mixed> */
    public static function summarize(array $rows): array
    {
        $states = [];
        $invalid = 0;
        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['state']) || ! is_string($row['state']) || trim($row['state']) === '') {
                $invalid++;
                continue;
            }
            $state = strtoupper(trim($row['state']));
            if (! in_array($state, self::VALID_STATES, true)) {
                $invalid++;
                continue;
            }
            $states[$state] = ($states[$state] ?? 0) + 1;
        }
        ksort($states);
        return [
            'total' => count($rows),
            'states' => $states,
            'invalid' => $invalid,
            'gate3_approval_required' => true,
            'etsy_api_invoked' => false,
            'external_actions_performed' => false,
        ];
    }
}
