<?php
declare(strict_types=1);
namespace DigiForge\Launch;

use DigiForge\Core\Settings;

/** Read-only scoped activation preflight. Performs no credential retrieval, network request, or external action. */
final class OrderAutomationActivationPreflight
{
    /** @return array<string,mixed> */
    public function report(): array
    {
        $checks = [
            'stop_all_released' => Settings::get('stop_all', true) === false,
            'activation_authorized' => Settings::get('activation_authorized', false) === true,
            'automation_armed' => Settings::get('automation_armed', false) === true,
            'printify_effective' => Settings::is_enabled('printify') === true,
            'order_automation_configured' => Settings::get('order_automation', false) === true,
            'order_automation_not_authorized_yet' => Settings::get('order_automation_activation_authorized', false) === false,
            'order_automation_not_effective_yet' => Settings::is_enabled('order_automation') === false,
        ];
        $blockers = array_keys(array_filter($checks, static fn(bool $pass): bool => ! $pass));
        return [
            'status' => $blockers === [] ? 'READY_FOR_CONTROLLED_ORDER_AUTOMATION_ACTIVATION' : 'BLOCKED',
            'checks' => $checks,
            'blockers' => $blockers,
            'credential_retrieval_performed' => false,
            'network_requests_performed' => false,
            'external_actions_performed' => false,
        ];
    }
}
