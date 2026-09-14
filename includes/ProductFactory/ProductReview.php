<?php

declare(strict_types=1);

namespace DigiForge\ProductFactory;

use DigiForge\Database\Tables;
use DigiForge\Production\Repository as ProductionRepository;
use DigiForge\Security\Logger;
use WP_Error;

/** Gate 2: explicit human Product Approval. No listing or external action is executed here. */
final class ProductReview
{
    /** @return array<string,mixed>|WP_Error */
    public function decide(int $productVersionId, string $decision, string $notes = ''): array|WP_Error
    {
        if (get_current_user_id() < 1) {
            return $this->error('authorization', 'Product review requires an authenticated reviewer.', 403);
        }
        $decision = strtoupper(sanitize_key($decision));
        if (! in_array($decision, ['APPROVED', 'REJECTED'], true)) {
            return $this->error('validation', 'Product review decision must be APPROVED or REJECTED.');
        }

        $products = new Repository();
        $version = $products->find('product_version', $productVersionId);
        if ($version === null) {
            return $this->error('not_found', 'Product version not found.', 404);
        }
        $plan = $this->plan($productVersionId);
        if ($plan === null || (string) ($plan['state'] ?? '') !== 'REVIEW_REQUIRED') {
            return $this->error('review_not_ready', 'Product production plan is not awaiting review.', 409);
        }
        $bundle = $this->bundle((int) $plan['id']);
        if ($bundle === null || (string) ($bundle['state'] ?? '') !== 'REVIEW_REQUIRED') {
            return $this->error('review_not_ready', 'Product release bundle is not awaiting review.', 409);
        }

        $production = new ProductionRepository();
        if ($decision === 'REJECTED') {
            $planResult = $production->transition('plan', (int) $plan['id'], 'REJECTED');
            if (is_wp_error($planResult)) { return $planResult; }
            $bundleResult = $production->transition('bundle', (int) $bundle['id'], 'REJECTED');
            if (is_wp_error($bundleResult)) { return $bundleResult; }
            Logger::audit('u3_product_rejected', [
                'product_version_id' => $productVersionId,
                'notes' => sanitize_textarea_field($notes),
                'external_actions' => false,
            ], 'product_version', (string) $productVersionId);
            return [
                'product_version_id' => $productVersionId,
                'decision' => 'REJECTED',
                'workflow_status' => Workflow::ASSET_PRODUCTION,
                'external_actions_performed' => false,
                'next_action' => 'Revise the product, regenerate affected assets and rerun QA before another Product Approval review.',
            ];
        }

        $planQa = $this->planQaPassed((int) $plan['id']);
        if (is_wp_error($planQa)) { return $planQa; }
        $revisions = $this->requiredQaPassedRevisions((int) $plan['id']);
        if (is_wp_error($revisions)) { return $revisions; }

        // All fail-closed preconditions are checked before any approval state is written.
        foreach ($revisions as $revisionId) {
            $approvedRevision = $production->transition('revision', $revisionId, 'APPROVED');
            if (is_wp_error($approvedRevision)) { return $approvedRevision; }
        }

        $planResult = $production->transition('plan', (int) $plan['id'], 'APPROVED');
        if (is_wp_error($planResult)) { return $planResult; }
        $bundleResult = $production->transition('bundle', (int) $bundle['id'], 'APPROVED');
        if (is_wp_error($bundleResult)) { return $bundleResult; }

        $versionState = (string) ($version['state'] ?? 'DRAFT');
        if ($versionState === 'DRAFT') {
            $version = $products->transition('product_version', $productVersionId, 'REVIEW');
            if (is_wp_error($version)) { return $version; }
            $versionState = 'REVIEW';
        }
        if ($versionState === 'REVIEW') {
            $version = $products->transition('product_version', $productVersionId, 'APPROVED');
            if (is_wp_error($version)) { return $version; }
        }

        $productId = (int) ($version['product_id'] ?? 0);
        $product = $products->find('product', $productId);
        if ($product !== null && (string) ($product['state'] ?? '') === 'DRAFT') {
            $readyProduct = $products->transition('product', $productId, 'READY');
            if (is_wp_error($readyProduct)) { return $readyProduct; }
        }

        $readyBundle = $production->validateBundle((int) $bundle['id']);
        if (is_wp_error($readyBundle)) { return $readyBundle; }
        if ((string) ($readyBundle['state'] ?? '') !== 'RELEASE_READY') {
            return $this->error('bundle_not_ready', 'Product approval completed but release bundle readiness validation did not pass.', 409);
        }

        Logger::audit('u3_product_approved', [
            'product_version_id' => $productVersionId,
            'production_plan_id' => (int) $plan['id'],
            'release_bundle_id' => (int) $bundle['id'],
            'semantic_qa' => 'PASS',
            'notes' => sanitize_textarea_field($notes),
            'external_actions' => false,
        ], 'product_version', (string) $productVersionId);

        return [
            'product_version_id' => $productVersionId,
            'decision' => 'APPROVED',
            'workflow_status' => Workflow::PRODUCT_APPROVED,
            'release_bundle' => $readyBundle,
            'external_actions_performed' => false,
            'next_action' => 'Listing production may begin in the next stage. Etsy draft/publish remains blocked.',
        ];
    }

