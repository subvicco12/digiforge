<?php

declare(strict_types=1);

namespace DigiForge\Launch;

use DigiForge\Queue\JobRepository;
use DigiForge\Security\Logger;

/** Executes approved product development + local digital production outside the approval request. */
final class ProductProductionWorker
{
    private const HOOK = 'digiforge_run_approved_product_production';
    private const OWNER = 'product-production-worker';

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'run'], 10, 4);
    }

    /** @return int|\WP_Error */
    public function queue(int $candidateId, string $shop)
    {
        $shop = sanitize_key($shop);
        if ($candidateId < 1 || ! in_array($shop, ['digital', 'goods'], true)) {
            return new \WP_Error('digiforge_production_queue_validation', __('A valid candidate and target shop are required.', 'digiforge'), ['status' => 400]);
        }

        $key = sprintf('approved-production-%d-%s', $candidateId, $shop);
        $jobs = new JobRepository();
        $jobId = $jobs->enqueue('approved_product_production', [
            'candidate_id' => $candidateId,
            'shop' => $shop,
        ], $key);
        if ($jobId === false) {
            return new \WP_Error('digiforge_production_queue_failed', __('Unable to create the product production job.', 'digiforge'), ['status' => 500]);
        }

        global $wpdb;
        $table = \DigiForge\Database\Tables::jobs();
        $state = (string) $wpdb->get_var($wpdb->prepare('SELECT state FROM ' . $table . ' WHERE id=%d', $jobId));
        if ($state === 'BLOCKED' && ! $jobs->transition($jobId, 'BLOCKED', 'QUEUED')) {
            return new \WP_Error('digiforge_production_queue_failed', __('Unable to queue the product production job.', 'digiforge'), ['status' => 500]);
        }
        if (in_array($state, ['SUCCESS', 'RUNNING', 'QUEUED'], true)) {
            return $jobId;
        }

        $args = [$jobId, $candidateId, $shop, $key];
        if (! wp_next_scheduled(self::HOOK, $args)) {
            wp_schedule_single_event(time() + 5, self::HOOK, $args);
        }

        Logger::audit('launch_product_production_queued', [
            'job_id' => $jobId,
            'candidate_id' => $candidateId,
            'shop' => $shop,
        ], 'research_candidate', (string) $candidateId);

        return $jobId;
    }

    public function run(int $jobId, int $candidateId, string $shop, string $key): void
    {
        $jobs = new JobRepository();
        if (! $jobs->acquireLease($jobId, self::OWNER, 1200) || ! $jobs->start($jobId, self::OWNER)) {
            Logger::audit('launch_product_production_worker_skipped', [
                'job_id' => $jobId,
                'candidate_id' => $candidateId,
                'reason' => 'lease_or_state',
            ], 'research_candidate', (string) $candidateId);
            return;
        }

        try {
            $developed = (new ExecutionEngine())->develop($candidateId, ['shop' => $shop], $key . '-develop');
            if (is_wp_error($developed)) {
                $this->retry($jobs, $jobId, $candidateId, $shop, $key, $developed->get_error_message());
                return;
            }

            $productVersionId = (int) ($developed['product_version']['id'] ?? 0);
            if ($productVersionId < 1) {
                $this->retry($jobs, $jobId, $candidateId, $shop, $key, 'Product development returned no product version.');
                return;
            }

            if ($shop === 'digital') {
                $produced = (new ProductProductionEngine())->produce($productVersionId, $key . '-produce');
                if (is_wp_error($produced)) {
                    $this->retry($jobs, $jobId, $candidateId, $shop, $key, $produced->get_error_message());
                    return;
                }
            } else {
                Logger::audit('launch_goods_production_deferred', [
                    'job_id' => $jobId,
                    'candidate_id' => $candidateId,
                    'product_version_id' => $productVersionId,
                ], 'product_version', (string) $productVersionId);
            }

            $jobs->transition($jobId, 'RUNNING', 'SUCCESS');
            $jobs->releaseLease($jobId, self::OWNER);
            Logger::audit('launch_product_production_job_succeeded', [
                'job_id' => $jobId,
                'candidate_id' => $candidateId,
                'product_version_id' => $productVersionId,
                'shop' => $shop,
            ], 'research_candidate', (string) $candidateId);
        } catch (\Throwable $e) {
            $this->retry($jobs, $jobId, $candidateId, $shop, $key, 'Unexpected production worker failure.');
        }
    }

    private function retry(JobRepository $jobs, int $jobId, int $candidateId, string $shop, string $key, string $error): void
    {
        $scheduled = $jobs->scheduleRetry($jobId, 300, $error);
        if ($scheduled) {
            if ($jobs->transition($jobId, 'RETRY', 'QUEUED')) {
                $args = [$jobId, $candidateId, $shop, $key];
                if (! wp_next_scheduled(self::HOOK, $args)) {
                    wp_schedule_single_event(time() + 300, self::HOOK, $args);
                }
            }
        } else {
            $jobs->deadLetter($jobId, $error);
        }
        Logger::audit('launch_product_production_job_failed', [
            'job_id' => $jobId,
            'candidate_id' => $candidateId,
            'shop' => $shop,
            'reason' => sanitize_text_field($error),
        ], 'research_candidate', (string) $candidateId);
    }
}
