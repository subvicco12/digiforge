<?php

declare(strict_types=1);

namespace DigiForge\ProductFactory;

use DigiForge\Database\Tables;
use DigiForge\Security\Logger;
use WP_Error;

/**
 * Continues Gate 1 approvals into U3 without another human click.
 * Only the internal AI/local-asset Product Factory is invoked.
 */
final class ApprovalAutomation
{
    private const HOOK = 'digiforge_u3_build_product';

    public function register(): void
    {
        add_action('digiforge_log', [$this, 'onAudit'], 20, 4);
        add_action(self::HOOK, [$this, 'run'], 10, 2);
        add_filter('action_scheduler_timeout_period', [$this, 'timeoutPeriod']);
    }

    public function timeoutPeriod(int $seconds): int
    {
        return max($seconds, 900);
    }

    /** @param array<string,mixed> $context */
    public function onAudit(string $event, array $context, string $objectType, string $objectId): void
    {
        if ($event !== 'research_candidate_reviewed'
            || $objectType !== 'research_candidate'
            || strtoupper((string) ($context['decision'] ?? '')) !== 'APPROVED') {
            return;
        }
        $candidateId = absint($objectId);
        if ($candidateId < 1) { return; }
        $shop = $this->resolveShop($candidateId);
        if ($shop === '') {
            Logger::audit('u3_product_build_not_scheduled', ['reason' => 'target_shop_unknown'], 'research_candidate', (string) $candidateId);
            return;
        }
        $this->schedule($candidateId, $shop);
    }

    /** @return array<string,mixed>|WP_Error */
    public function retryApproved(int $candidateId): array|WP_Error
    {
        global $wpdb;
        if ($candidateId < 1) {
            return new WP_Error('invalid_candidate', __('Candidate ID is invalid.', 'digiforge'), ['status' => 400]);
        }
        $status = strtoupper((string) $wpdb->get_var($wpdb->prepare(
            'SELECT review_status FROM ' . Tables::research_candidates() . ' WHERE id=%d LIMIT 1',
            $candidateId
        )));
        if ($status !== 'APPROVED') {
            return new WP_Error('candidate_not_approved', __('Only an approved research candidate may retry Product Factory.', 'digiforge'), ['status' => 409]);
        }
        $shop = $this->resolveShop($candidateId);
        if ($shop === '') {
            return new WP_Error('target_shop_unknown', __('The approved candidate does not have a valid target shop.', 'digiforge'), ['status' => 409]);
        }
        $scheduled = $this->schedule($candidateId, $shop, true);
        if (! $scheduled) {
            return new WP_Error('u3_retry_not_scheduled', __('Product Factory retry could not be scheduled.', 'digiforge'), ['status' => 503]);
        }
        return ['candidate_id' => $candidateId, 'shop' => $shop, 'scheduled' => true, 'external_actions' => false];
    }

    public function run(int $candidateId, string $shop): void
    {
        $shop = sanitize_key($shop);
        if (! in_array($shop, ['digital', 'goods'], true)) {
            Logger::audit('u3_product_build_failed', ['reason' => 'invalid_shop'], 'research_candidate', (string) $candidateId);
            return;
        }
        if (function_exists('set_time_limit')) { @set_time_limit(0); }
        $result = (new Orchestrator())->build(
            $candidateId,
            ['shop' => $shop],
            'u3-auto-candidate-' . $candidateId . '-' . $shop
        );
        if (is_wp_error($result)) {
            Logger::audit('u3_product_build_failed', [
                'error_code' => $result->get_error_code(),
                'message' => $result->get_error_message(),
            ], 'research_candidate', (string) $candidateId);
            return;
        }
        Logger::audit('u3_product_build_completed', [
            'shop' => $shop,
            'product_version_id' => (int) (($result['product_version']['id'] ?? 0)),
            'workflow_status' => (string) ($result['workflow_status'] ?? ''),
            'product_approval_required' => (bool) ($result['product_approval_required'] ?? false),
        ], 'research_candidate', (string) $candidateId);
    }

    private function schedule(int $candidateId, string $shop, bool $retry = false): bool
    {
        $args = [$candidateId, $shop];
        $scheduled = false;
        $scheduler = 'wp_cron';
        if (function_exists('as_enqueue_async_action')) {
            $scheduler = 'action_scheduler';
            $actionId = as_enqueue_async_action(self::HOOK, $args, 'digiforge', true);
            $scheduled = is_int($actionId) && $actionId > 0;
        } else {
            $scheduled = wp_schedule_single_event(time() + 1, self::HOOK, $args, true) === true;
        }
        if (! $scheduled) {
            Logger::audit('u3_product_build_not_scheduled', [
                'reason' => 'scheduler_rejected_job', 'scheduler' => $scheduler, 'shop' => $shop, 'retry' => $retry,
            ], 'research_candidate', (string) $candidateId);
            return false;
        }
        Logger::audit($retry ? 'u3_product_build_retry_scheduled' : 'u3_product_build_scheduled', [
            'shop' => $shop, 'scheduler' => $scheduler,
        ], 'research_candidate', (string) $candidateId);
        return true;
    }

    private function resolveShop(int $candidateId): string
    {
        global $wpdb;
        $config = $wpdb->get_var($wpdb->prepare(
            'SELECT s.config FROM ' . Tables::research_candidate_evidence() . ' ce '
            . 'INNER JOIN ' . Tables::research_evidence() . ' e ON e.id=ce.evidence_id '
            . 'INNER JOIN ' . Tables::research_observations() . ' o ON o.id=e.observation_id '
            . 'INNER JOIN ' . Tables::research_sources() . ' s ON s.id=o.source_id '
            . 'WHERE ce.candidate_id=%d ORDER BY ce.evidence_id ASC LIMIT 1',
            $candidateId
        ));
        $decoded = is_string($config) ? json_decode($config, true) : null;
        $shop = is_array($decoded) ? sanitize_key((string) ($decoded['shop'] ?? '')) : '';
        return in_array($shop, ['digital', 'goods'], true) ? $shop : '';
    }
}
