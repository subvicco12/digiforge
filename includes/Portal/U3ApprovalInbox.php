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
            $output = (string) preg_replace(
                '~<form class="df-review-form"[^>]*>\s*<input type="hidden" name="action" value="digiforge_portal_develop_candidate">.*?</form>~s',
                '<div class="df-muted">Approved — DigiForge Product Factory continues automatically. The next human decision is Product Approval.</div>',
                $output
            );
        }
        if ($view !== 'approvals') { return $output; }

        $panel = $this->renderPanel();
        $position = strrpos($output, '</main>');
        if ($position === false) { return $output . $panel; }
        return substr($output, 0, $position) . $panel . substr($output, $position);
    }

    public function review(): void
    {
        if (! is_user_logged_in()
            || ! current_user_can('manage_digiforge_products')
            || ! current_user_can('manage_digiforge_production')) {
            wp_die(esc_html__('You are not authorized to review DigiForge products.', 'digiforge'));
        }
        check_admin_referer(self::ACTION);
        $versionId = isset($_POST['product_version_id']) ? absint($_POST['product_version_id']) : 0;
        $decision = isset($_POST['decision']) ? strtoupper(sanitize_key(wp_unslash($_POST['decision']))) : '';
        $notes = isset($_POST['notes']) ? sanitize_textarea_field(wp_unslash($_POST['notes'])) : '';
        $result = (new ProductReview())->decide($versionId, $decision, $notes);
        if (is_wp_error($result)) {
            $this->redirect($result->get_error_message(), true);
        }
        Logger::audit('portal_u3_product_review_completed', [
            'product_version_id' => $versionId,
            'decision' => $decision,
        ], 'product_version', (string) $versionId);
        $this->redirect(sprintf('Product version #%d marked %s.', $versionId, $decision));
    }

    private function renderPanel(): string
    {
        $rows = $this->pendingProducts();
        ob_start();
        ?>
        <section class="df-panel df-u3-product-approvals">
            <div class="df-panel-head"><div>
                <h2>Product approval inbox</h2>
                <p>Gate 2 — inspect protected product files and marketing assets, then review deterministic and semantic/policy QA before listing production.</p>
            </div><span><?php echo esc_html((string) count($rows)); ?> need decision</span></div>
            <?php if ($rows === []) : ?>
                <div class="df-empty">No finished products currently require Product Approval.</div>
            <?php else : ?>
                <?php foreach ($rows as $row) : ?>
                    <article class="df-candidate">
                        <div class="df-candidate-head"><div>
                            <span class="df-kicker">Product #<?php echo esc_html((string) $row['product_id']); ?> · Version #<?php echo esc_html((string) $row['product_version_id']); ?></span>
                            <h3><?php echo esc_html((string) $row['product_name']); ?></h3>
                            <span class="df-status"><?php echo esc_html((string) $row['plan_state']); ?></span>
                        </div><div class="df-score"><strong><?php echo esc_html((string) $row['asset_count']); ?></strong><span> assets</span></div></div>
                        <div class="df-signal-grid">
                            <div><span>Channel</span><b><?php echo esc_html(strtoupper((string) $row['channel'])); ?></b></div>
                            <div><span>QA blockers</span><b><?php echo esc_html((string) $row['qa_failures']); ?></b></div>
                            <div><span>Semantic QA</span><b><?php echo esc_html((string) $row['semantic_qa_status']); ?></b></div>
                            <div><span>Bundle</span><b><?php echo esc_html((string) ($row['bundle_state'] ?: 'MISSING')); ?></b></div>
                            <div><span>Workflow</span><b>PRODUCT_REVIEW_REQUIRED</b></div>
                        </div>
                        <?php $this->renderAssets((array) $row['assets']); ?>
                        <?php if ((int) $row['qa_failures'] > 0 || (string) $row['semantic_qa_status'] !== 'PASS') : ?>
                            <div class="df-notice df-notice-error">QA blockers exist. Approval remains fail-closed until deterministic and semantic QA pass.</div>
                        <?php endif; ?>
                        <form class="df-review-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
                            <input type="hidden" name="product_version_id" value="<?php echo esc_attr((string) $row['product_version_id']); ?>">
                            <?php wp_nonce_field(self::ACTION); ?>
                            <textarea name="notes" rows="2" placeholder="Optional product review notes"></textarea>
                            <div class="df-actions">
                                <button class="df-button df-button-primary" name="decision" value="APPROVED" <?php disabled((int) $row['qa_failures'] > 0 || (string) $row['semantic_qa_status'] !== 'PASS'); ?>>Approve Product</button>
                                <button class="df-button df-button-danger" name="decision" value="REJECTED">Reject / Revise</button>
                            </div>
                        </form>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    /** @param list<array<string,mixed>> $assets */
    private function renderAssets(array $assets): void
    {
        $groups = [
            'product' => ['title' => 'Customer / production assets', 'types' => ['product_asset', 'product_package']],
            'marketing' => ['title' => 'Marketing / listing assets', 'types' => ['marketing_asset']],
        ];
        foreach ($groups as $group) {
            $matches = array_values(array_filter(
                $assets,
                static fn(array $asset): bool => in_array((string) ($asset['asset_type'] ?? ''), $group['types'], true)
            ));
            if ($matches === []) { continue; }
            ?>
            <div class="df-subpanel">
                <h4><?php echo esc_html((string) $group['title']); ?></h4>
                <div class="df-stack">
                    <?php foreach ($matches as $asset) : ?>
                        <div class="df-row">
                            <div>
                                <strong><?php echo esc_html((string) $asset['filename']); ?></strong>
                                <div class="df-muted"><?php echo esc_html((string) $asset['purpose']); ?> · <?php echo esc_html(strtoupper((string) $asset['format'])); ?> · <?php echo esc_html(size_format((int) $asset['byte_size'])); ?></div>
                            </div>
                            <div class="df-actions">
                                <span class="df-status"><?php echo esc_html((string) $asset['revision_state']); ?></span>
                                <a class="df-button" target="_blank" rel="noopener" href="<?php echo esc_url($this->assetReviewUrl((int) $asset['revision_id'])); ?>">Review file</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php
        }
    }

    /** @return list<array<string,mixed>> */
    private function pendingProducts(): array
    {
        global $wpdb;
        $sql = "SELECT pp.id AS plan_id,pp.product_version_id,pp.channel,pp.state AS plan_state,"
            . "pv.version_label,p.id AS product_id,p.name AS product_name,rb.id AS bundle_id,rb.state AS bundle_state "
            . 'FROM ' . Tables::production_plans() . ' pp '
            . 'INNER JOIN ' . Tables::product_versions() . ' pv ON pv.id=pp.product_version_id '
            . 'INNER JOIN ' . Tables::products() . ' p ON p.id=pv.product_id '
            . 'LEFT JOIN ' . Tables::release_bundles() . ' rb ON rb.production_plan_id=pp.id '
            . "WHERE pp.state='REVIEW_REQUIRED' ORDER BY pp.id DESC LIMIT 50";
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (! is_array($rows)) { return []; }
        foreach ($rows as &$row) {
            $planId = (int) $row['plan_id'];
            $row['asset_count'] = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . Tables::production_plan_assets() . ' WHERE production_plan_id=%d AND is_required=1',
                $planId
            ));
            $revisionFailures = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . Tables::production_qa() . ' q '
                . 'INNER JOIN ' . Tables::asset_revisions() . " ar ON q.target_type='revision' AND q.target_id=ar.id "
                . 'INNER JOIN ' . Tables::production_plan_assets() . ' pa ON pa.asset_spec_id=ar.asset_spec_id '
                . "WHERE pa.production_plan_id=%d AND q.status NOT IN ('PASS','WAIVED')",
                $planId
            ));
            $semanticTotal = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM " . Tables::production_qa() . " WHERE target_type='plan' AND target_id=%d AND check_type LIKE 'semantic_%%'",
                $planId
            ));
            $semanticFailures = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM " . Tables::production_qa() . " WHERE target_type='plan' AND target_id=%d AND check_type LIKE 'semantic_%%' AND status NOT IN ('PASS','WAIVED')",
                $planId
            ));
            $row['qa_failures'] = $revisionFailures + $semanticFailures;
            $row['semantic_qa_status'] = $semanticTotal >= 7 && $semanticFailures === 0 ? 'PASS' : 'BLOCKED';
            $row['assets'] = $this->assetsForPlan($planId);
        }
        unset($row);
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function assetsForPlan(int $planId): array
    {
        global $wpdb;
        $sql = 'SELECT s.asset_key,s.asset_type,s.purpose,s.format,ar.id AS revision_id,ar.storage_reference,'
            . 'ar.mime_type,ar.byte_size,ar.state AS revision_state '
            . 'FROM ' . Tables::production_plan_assets() . ' pa '
            . 'INNER JOIN ' . Tables::asset_specs() . ' s ON s.id=pa.asset_spec_id '
            . 'INNER JOIN ' . Tables::asset_revisions() . ' ar ON ar.id=('
            . 'SELECT ar2.id FROM ' . Tables::asset_revisions() . ' ar2 WHERE ar2.asset_spec_id=s.id ORDER BY ar2.id DESC LIMIT 1) '
            . 'WHERE pa.production_plan_id=%d AND pa.is_required=1 ORDER BY pa.sequence_no ASC';
        $rows = $wpdb->get_results($wpdb->prepare($sql, $planId), ARRAY_A);
        if (! is_array($rows)) { return []; }
        foreach ($rows as &$row) {
            $row['filename'] = sanitize_file_name(basename((string) ($row['storage_reference'] ?? 'asset')));
        }
        unset($row);
        return array_values($rows);
    }

    private function assetReviewUrl(int $revisionId): string
    {
        $url = add_query_arg([
            'action' => U3AssetReview::ACTION,
            'revision_id' => $revisionId,
        ], admin_url('admin-post.php'));
        return wp_nonce_url($url, U3AssetReview::nonceAction($revisionId));
    }

    private function redirect(string $message, bool $error = false): never
    {
        $url = add_query_arg([
            'df_view' => 'approvals',
            'df_message' => $message,
            'df_error' => $error ? '1' : '0',
        ], home_url('/'));
        wp_safe_redirect($url);
        exit;
    }
}
