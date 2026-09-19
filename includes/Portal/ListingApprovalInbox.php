<?php
declare(strict_types=1);

namespace DigiForge\Portal;

use DigiForge\Database\Tables;
use DigiForge\Listings\Repository as ListingRepository;
use DigiForge\Security\Logger;
use WP_Error;

/**
 * Gate 3 review UI for the exact local Etsy listing package.
 *
 * Approval prepares immutable local draft evidence and a BLOCKED Etsy intent.
 * It never calls Etsy and never authorizes external publication.
 */
final class ListingApprovalInbox
{
    private const ACTION = 'digiforge_listing_review';

    public function register(): void
    {
        add_filter('do_shortcode_tag', [$this, 'appendPanel'], 40, 4);
        add_action('admin_post_' . self::ACTION, [$this, 'review']);
    }

    /** @param array<string,mixed> $attr @param array<int,string> $match */
    public function appendPanel(string $output, string $tag, array $attr, array $match): string
    {
        if ($tag !== 'digiforge_admin_portal'
            || ! is_user_logged_in()
            || ! current_user_can('manage_digiforge_listings')) {
            return $output;
        }
        $view = isset($_GET['df_view']) ? sanitize_key(wp_unslash($_GET['df_view'])) : 'dashboard';
        if ($view !== 'approvals') {
            return $output;
        }

        $panel = $this->renderPanel();
        $position = strrpos($output, '</main>');
        return $position === false
            ? $output . $panel
            : substr($output, 0, $position) . $panel . substr($output, $position);
    }

    public function review(): void
    {
        if (! is_user_logged_in() || ! current_user_can('manage_digiforge_listings')) {
            wp_die(esc_html__('You are not authorized to review DigiForge listings.', 'digiforge'), '', ['response' => 403]);
        }
        check_admin_referer(self::ACTION);

        $listingId = isset($_POST['listing_id']) ? absint($_POST['listing_id']) : 0;
        $decision = isset($_POST['decision']) ? strtoupper(sanitize_key(wp_unslash($_POST['decision']))) : '';
        if ($listingId < 1 || ! in_array($decision, ['APPROVED','REJECTED'], true)) {
            $this->redirect('Invalid listing review request.', true);
        }

        $repo = new ListingRepository();
        if ($decision === 'REJECTED') {
            $result = $repo->transition('listing', $listingId, 'REJECTED');
            if (is_wp_error($result)) {
                $this->redirect($result->get_error_message(), true);
            }
            Logger::audit('portal_listing_review_completed', [
                'listing_id' => $listingId,
                'decision' => 'REJECTED',
                'external_actions' => false,
            ], 'listing', (string) $listingId);
            $this->redirect(sprintf('Listing #%d rejected for revision.', $listingId));
        }

        $pre = $repo->readiness($listingId);
        if (is_wp_error($pre)) {
            $this->redirect($pre->get_error_message(), true);
        }
        $checks = is_array($pre['checks'] ?? null) ? $pre['checks'] : [];
        foreach (['seo_present','approved_media_bound','pod_binding_valid'] as $required) {
            if (($checks[$required] ?? false) !== true) {
                $this->redirect('Listing cannot be approved until SEO, approved media, and any required POD binding are ready.', true);
            }
        }

        $approved = $repo->transition('listing', $listingId, 'APPROVED');
        if (is_wp_error($approved)) {
            $this->redirect($approved->get_error_message(), true);
        }

        $readiness = $repo->readiness($listingId);
        if (is_wp_error($readiness) || empty($readiness['ready'])) {
            $message = is_wp_error($readiness)
                ? $readiness->get_error_message()
                : 'Listing approval did not produce complete release readiness.';
            $this->redirect($message, true);
        }

        $package = $repo->createDraftPackage([
            'listing_id' => $listingId,
            'package_version' => 'gate3-local-v1',
        ], 'gate3-package-' . $listingId);
        if (is_wp_error($package)) {
            $this->redirect($package->get_error_message(), true);
        }

        $intent = $repo->createIntent([
            'listing_id' => $listingId,
            'draft_package_id' => (int) ($package['id'] ?? 0),
            'intent_type' => 'PREPARE_DRAFT',
            'input_payload' => [
                'source' => 'gate3_listing_approval',
                'local_preparation_only' => true,
                'publish_authorized' => false,
            ],
        ], 'gate3-draft-intent-' . $listingId);
        if (is_wp_error($intent)) {
            $this->redirect($intent->get_error_message(), true);
        }

        Logger::audit('portal_listing_review_completed', [
            'listing_id' => $listingId,
            'decision' => 'APPROVED',
            'draft_package_id' => (int) ($package['id'] ?? 0),
            'etsy_intent_id' => (int) ($intent['id'] ?? 0),
            'etsy_intent_state' => (string) ($intent['state'] ?? 'BLOCKED'),
            'publish_authorized' => false,
            'etsy_api_invoked' => false,
            'external_actions' => false,
        ], 'listing', (string) $listingId);

        $this->redirect(sprintf(
            'Listing #%d approved. Local Etsy draft evidence is prepared and remains BLOCKED from external publishing.',
            $listingId
        ));
    }

