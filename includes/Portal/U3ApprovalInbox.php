<?php

declare(strict_types=1);

namespace DigiForge\Portal;

use DigiForge\Database\Tables;
use DigiForge\ProductFactory\ProductReview;
use DigiForge\Security\Logger;

/** Adds Gate 2 Product Approval to the existing single DigiForge Approval Inbox. */
final class U3ApprovalInbox
{
    private const ACTION = 'digiforge_u3_product_review';

    public function register(): void
    {
        add_filter('do_shortcode_tag', [$this, 'appendPanel'], 20, 4);
        add_action('admin_post_' . self::ACTION, [$this, 'review']);
    }

    /** @param array<string,mixed> $attr @param array<int,string> $match */
    public function appendPanel(string $output, string $tag, array $attr, array $match): string
    {
        if ($tag !== 'digiforge_admin_portal' || ! is_user_logged_in() || ! current_user_can('manage_digiforge_products')) {
            return $output;
        }
        $view = isset($_GET['df_view']) ? sanitize_key(wp_unslash($_GET['df_view'])) : 'dashboard';
        if (in_array($view, ['approvals', 'research'], true)) {
            $output = (string) preg_replace('~<form class="df-review-form"[^>]*>\s*<input type="hidden" name="action" value="digiforge_portal_develop_candidate">.*?</form>~s', '<div class="df-muted">Approved — DigiForge Product Factory continues automatically. The next human decision is Product Approval.</div>', $output);
        }
        if ($view !== 'approvals') { return $output; }
        $panel = $this->renderPanel() . $this->renderOperationalDecisionPanels();
        $position = strrpos($output, '</main>');
        return $position === false ? $output . $panel : substr($output, 0, $position) . $panel . substr($output, $position);
    }

    private function renderOperationalDecisionPanels(): string
    {
        global $wpdb;
        $listingReviews = $wpdb->get_results(
            "SELECT r.id,r.listing_id,r.decision,r.created_at,l.title,l.environment FROM " . Tables::listing_readiness_reviews() . " r INNER JOIN " . Tables::listings() . " l ON l.id=r.listing_id WHERE r.decision='PENDING' ORDER BY r.id DESC LIMIT 50",
            ARRAY_A
        );
        $personalization = $wpdb->get_results(
            "SELECT p.id,p.order_line_item_id,p.personalization_schema_id,p.review_status,p.environment,p.created_at FROM " . Tables::personalization_submissions() . " p WHERE p.review_status NOT IN ('APPROVED','REJECTED') ORDER BY p.id DESC LIMIT 50",
            ARRAY_A
        );
        $podReviews = $wpdb->get_results(
            "SELECT r.id,r.provider_mapping_id,r.decision,r.created_at,m.provider,m.environment FROM " . Tables::pod_readiness_reviews() . " r INNER JOIN " . Tables::pod_mappings() . " m ON m.id=r.provider_mapping_id WHERE r.decision='PENDING' ORDER BY r.id DESC LIMIT 50",
            ARRAY_A
        );
        $fulfillmentReviews = $wpdb->get_results(
            "SELECT r.id,r.order_id,r.fulfillment_plan_id,r.decision,r.created_at,p.provider,p.environment,p.state FROM " . Tables::fulfillment_readiness_reviews() . " r LEFT JOIN " . Tables::fulfillment_plans() . " p ON p.id=r.fulfillment_plan_id WHERE r.decision='PENDING' ORDER BY r.id DESC LIMIT 50",
            ARRAY_A
        );
        $groups = [
            'Listing / publish decisions (Gate 3)' => is_array($listingReviews) ? $listingReviews : [],
            'Personalization exceptions' => is_array($personalization) ? $personalization : [],
            'POD readiness exceptions' => is_array($podReviews) ? $podReviews : [],
            'Fulfillment exceptions' => is_array($fulfillmentReviews) ? $fulfillmentReviews : [],
        ];
        ob_start(); ?>
        <section class="df-panel df-u3-operational-approvals">
            <div class="df-panel-head"><div><h2>Operational approval & exception inbox</h2><p>Stage-F convergence view for pending listing/publish, personalization, POD and fulfillment decisions. This panel is read-only and cannot activate or execute an external action.</p></div></div>
            <?php foreach ($groups as $title => $rows) : ?>
                <div class="df-subpanel"><h4><?php echo esc_html($title); ?></h4>
                <?php if ($rows === []) : ?><div class="df-empty">No pending items.</div><?php else : ?>
                    <div class="df-table-wrap"><table class="df-table"><thead><tr><th>ID</th><th>Subject</th><th>Environment</th><th>Status</th><th>Created</th></tr></thead><tbody>
                    <?php foreach ($rows as $row) :
                        $id = (int) ($row['id'] ?? 0);
                        $environment = (string) ($row['environment'] ?? '');
                        $status = (string) ($row['decision'] ?? $row['review_status'] ?? $row['state'] ?? 'PENDING');
                        $subject = isset($row['listing_id']) ? 'Listing #' . (int) $row['listing_id']
                            : (isset($row['order_line_item_id']) ? 'Order line #' . (int) $row['order_line_item_id']
                            : (isset($row['provider_mapping_id']) ? 'POD mapping #' . (int) $row['provider_mapping_id']
                            : 'Order #' . (int) ($row['order_id'] ?? 0))); ?>
                        <tr><td><?php echo esc_html((string) $id); ?></td><td><?php echo esc_html($subject); ?></td><td><?php echo esc_html($environment); ?></td><td><?php echo esc_html($status); ?></td><td><?php echo esc_html((string) ($row['created_at'] ?? '')); ?></td></tr>
                    <?php endforeach; ?></tbody></table></div>
                <?php endif; ?></div>
            <?php endforeach; ?>
            <div class="df-muted">Use the dedicated Listings, POD/Personalization, or Orders/Fulfillment workflow to make the governed decision. No approval is inferred from this aggregation view.</div>
        </section><?php
        return (string) ob_get_clean();
    }

