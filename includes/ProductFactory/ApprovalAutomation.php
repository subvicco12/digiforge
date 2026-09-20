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
    private const STAGE_HOOK = 'digiforge_u3_build_product_stage';

    public function register(): void
    {
        add_action('digiforge_log', [$this, 'onAudit'], 20, 4);
        add_action(self::HOOK, [$this, 'run'], 10, 3);
        add_action(self::STAGE_HOOK, [$this, 'runStage'], 10, 4);
        add_filter('action_scheduler_timeout_period', [$this, 'timeoutPeriod']);
    }

    public function timeoutPeriod(int $seconds): int
    {
        // Hostinger/Action Scheduler watchdogs may enforce a hard 300s ceiling regardless of PHP set_time_limit().
        // Keep DigiForge below that boundary so Action Scheduler does not falsely mark a healthy build failed.
        return min(max($seconds, 240), 270);
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
        $this->schedule($candidateId, $shop, 'u3-auto-candidate-' . $candidateId . '-' . $shop);
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
        $runKey = 'u3-repair-candidate-' . $candidateId . '-' . $shop . '-' . gmdate('YmdHis') . '-' . wp_generate_uuid4();
        $scheduled = $this->schedule($candidateId, $shop, $runKey, true);
        if (! $scheduled) {
            return new WP_Error('u3_retry_not_scheduled', __('Product Factory retry could not be scheduled.', 'digiforge'), ['status' => 503]);
        }
        return [
            'candidate_id' => $candidateId,
            'shop' => $shop,
            'scheduled' => true,
            'run_key' => $runKey,
            'external_actions' => false,
        ];
    }

    public function run(int $candidateId, string $shop, string $runKey = ''): void
    {
        $shop = sanitize_key($shop);
        if (! in_array($shop, ['digital', 'goods'], true)) {
            Logger::audit('u3_product_build_failed', ['reason' => 'invalid_shop'], 'research_candidate', (string) $candidateId);
            return;
        }
        $runKey = sanitize_key($runKey);
        if ($runKey === '') {
            $runKey = 'u3-auto-candidate-' . $candidateId . '-' . $shop;
        }
        // Keep the legacy hook as a short dispatcher. Long Product Factory work runs in
        // resumable stages so a host watchdog cannot strand the whole pipeline.
        $this->scheduleStage($candidateId, $shop, $runKey, 'prepare');
        return;
    }

    public function runStage(int $candidateId, string $shop, string $runKey = '', string $stage = 'prepare'): void
    {
        $shop = sanitize_key($shop);
        $runKey = sanitize_key($runKey);
        $stage = sanitize_key($stage);
        if ($candidateId < 1 || ! in_array($shop, ['digital', 'goods'], true) || $runKey === '' || ! in_array($stage, ['prepare','assets','semantic'], true)) {
            Logger::audit('u3_product_build_failed', ['reason' => 'invalid_stage_args'], 'research_candidate', (string) $candidateId);
            return;
        }
        if (function_exists('set_time_limit')) { @set_time_limit(240); }
        $result = (new Orchestrator())->buildStage($candidateId, ['shop' => $shop], $runKey, $stage);
        if (is_wp_error($result)) {
            Logger::audit('u3_product_build_failed', ['run_key'=>$runKey,'stage'=>$stage,'error_code'=>$result->get_error_code(),'message'=>$result->get_error_message()], 'research_candidate', (string) $candidateId);
            return;
        }
        $next = sanitize_key((string)($result['next_stage'] ?? 'complete'));
        if (in_array($next, ['assets','semantic'], true)) {
            if (! $this->scheduleStage($candidateId, $shop, $runKey, $next)) {
                Logger::audit('u3_product_build_failed', ['run_key'=>$runKey,'stage'=>$stage,'reason'=>'next_stage_not_scheduled'], 'research_candidate', (string) $candidateId);
            }
            return;
        }
        Logger::audit('u3_product_build_completed', ['shop'=>$shop,'run_key'=>$runKey,'product_version_id'=>(int)(($result['product_version']['id']??0)),'workflow_status'=>(string)($result['workflow_status']??''),'product_approval_required'=>(bool)($result['product_approval_required']??false),'external_actions'=>false], 'research_candidate', (string) $candidateId);
    }

    private function scheduleStage(int $candidateId, string $shop, string $runKey, string $stage = 'prepare'): bool
    {
        $args = [$candidateId, $shop, sanitize_key($runKey), sanitize_key($stage)];
        if (function_exists('as_enqueue_async_action')) {
            $actionId = as_enqueue_async_action(self::STAGE_HOOK, $args, 'digiforge', true);
            $scheduled = is_int($actionId) && $actionId > 0;
        } else {
            $scheduled = wp_schedule_single_event(time() + 1, self::STAGE_HOOK, $args, true) === true;
        }
        Logger::audit($scheduled ? 'u3_product_build_stage_scheduled' : 'u3_product_build_stage_not_scheduled', [
            'shop' => $shop,
            'run_key' => $runKey,
            'stage' => $stage,
            'external_actions' => false,
        ], 'research_candidate', (string) $candidateId);
        return $scheduled;
    }

    private function schedule(int $candidateId, string $shop, string $runKey, bool $retry = false): bool
    {
        $args = [$candidateId, $shop, sanitize_key($runKey)];
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
                'reason' => 'scheduler_rejected_job',
                'scheduler' => $scheduler,
                'shop' => $shop,
                'retry' => $retry,
                'run_key' => $runKey,
            ], 'research_candidate', (string) $candidateId);
            return false;
        }
        Logger::audit($retry ? 'u3_product_build_retry_scheduled' : 'u3_product_build_scheduled', [
            'shop' => $shop,
            'scheduler' => $scheduler,
            'run_key' => $runKey,
            'external_actions' => false,
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
