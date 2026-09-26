<?php
declare(strict_types=1);
namespace DigiForge\Launch;

use DigiForge\Core\Settings;

/** Read-only Stage 3 preflight. It performs no provider request or external action. */
final class ProductDevelopmentActivationPreflight
{
    /** @return array<string,mixed> */
    public function report(): array
    {
        return self::summarize([
            'stop_all' => Settings::get('stop_all', true),
            'activation_authorized' => Settings::get('activation_authorized', false),
            'automation_armed' => Settings::get('automation_armed', false),
            'research_effective' => Settings::is_enabled('research'),
            'ai_effective' => Settings::is_enabled('ai'),
            'product_development_configured' => Settings::get('product_development', false),
            'product_development_authorized' => Settings::get('product_development_activation_authorized', false),
            'product_development_effective' => Settings::is_enabled('product_development'),
        ]);
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    public static function summarize(array $state): array
    {
        $checks = [
            'stop_all_released' => ($state['stop_all'] ?? true) === false,
            'activation_authorized' => ($state['activation_authorized'] ?? false) === true,
            'automation_armed' => ($state['automation_armed'] ?? false) === true,
            'research_effective' => ($state['research_effective'] ?? false) === true,
            'ai_effective' => ($state['ai_effective'] ?? false) === true,
            'product_development_configured' => ($state['product_development_configured'] ?? false) === true,
            'product_development_not_authorized_yet' => ($state['product_development_authorized'] ?? true) === false,
            'product_development_not_effective_yet' => ($state['product_development_effective'] ?? true) === false,
        ];
        $blockers = array_keys(array_filter($checks, static fn(bool $pass): bool => ! $pass));
        return ['status' => $blockers === [] ? 'READY_FOR_CONTROLLED_PRODUCT_DEVELOPMENT_ACTIVATION' : 'BLOCKED', 'checks' => $checks, 'blockers' => $blockers, 'network_requests_performed' => false, 'external_actions_performed' => false];
    }
}