    public function review(): void
    {
        if (! is_user_logged_in() || ! current_user_can('manage_digiforge_products') || ! current_user_can('manage_digiforge_production')) {
            wp_die(esc_html__('You are not authorized to review DigiForge products.', 'digiforge'));
        }
        check_admin_referer(self::ACTION);
        $versionId = isset($_POST['product_version_id']) ? absint($_POST['product_version_id']) : 0;
        $decision = isset($_POST['decision']) ? strtoupper(sanitize_key(wp_unslash($_POST['decision']))) : '';
        $notes = isset($_POST['notes']) ? sanitize_textarea_field(wp_unslash($_POST['notes'])) : '';
        $result = (new ProductReview())->decide($versionId, $decision, $notes);
        if (is_wp_error($result)) { $this->redirect($result->get_error_message(), true); }
        Logger::audit('portal_u3_product_review_completed', ['product_version_id' => $versionId, 'decision' => $decision], 'product_version', (string) $versionId);
        $this->redirect(sprintf('Product version #%d marked %s.', $versionId, $decision));
    }

    private function renderPanel(): string
    {
        $rows = $this->pendingProducts();
        ob_start(); ?>
        <section class="df-panel df-u3-product-approvals">
            <div class="df-panel-head"><div><h2>Product approval inbox</h2><p>Gate 2 — inspect protected product files, marketing assets, latest-revision deterministic QA and semantic/policy QA evidence before deciding.</p></div><span><?php echo esc_html((string) count($rows)); ?> need decision</span></div>
            <div class="df-muted">Gate 2 remains human-controlled. Product Approval does not activate Etsy publishing, POD fulfillment, orders or tax automation.</div>
            <?php if ($rows === []) : ?><div class="df-empty">No finished products currently require Product Approval.</div><?php else : foreach ($rows as $row) : ?>
                <article class="df-candidate">
                    <div class="df-candidate-head"><div><span class="df-kicker">Product #<?php echo esc_html((string) $row['product_id']); ?> · Version #<?php echo esc_html((string) $row['product_version_id']); ?></span><h3><?php echo esc_html((string) $row['product_name']); ?></h3><span class="df-status"><?php echo esc_html((string) $row['plan_state']); ?></span></div><div class="df-score"><strong><?php echo esc_html((string) $row['asset_count']); ?></strong><span> assets</span></div></div>
                    <div class="df-signal-grid"><div><span>Channel</span><b><?php echo esc_html(strtoupper((string) $row['channel'])); ?></b></div><div><span>QA blockers</span><b><?php echo esc_html((string) $row['qa_failures']); ?></b></div><div><span>Semantic QA</span><b><?php echo esc_html((string) $row['semantic_qa_status']); ?></b></div><div><span>Bundle</span><b><?php echo esc_html((string) ($row['bundle_state'] ?: 'MISSING')); ?></b></div><div><span>Workflow</span><b>PRODUCT_REVIEW_REQUIRED</b></div></div>
                    <?php $this->renderAssets((array) $row['assets']); $this->renderSemanticQa((array) $row['semantic_checks']); ?>
                    <?php if ((int) $row['qa_failures'] > 0 || (string) $row['semantic_qa_status'] !== 'PASS') : ?><div class="df-notice df-notice-error">QA blockers exist. Approval remains fail-closed until latest-revision deterministic QA and all seven semantic/policy checks pass.</div><?php endif; ?>
                    <form class="df-review-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>"><input type="hidden" name="product_version_id" value="<?php echo esc_attr((string) $row['product_version_id']); ?>"><?php wp_nonce_field(self::ACTION); ?><textarea name="notes" rows="2" placeholder="Optional product review notes"></textarea><div class="df-actions"><button class="df-button df-button-primary" name="decision" value="APPROVED" <?php disabled((int) $row['qa_failures'] > 0 || (string) $row['semantic_qa_status'] !== 'PASS'); ?>>Approve Product</button><button class="df-button df-button-danger" name="decision" value="REJECTED">Reject / Revise</button></div></form>
                </article>
            <?php endforeach; endif; ?>
        </section><?php
        return (string) ob_get_clean();
    }

