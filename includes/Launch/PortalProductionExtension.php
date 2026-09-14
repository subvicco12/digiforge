<?php

declare(strict_types=1);

namespace DigiForge\Launch;

use DigiForge\Database\Tables;
use DigiForge\Security\Logger;

/** Extends the existing portal without weakening its research approval handler. */
final class PortalProductionExtension
{
    private const RESEARCH_ACTION = 'digiforge_portal_review_research';
    private const PRODUCT_ACTION = 'digiforge_portal_review_product';
    private const ARTIFACT_ACTION = 'digiforge_portal_artifact';

    public function register(): void
    {
        add_action('admin_post_' . self::RESEARCH_ACTION, [$this, 'queueApprovedResearch'], 5);
        add_action('admin_post_' . self::PRODUCT_ACTION, [$this, 'reviewProduct']);
        add_action('admin_post_' . self::ARTIFACT_ACTION, [$this, 'artifact']);
        add_filter('do_shortcode_tag', [$this, 'injectProductReview'], 10, 2);
    }

    public function queueApprovedResearch(): void
    {
        if (! current_user_can('manage_digiforge_research')) {
            return;
        }
        check_admin_referer(self::RESEARCH_ACTION);
        $decision = isset($_POST['decision']) ? strtoupper(sanitize_key(wp_unslash($_POST['decision']))) : '';
        if ($decision !== 'APPROVED') {
            return;
        }
        $candidateId = isset($_POST['candidate_id']) ? absint($_POST['candidate_id']) : 0;
        if ($candidateId < 1) {
            return;
        }
        $shop = $this->candidateShop($candidateId);
        $queued = (new ProductProductionWorker())->queue($candidateId, $shop);
        if (is_wp_error($queued)) {
            Logger::audit('portal_product_production_queue_failed', [
                'candidate_id' => $candidateId,
                'reason' => $queued->get_error_code(),
            ], 'research_candidate', (string) $candidateId);
            return;
        }
        Logger::audit('portal_product_production_queue_created', [
            'candidate_id' => $candidateId,
            'job_id' => (int) $queued,
            'shop' => $shop,
        ], 'research_candidate', (string) $candidateId);
    }

    public function reviewProduct(): void
    {
        if (! current_user_can('manage_digiforge_production')) {
            wp_die(esc_html__('You are not authorized to review DigiForge products.', 'digiforge'), 403);
        }
        check_admin_referer(self::PRODUCT_ACTION);
        $productVersionId = isset($_POST['product_version_id']) ? absint($_POST['product_version_id']) : 0;
        $decision = isset($_POST['decision']) ? strtoupper(sanitize_key(wp_unslash($_POST['decision']))) : '';
        $result = (new ProductProductionEngine())->approve($productVersionId, $decision);
        if (is_wp_error($result)) {
            $this->redirect($result->get_error_message(), true);
        }
        $message = $decision === 'APPROVED'
            ? sprintf('Product version #%d approved. Listing preparation is the next gated stage.', $productVersionId)
            : sprintf('Product version #%d rejected.', $productVersionId);
        $this->redirect($message, false);
    }

