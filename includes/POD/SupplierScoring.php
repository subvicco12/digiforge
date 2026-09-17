<?php

declare(strict_types=1);

namespace DigiForge\POD;

/** Deterministic provider-neutral scoring; performs no supplier execution. */
final class SupplierScoring
{
    private const WEIGHTS = [
        'market_opportunity' => 20,
        'landed_cost' => 20,
        'fulfillment_geography' => 10,
        'production_speed' => 8,
        'quality_reliability' => 10,
        'variant_coverage' => 7,
        'template_capability' => 10,
        'personalization_compatibility' => 7,
        'mockup_preview' => 3,
        'backup_availability' => 5,
    ];

    /** @return array{score:float,decision:string,region:string} */
    public static function evaluate(array $input): array
    {
        $region = strtoupper(trim((string)($input['region'] ?? '')));
        if (!in_array($region, ['US','EU'], true)) {
            throw new \InvalidArgumentException('region must be US or EU');
        }
        $weighted = 0.0;
        foreach (self::WEIGHTS as $field => $weight) {
            if (!array_key_exists($field, $input) || !is_numeric($input[$field])) {
                throw new \InvalidArgumentException($field . ' score is required');
            }
            $score = (float)$input[$field];
            if (!is_finite($score) || $score < 0 || $score > 100) {
                throw new \InvalidArgumentException($field . ' must be between 0 and 100');
            }
            $weighted += $score * ($weight / 100);
        }

        $demand = (float)$input['market_opportunity'];
        $fulfillment = ((float)$input['landed_cost'] + (float)$input['fulfillment_geography'] + (float)$input['quality_reliability']) / 3;
        $printify = strtolower(trim((string)($input['provider'] ?? ''))) === 'printify';

        // Demand first: poor Printify fulfillment must trigger an alternative
        // provider search rather than rejecting a validated product concept.
        if ($demand >= 70 && $printify && $fulfillment < 55) {
            $decision = 'EXTERNAL_PROVIDER_SEARCH';
        } elseif ($demand < 40) {
            $decision = 'REJECT';
        } elseif ($weighted >= 75) {
            $decision = 'SUPPLIER_SELECTED';
        } elseif ($weighted >= 60) {
            $decision = 'PRINTIFY_CANDIDATE';
        } else {
            $decision = 'ALT_PRINTIFY_SEARCH';
        }

        return ['score' => round($weighted, 2), 'decision' => $decision, 'region' => $region];
    }
}