    /** @return array<string,mixed>|null */
    private function plan(int $productVersionId): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . Tables::production_plans() . ' WHERE product_version_id=%d ORDER BY id DESC LIMIT 1',
            $productVersionId
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private function bundle(int $planId): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . Tables::release_bundles() . ' WHERE production_plan_id=%d ORDER BY id DESC LIMIT 1',
            $planId
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** @return true|WP_Error */
    private function planQaPassed(int $planId): true|WP_Error
    {
        global $wpdb;
        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . Tables::production_qa() . " WHERE target_type='plan' AND target_id=%d AND check_type LIKE 'semantic_%%'",
            $planId
        ));
        $bad = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . Tables::production_qa() . " WHERE target_type='plan' AND target_id=%d AND check_type LIKE 'semantic_%%' AND status NOT IN ('PASS','WAIVED')",
            $planId
        ));
        if ($total < 7 || $bad > 0) {
            return $this->error('semantic_qa_failed', 'All required semantic, policy and consistency QA checks must pass before Product Approval.', 409);
        }
        return true;
    }

    /** @return list<int>|WP_Error */
    private function requiredQaPassedRevisions(int $planId): array|WP_Error
    {
        global $wpdb;
        $specIds = $wpdb->get_col($wpdb->prepare(
            'SELECT asset_spec_id FROM ' . Tables::production_plan_assets() . ' WHERE production_plan_id=%d AND is_required=1 ORDER BY sequence_no ASC',
            $planId
        ));
        if (! is_array($specIds) || $specIds === []) {
            return $this->error('qa_missing', 'No required production assets exist.', 409);
        }
        $revisions = [];
        foreach ($specIds as $specId) {
            $revision = $wpdb->get_row($wpdb->prepare(
                'SELECT * FROM ' . Tables::asset_revisions() . ' WHERE asset_spec_id=%d ORDER BY id DESC LIMIT 1',
                (int) $specId
            ), ARRAY_A);
            if (! is_array($revision) || (string) ($revision['state'] ?? '') !== 'QA_PASSED') {
                return $this->error('qa_failed', 'Every required asset must pass QA before Product Approval.', 409);
            }
            $bad = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM " . Tables::production_qa() . " WHERE target_type='revision' AND target_id=%d AND status NOT IN ('PASS','WAIVED')",
                (int) $revision['id']
            ));
            $total = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM " . Tables::production_qa() . " WHERE target_type='revision' AND target_id=%d",
                (int) $revision['id']
            ));
            if ($total < 1 || $bad > 0) {
                return $this->error('qa_failed', 'Every required asset must have completed passing QA checks.', 409);
            }
            $revisions[] = (int) $revision['id'];
        }
        return $revisions;
    }

    private function error(string $code, string $message, int $status = 400): WP_Error
    {
        return new WP_Error('digiforge_u3_' . $code, __($message, 'digiforge'), ['status' => $status]);
    }
}
