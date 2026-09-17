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

    /** @return array{score:float,decision:string,region:string,provider:string} */
    public static function evaluate(array $input): array
    {
        $region = strtoupper(trim((string)($input['region'] ?? '')));
        if (!in_array($region, ['US','EU'], true)) {
            throw new \InvalidArgumentException('region must be US or EU');
        }
        if (!array_key_exists('provider', $input) || !is_string($input['provider'])) {
            throw new \InvalidArgumentException('provider must be a nonempty string');
        }
        $provider = strtolower(trim($input['provider']));
        if ($provider === '') {
            throw new \InvalidArgumentException('provider must be a nonempty string');
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

        // Demand first: a validated concept is not rejected merely because the
        // evaluated supplier cannot fulfill it competitively in the region.
        if ($demand >= 70 && $fulfillment < 55) {
            $decision = 'ALTERNATIVE_PROVIDER_SEARCH';
        } elseif ($demand < 40) {
            $decision = 'REJECT';
        } elseif ($weighted >= 75) {
            $decision = 'SUPPLIER_SELECTED';
        } elseif ($weighted >= 60) {
            $decision = 'SUPPLIER_CANDIDATE';
        } else {
            $decision = 'ALTERNATIVE_PROVIDER_SEARCH';
        }

        return [
            'score' => round($weighted, 2),
            'decision' => $decision,
            'region' => $region,
            'provider' => $provider,
        ];
    }
}
