<?php
declare(strict_types=1);
namespace DigiForge\Launch;

use DigiForge\Core\Settings;

/** Read-only prerequisite report for remaining scoped activation stages. */
final class RemainingActivationPreflight
{
    /** @return array<string,mixed> */
    public function report(string $capability): array
    {
        $map = [
            'etsy_publish' => ['etsy_draft_activation_authorized', 'etsy_draft'],
            'order_automation' => ['printify_activation_authorized', 'printify'],
            'gst_automation' => ['order_automation_activation_authorized', 'order_automation'],
        ];
        if (! isset($map[$capability])) {
            return ['status' => 'BLOCKED', 'capability' => $capability, 'blockers' => ['unknown_capability'], 'external_actions_performed' => false];
        }
        [$gate, $switch] = $map[$capability];
        $blockers = [];
        if (Settings::safety_locked()) { $blockers[] = 'production_safety_lock_active'; }
        if (Settings::get($gate, false) !== true || ! Settings::is_enabled($switch)) { $blockers[] = 'prerequisite_capability_not_effective'; }
        if (Settings::get($capability, false) !== true) { $blockers[] = 'capability_not_configured'; }
        return [
            'status' => $blockers === [] ? 'READY_FOR_CONTROLLED_ACTIVATION' : 'BLOCKED',
            'capability' => $capability,
            'blockers' => $blockers,
            'external_actions_performed' => false,
        ];
    }
}
