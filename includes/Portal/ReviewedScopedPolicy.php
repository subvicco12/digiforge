<?php
declare(strict_types=1);
namespace DigiForge\Portal;

use DigiForge\Core\Config;
use DigiForge\Core\Settings;
use DigiForge\Security\ReviewedLocalEvidence;
use WP_Error;

/** Reviewed local restrictions only; existing activation and admission gates remain mandatory. */
final class ReviewedScopedPolicy
{
    // These are the workflow identities already emitted by ShopAiGovernanceRepository.
    public const WORKFLOWS = ['research', 'product_factory'];
    public const ENVIRONMENTS = ['sandbox', 'production'];

    public static function propose(string $shop, array $input, int $effectiveAt, int $expiresAt, string $requestId, int $expectedRevision): array|WP_Error
    {
        $key = self::keyFor($input);
        if (is_wp_error($key)) { return $key; }
        $result = ReviewedLocalEvidence::propose('policy', $shop, $key, $input, $effectiveAt, $expiresAt, $requestId, $expectedRevision);
        return self::withKey($result, $key);
    }

    public static function decide(string $shop, string $key, int $revision, string $hash, string $decision, string $requestId): array|WP_Error
    {
        if (!self::validKey($key)) { return self::invalid(); }
        return self::withKey(ReviewedLocalEvidence::decide('policy', $shop, $key, $revision, $hash, $decision, $requestId), $key);
    }

    public static function current(string $shop, string $key): array|WP_Error|null
    {
        if (!self::validKey($key)) { return self::invalid(); }
        return self::withKey(ReviewedLocalEvidence::read('policy', $shop, $key), $key);
    }

    /** Canonical identity is exact and never sanitizes a malformed input into another scope. */
    public static function keyFor(array $input): string|WP_Error
    {
        $keys = array_keys($input);
        sort($keys);
        if ($keys !== ['capability', 'enabled', 'environment', 'scope', 'workflow']) { return self::invalid(); }
        foreach (['environment', 'scope', 'workflow', 'capability'] as $field) {
            if (!is_string($input[$field])) { return self::invalid(); }
        }
        if (!is_bool($input['enabled']) || !in_array($input['environment'], self::ENVIRONMENTS, true)
            || !in_array($input['scope'], ['SHOP', 'WORKFLOW'], true)
            || !in_array($input['capability'], Config::SWITCHES, true)
            || in_array($input['capability'], ['stop_all', 'gst_automation'], true)
            || ($input['scope'] === 'SHOP' && $input['workflow'] !== '')
            || ($input['scope'] === 'WORKFLOW' && !in_array($input['workflow'], self::WORKFLOWS, true))) {
            return self::invalid();
        }
        return implode(':', [$input['environment'], $input['scope'], $input['workflow'], $input['capability']]);
    }

    /** No execution admission: caller must still satisfy quantity/budget and every existing gate. */
    public static function evaluate(string $shop, string $environment, string $workflow, string $capability): array
    {
        $result = ['capability'=>$capability, 'global_enabled'=>false, 'shop_enabled'=>false,
            'workflow_enabled'=>false, 'effective_enabled'=>false, 'global_disable_wins'=>true,
            'shop_disable_wins'=>true, 'narrower_scope_cannot_override_parent'=>true,
            'existing_quantity_budget_ceilings_required'=>true, 'read_only'=>true,
            'external_execution_authorized'=>false];
        $input = ['environment'=>$environment, 'scope'=>'WORKFLOW', 'workflow'=>$workflow, 'capability'=>$capability, 'enabled'=>true];
        $workflowKey = self::keyFor($input);
        if (is_wp_error($workflowKey)) { return $result; }
        $input['scope'] = 'SHOP';
        $input['workflow'] = '';
        $shopKey = self::keyFor($input);
        if (is_wp_error($shopKey)) { return $result; }
        $parent = ReviewedLocalEvidence::approved('policy', $shop, $shopKey);
        $child = ReviewedLocalEvidence::approved('policy', $shop, $workflowKey);
        $result['shop_enabled'] = self::enabled($parent, $shopKey);
        $result['workflow_enabled'] = self::enabled($child, $workflowKey);
        $result['global_enabled'] = !Settings::safety_locked() && Settings::is_enabled($capability);
        $result['effective_enabled'] = $result['global_enabled'] && $result['shop_enabled'] && $result['workflow_enabled'];
        return $result;
    }

    private static function enabled(array|WP_Error $state, string $key): bool
    {
        if (is_wp_error($state) || !is_array($state['payload'] ?? null)) { return false; }
        $identity = self::keyFor($state['payload']);
        return is_string($identity) && hash_equals($key, $identity) && $state['payload']['enabled'] === true;
    }

    private static function validKey(string $key): bool
    {
        $parts = explode(':', $key);
        return count($parts) === 4 && self::keyFor(['environment'=>$parts[0], 'scope'=>$parts[1], 'workflow'=>$parts[2], 'capability'=>$parts[3], 'enabled'=>true]) === $key;
    }

    private static function withKey(array|WP_Error|null $state, string $key): array|WP_Error|null
    {
        if (is_array($state)) { $state['policy_key'] = $key; }
        return $state;
    }

    private static function invalid(): WP_Error
    {
        return new WP_Error('invalid_reviewed_policy', 'An exact typed existing feature and shop or workflow scope are required.');
    }
}