    public function artifact(): void
    {
        if (! current_user_can('manage_digiforge_production')) {
            wp_die(esc_html__('You are not authorized to access DigiForge product artifacts.', 'digiforge'), 403);
        }
        $reference = isset($_GET['ref']) ? sanitize_text_field(wp_unslash($_GET['ref'])) : '';
        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
        if ($reference === '' || ! wp_verify_nonce($nonce, self::ARTIFACT_ACTION . ':' . hash('sha256', $reference))) {
            wp_die(esc_html__('Invalid artifact request.', 'digiforge'), 403);
        }
        $path = (new ArtifactStorage())->resolve($reference);
        if (is_wp_error($path) || ! is_file($path)) {
            wp_die(esc_html__('Product artifact was not found.', 'digiforge'), 404);
        }

        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $mime = match ($extension) {
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'pdf' => 'application/pdf',
            'zip' => 'application/zip',
            'html', 'htm' => 'text/html; charset=UTF-8',
            'json' => 'application/json; charset=UTF-8',
            default => 'application/octet-stream',
        };
        $inline = isset($_GET['inline']) && (string) $_GET['inline'] === '1' && in_array($extension, ['svg', 'png', 'jpg', 'jpeg', 'pdf'], true);
        nocache_headers();
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; img-src data:; style-src 'unsafe-inline'");
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) filesize($path));
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . sanitize_file_name(basename($path)) . '"');
        readfile($path);
        exit;
    }

    public function injectProductReview(string $output, string $tag): string
    {
        if ($tag !== 'digiforge_admin_portal' || ! is_user_logged_in() || ! current_user_can('manage_digiforge_production')) {
            return $output;
        }
        $view = isset($_GET['df_view']) ? sanitize_key(wp_unslash($_GET['df_view'])) : 'dashboard';
        if ($view !== 'approvals') {
            return $output;
        }
        $panel = $this->productReviewPanel();
        $position = strrpos($output, '</main>');
        if ($position === false) {
            return $output . $panel;
        }
        return substr($output, 0, $position) . $panel . substr($output, $position);
    }

    private function productReviewPanel(): string
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            'SELECT pp.id AS plan_id,pp.product_version_id,pp.state AS plan_state,rb.id AS bundle_id,rb.state AS bundle_state,'
            . 'p.id AS product_id,p.name AS product_name,dp.id AS digital_product_id,dp.state AS digital_state '
            . 'FROM ' . Tables::production_plans() . ' pp '
            . 'INNER JOIN ' . Tables::product_versions() . ' pv ON pv.id=pp.product_version_id '
            . 'INNER JOIN ' . Tables::products() . ' p ON p.id=pv.product_id '
            . 'INNER JOIN ' . Tables::release_bundles() . ' rb ON rb.production_plan_id=pp.id '
            . 'LEFT JOIN ' . Tables::digital_products() . ' dp ON dp.product_version_id=pp.product_version_id '
            . "WHERE pp.state='REVIEW_REQUIRED' AND rb.state='REVIEW_REQUIRED' ORDER BY pp.id DESC LIMIT 20",
            ARRAY_A
        );

        ob_start();
        ?>
        <section class="df-panel df-product-review-panel">
            <div class="df-panel-head"><div><h2>Product approval inbox</h2>
                <p>Review the actual generated files and listing images before listing preparation begins.</p></div></div>
            <?php if (! is_array($rows) || $rows === []) : ?>
                <div class="df-empty">No completed products are waiting for approval.</div>
            <?php else : ?>
                <?php foreach ($rows as $row) : ?>
                    <?php $assets = $this->assets((int) $row['plan_id']); ?>
                    <article class="df-candidate df-product-review-card">
                        <div class="df-candidate-head"><div>
                            <span class="df-kicker">Product version #<?php echo esc_html((string) $row['product_version_id']); ?></span>
                            <h3><?php echo esc_html((string) $row['product_name']); ?></h3>
                            <span class="df-status">PRODUCT REVIEW REQUIRED</span>
                        </div></div>
                        <p>Digital state: <?php echo esc_html((string) ($row['digital_state'] ?? '')); ?> · Production plan: <?php echo esc_html((string) $row['plan_state']); ?></p>
                        <?php $this->renderAssetPreviews($assets); ?>
                        <form class="df-review-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="<?php echo esc_attr(self::PRODUCT_ACTION); ?>">
                            <input type="hidden" name="product_version_id" value="<?php echo esc_attr((string) $row['product_version_id']); ?>">
                            <?php wp_nonce_field(self::PRODUCT_ACTION); ?>
                            <div class="df-actions">
                                <button class="df-button df-button-primary" name="decision" value="APPROVED">Approve product</button>
                                <button class="df-button df-button-danger" name="decision" value="REJECTED">Reject product</button>
                            </div>
                        </form>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    /** @return array<int,array<string,mixed>> */
    private function assets(int $planId): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT s.asset_type,s.purpose,ar.storage_reference,ar.mime_type,ar.byte_size,ar.width_px,ar.height_px '
            . 'FROM ' . Tables::production_plan_assets() . ' pa '
            . 'INNER JOIN ' . Tables::asset_specs() . ' s ON s.id=pa.asset_spec_id '
            . 'INNER JOIN ' . Tables::asset_revisions() . ' ar ON ar.asset_spec_id=s.id '
            . "WHERE pa.production_plan_id=%d AND ar.state='APPROVED' ORDER BY pa.sequence_no ASC,s.id ASC,ar.id DESC",
            $planId
        ), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    /** @param array<int,array<string,mixed>> $assets */
    private function renderAssetPreviews(array $assets): void
    {
        $images = array_values(array_filter($assets, static fn(array $asset): bool => (string) ($asset['asset_type'] ?? '') === 'listing_image'));
        $package = null;
        foreach ($assets as $asset) {
            if ((string) ($asset['asset_type'] ?? '') === 'customer_package') {
                $package = $asset;
                break;
            }
        }
        if ($images !== []) {
            echo '<div class="df-product-preview-grid">';
            foreach (array_slice($images, 0, 6) as $image) {
                $reference = (string) $image['storage_reference'];
                echo '<figure><img loading="lazy" src="' . esc_url($this->artifactUrl($reference, true)) . '" alt="' . esc_attr((string) $image['purpose']) . '"><figcaption>' . esc_html((string) $image['purpose']) . '</figcaption></figure>';
            }
            echo '</div>';
        }
        if (is_array($package)) {
            echo '<p><a class="df-button" href="' . esc_url($this->artifactUrl((string) $package['storage_reference'], false)) . '">Download customer package</a></p>';
        }
    }

    private function artifactUrl(string $reference, bool $inline): string
    {
        $url = add_query_arg([
            'action' => self::ARTIFACT_ACTION,
            'ref' => $reference,
            'inline' => $inline ? '1' : '0',
        ], admin_url('admin-post.php'));
        return wp_nonce_url($url, self::ARTIFACT_ACTION . ':' . hash('sha256', $reference));
    }

    private function candidateShop(int $candidateId): string
    {
        global $wpdb;
        $config = $wpdb->get_var($wpdb->prepare(
            'SELECT s.config FROM ' . Tables::research_candidate_evidence() . ' ce '
            . 'INNER JOIN ' . Tables::research_evidence() . ' e ON e.id=ce.evidence_id '
            . 'INNER JOIN ' . Tables::research_observations() . ' o ON o.id=e.observation_id '
            . 'INNER JOIN ' . Tables::research_sources() . ' s ON s.id=o.source_id '
            . 'WHERE ce.candidate_id=%d ORDER BY e.id ASC LIMIT 1',
            $candidateId
        ));
        if (is_string($config)) {
            $decoded = json_decode($config, true);
            $shop = is_array($decoded) ? sanitize_key((string) ($decoded['shop'] ?? '')) : '';
            if (in_array($shop, ['digital', 'goods'], true)) {
                return $shop;
            }
        }
        return 'digital';
    }

    private function redirect(string $message, bool $error): void
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