    /** @param list<array<string,mixed>> $assets */
    private function renderAssets(array $assets): void
    {
        $groups = ['product' => ['title' => 'Customer / production assets', 'types' => ['product_asset', 'product_package']], 'evidence' => ['title' => 'Production evidence / provenance', 'types' => ['evidence_asset']], 'marketing' => ['title' => 'Marketing / listing assets', 'types' => ['marketing_asset']]];
        foreach ($groups as $group) {
            $matches = array_values(array_filter($assets, static fn(array $asset): bool => in_array((string) ($asset['asset_type'] ?? ''), $group['types'], true)));
            if ($matches === []) { continue; } ?>
            <div class="df-subpanel"><h4><?php echo esc_html((string) $group['title']); ?></h4><div class="df-stack"><?php foreach ($matches as $asset) : ?><div class="df-row"><div><strong><?php echo esc_html((string) $asset['filename']); ?></strong><div class="df-muted"><?php echo esc_html((string) $asset['purpose']); ?> · <?php echo esc_html(strtoupper((string) $asset['format'])); ?> · <?php echo esc_html(size_format((int) $asset['byte_size'])); ?></div></div><div class="df-actions"><span class="df-status"><?php echo esc_html((string) $asset['revision_state']); ?></span><a class="df-button" target="_blank" rel="noopener" href="<?php echo esc_url($this->assetReviewUrl((int) $asset['revision_id'])); ?>">Review file</a></div></div><?php endforeach; ?></div></div><?php
        }
    }

    /** @param list<array<string,mixed>> $checks */
    private function renderSemanticQa(array $checks): void
    {
        if ($checks === []) { return; } ?>
        <div class="df-subpanel"><h4>Semantic / policy QA evidence</h4><div class="df-stack">
            <?php foreach ($checks as $check) : ?><div class="df-row"><div><strong><?php echo esc_html($this->checkLabel((string) $check['check_type'])); ?></strong><div class="df-muted"><?php echo esc_html((string) ($check['details'] ?: 'No reviewer details recorded.')); ?></div></div><span class="df-status"><?php echo esc_html((string) $check['status']); ?></span></div><?php endforeach; ?>
        </div></div><?php
    }

    private function checkLabel(string $type): string
    {
        return ucwords(str_replace('_', ' ', preg_replace('/^semantic_/', '', $type) ?: $type));
    }

