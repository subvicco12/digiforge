<?php
declare(strict_types=1);
namespace DigiForge\Launch;

use DigiForge\Core\Settings;

/** Read-only scoped activation preflight. Performs no credential retrieval, network request, or external action. */
final class EtsyPublishActivationPreflight
{
    /** @return array<string,mixed> */
    public function report(): array
    {
        $checks = [
            'stop_all_released' => Settings::get('stop_all', true) === false,
            'activation_authorized' => Settings::get('activation_authorized', false) === true,
            'automation_armed' => Settings::get('automation_armed', false) === true,
            'etsy_draft_effective' => Settings::is_enabled('etsy_draft') === true,
            'etsy_publish_configured' => Settings::get('etsy_publish', false) === true,
            'etsy_publish_not_authorized_yet' => Settings::get('etsy_publish_activation_authorized', false) === false,
            'etsy_publish_not_effective_yet' => Settings::is_enabled('etsy_publish') === false,
        ];
        $blockers = array_keys(array_filter($checks, static fn(bool $pass): bool => ! $pass));
        return [
            'status' => $blockers === [] ? 'READY_FOR_CONTROLLED_ETSY_PUBLISH_ACTIVATION' : 'BLOCKED',
            'checks' => $checks,
            'blockers' => $blockers,
            'credential_retrieval_performed' => false,
            'network_requests_performed' => false,
            'external_actions_performed' => false,
        ];
    }
}