    private function renderPanel(): string
    {
        $rows = $this->pendingListings();
        ob_start(); ?>
        <section class="df-panel df-listing-approvals">
            <div class="df-panel-head">
                <div>
                    <h2>Listing / publish approval inbox</h2>
                    <p>Gate 3 — review the exact listing copy, price, tags and approved marketing media before local draft preparation.</p>
                </div>
                <span><?php echo esc_html((string) count($rows)); ?> need decision</span>
            </div>
            <div class="df-muted">Approval prepares local Etsy draft evidence only. Etsy API invocation and publication remain blocked until separately activated.</div>
            <?php if ($rows === []) : ?>
                <div class="df-empty">No listings currently require Gate 3 approval.</div>
            <?php else : foreach ($rows as $row) : ?>
                <article class="df-candidate">
                    <div class="df-candidate-head">
                        <div>
                            <span class="df-kicker">Listing #<?php echo esc_html((string) $row['id']); ?> · Product version #<?php echo esc_html((string) $row['product_version_id']); ?></span>
                            <h3><?php echo esc_html((string) $row['title']); ?></h3>
                            <span class="df-status">LISTING_REVIEW_REQUIRED</span>
                        </div>
                        <div class="df-score"><strong><?php echo esc_html(number_format((float) $row['price_amount'], 2)); ?></strong><span><?php echo esc_html(' ' . (string) $row['currency']); ?></span></div>
                    </div>
                    <p><?php echo esc_html((string) $row['description']); ?></p>
                    <div class="df-signal-grid">
                        <div><span>Shop</span><b><?php echo esc_html((string) $row['shop_reference']); ?></b></div>
                        <div><span>SEO tags</span><b><?php echo esc_html((string) $row['tag_count']); ?></b></div>
                        <div><span>Approved media</span><b><?php echo esc_html((string) $row['media_count']); ?></b></div>
                        <div><span>Channel</span><b><?php echo esc_html(strtoupper((string) $row['channel'])); ?></b></div>
                        <div><span>External publish</span><b>LOCKED</b></div>
                    </div>
                    <?php if ((array) $row['tags'] !== []) : ?>
                        <div class="df-tag-list">
                            <?php foreach ((array) $row['tags'] as $tag) : ?><span><?php echo esc_html((string) $tag); ?></span><?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <form class="df-review-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
                        <input type="hidden" name="listing_id" value="<?php echo esc_attr((string) $row['id']); ?>">
                        <?php wp_nonce_field(self::ACTION); ?>
                        <div class="df-actions">
                            <button class="df-button df-button-primary" name="decision" value="APPROVED">Approve Listing & Prepare Local Draft</button>
                            <button class="df-button df-button-danger" name="decision" value="REJECTED">Reject / Revise</button>
                        </div>
                    </form>
                </article>
            <?php endforeach; endif; ?>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    /** @return list<array<string,mixed>> */
    private function pendingListings(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT l.*,s.tags FROM " . Tables::listings() . " l "
            . "LEFT JOIN " . Tables::listing_seo() . " s ON s.listing_id=l.id "
            . "WHERE l.state='REVIEW_REQUIRED' ORDER BY l.id DESC LIMIT 50",
            ARRAY_A
        );
        $rows = is_array($rows) ? $rows : [];
        foreach ($rows as &$row) {
            $tags = json_decode((string) ($row['tags'] ?? '[]'), true);
            $row['tags'] = is_array($tags) ? array_values(array_filter($tags, 'is_scalar')) : [];
            $row['tag_count'] = count((array) $row['tags']);
            $row['media_count'] = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . Tables::listing_media() . ' WHERE listing_id=%d',
                (int) $row['id']
            ));
        }
        unset($row);
        return array_values($rows);
    }

    private function redirect(string $message, bool $error = false): never
    {
        wp_safe_redirect(add_query_arg([
            'df_view' => 'approvals',
            'df_message' => $message,
            'df_error' => $error ? '1' : '0',
        ], home_url('/')));
        exit;
    }
}