    /** @return list<array<string,mixed>> */
    private function pendingProducts(): array
    {
        global $wpdb;
        $sql = "SELECT pp.id AS plan_id,pp.product_version_id,pp.channel,pp.state AS plan_state,pv.version_label,p.id AS product_id,p.name AS product_name,rb.id AS bundle_id,rb.state AS bundle_state FROM " . Tables::production_plans() . " pp INNER JOIN " . Tables::product_versions() . " pv ON pv.id=pp.product_version_id INNER JOIN " . Tables::products() . " p ON p.id=pv.product_id LEFT JOIN " . Tables::release_bundles() . " rb ON rb.production_plan_id=pp.id WHERE pp.state='REVIEW_REQUIRED' ORDER BY pp.id DESC LIMIT 50";
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (! is_array($rows)) { return []; }
        foreach ($rows as &$row) {
            $planId = (int) $row['plan_id'];
            $row['asset_count'] = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Tables::production_plan_assets() . ' WHERE production_plan_id=%d AND is_required=1', $planId));
            $row['assets'] = $this->assetsForPlan($planId);
            $revisionFailures = 0;
            foreach ((array) $row['assets'] as $asset) {
                $revisionId = (int) ($asset['revision_id'] ?? 0);
                if ($revisionId < 1 || (string) ($asset['revision_state'] ?? '') !== 'QA_PASSED') { $revisionFailures++; continue; }
                $qaTotal = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . Tables::production_qa() . " WHERE target_type='revision' AND target_id=%d", $revisionId));
                $qaBad = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . Tables::production_qa() . " WHERE target_type='revision' AND target_id=%d AND status NOT IN ('PASS','WAIVED')", $revisionId));
                if ($qaTotal < 1 || $qaBad > 0) { $revisionFailures++; }
            }
            if (count((array) $row['assets']) < (int) $row['asset_count']) { $revisionFailures += (int) $row['asset_count'] - count((array) $row['assets']); }
            $row['semantic_checks'] = $this->semanticChecks($planId);
            $semanticTotal = count((array) $row['semantic_checks']);
            $semanticFailures = count(array_filter((array) $row['semantic_checks'], static fn(array $check): bool => ! in_array((string) ($check['status'] ?? ''), ['PASS', 'WAIVED'], true)));
            $row['qa_failures'] = $revisionFailures + $semanticFailures;
            $row['semantic_qa_status'] = $semanticTotal >= 7 && $semanticFailures === 0 ? 'PASS' : 'BLOCKED';
        }
        unset($row);
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function semanticChecks(int $planId): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT check_type,status,details,created_at FROM " . Tables::production_qa() . " WHERE target_type='plan' AND target_id=%d AND check_type LIKE 'semantic_%%' ORDER BY id ASC", $planId), ARRAY_A);
        return is_array($rows) ? array_values($rows) : [];
    }

    /** @return list<array<string,mixed>> */
    private function assetsForPlan(int $planId): array
    {
        global $wpdb;
        $sql = 'SELECT s.asset_key,s.asset_type,s.purpose,s.format,ar.id AS revision_id,ar.storage_reference,ar.mime_type,ar.byte_size,ar.state AS revision_state FROM ' . Tables::production_plan_assets() . ' pa INNER JOIN ' . Tables::asset_specs() . ' s ON s.id=pa.asset_spec_id INNER JOIN ' . Tables::asset_revisions() . ' ar ON ar.id=(SELECT ar2.id FROM ' . Tables::asset_revisions() . ' ar2 WHERE ar2.asset_spec_id=s.id ORDER BY ar2.id DESC LIMIT 1) WHERE pa.production_plan_id=%d AND pa.is_required=1 ORDER BY pa.sequence_no ASC';
        $rows = $wpdb->get_results($wpdb->prepare($sql, $planId), ARRAY_A);
        if (! is_array($rows)) { return []; }
        foreach ($rows as &$row) { $row['filename'] = sanitize_file_name(basename((string) ($row['storage_reference'] ?? 'asset'))); }
        unset($row);
        return array_values($rows);
    }

    private function assetReviewUrl(int $revisionId): string
    {
        return wp_nonce_url(add_query_arg(['action' => U3AssetReview::ACTION, 'revision_id' => $revisionId], admin_url('admin-post.php')), U3AssetReview::nonceAction($revisionId));
    }

    private function redirect(string $message, bool $error = false): never
    {
        wp_safe_redirect(add_query_arg(['df_view' => 'approvals', 'df_message' => $message, 'df_error' => $error ? '1' : '0'], home_url('/')));
        exit;
    }
}
