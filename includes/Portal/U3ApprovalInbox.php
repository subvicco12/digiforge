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
        $wpdb->last_error='';
        $listingReviews = $wpdb->get_results(
            "SELECT r.id,r.listing_id,r.decision,r.created_at,l.id AS subject_id,l.title,l.environment,l.updated_at AS subject_updated_at FROM " . Tables::listing_readiness_reviews() . " r LEFT JOIN " . Tables::listings() . " l ON l.id=r.listing_id WHERE r.decision='PENDING' ORDER BY r.id DESC LIMIT 50",
            ARRAY_A
        );
        if (!empty($wpdb->last_error)) $listingReviews=null;
        $wpdb->last_error='';
        $personalization = $wpdb->get_results(
            "SELECT p.id,p.order_line_item_id,p.personalization_schema_id,p.review_status,p.environment,p.created_at,li.id AS subject_id,li.environment AS line_environment,li.product_version_id AS line_product_version_id,s.id AS schema_subject_id,s.product_version_id AS schema_product_version_id,s.state AS schema_state FROM " . Tables::personalization_submissions() . " p LEFT JOIN " . Tables::order_line_items() . " li ON li.id=p.order_line_item_id LEFT JOIN " . Tables::personalization_schemas() . " s ON s.id=p.personalization_schema_id WHERE p.review_status NOT IN ('APPROVED','REJECTED') ORDER BY p.id DESC LIMIT 50",
            ARRAY_A
        );
        if (!empty($wpdb->last_error)) $personalization=null;
        $wpdb->last_error='';
        $podReviews = $wpdb->get_results(
            "SELECT r.id,r.provider_mapping_id,r.decision,r.created_at,m.id AS subject_id,m.provider,m.environment,m.updated_at AS subject_updated_at FROM " . Tables::pod_readiness_reviews() . " r LEFT JOIN " . Tables::pod_mappings() . " m ON m.id=r.provider_mapping_id WHERE r.decision='PENDING' ORDER BY r.id DESC LIMIT 50",
            ARRAY_A
        );
        if (!empty($wpdb->last_error)) $podReviews=null;
        $wpdb->last_error='';
        $fulfillmentReviews = $wpdb->get_results(
            "SELECT r.id,r.order_id,r.fulfillment_plan_id,r.decision,r.created_at,p.id AS subject_id,p.order_id AS subject_order_id,p.provider,p.environment,p.state,p.updated_at AS subject_updated_at FROM " . Tables::fulfillment_readiness_reviews() . " r LEFT JOIN " . Tables::fulfillment_plans() . " p ON p.id=r.fulfillment_plan_id WHERE r.decision='PENDING' ORDER BY r.id DESC LIMIT 50",
            ARRAY_A
        );
        if (!empty($wpdb->last_error)) $fulfillmentReviews=null;
        $groups = [
            'Listing / publish decisions (Gate 3)' => is_array($listingReviews) ? $listingReviews : null,
            'Personalization exceptions' => is_array($personalization) ? $personalization : null,
            'POD readiness exceptions' => is_array($podReviews) ? $podReviews : null,
            'Fulfillment exceptions' => is_array($fulfillmentReviews) ? $fulfillmentReviews : null,
        ];
        $pendingTotal=$this->pendingOperationalCount();
        ob_start(); ?>
        <section class="df-panel df-u3-operational-approvals" id="df-approval-downstream">
            <div class="df-panel-head"><div><h2>Consolidated approval gates</h2><p>Operational approval & exception inbox. One read-only convergence view across downstream listing/publish, personalization, POD and fulfillment decisions; it is read-only and cannot activate or execute an external action. Research Gate 1 and Product Gate 2 remain visible above in their governed workflows.</p></div><span class="df-status">NO INFERRED APPROVAL</span></div><div class="df-signal-grid"><div><span>Downstream pending decisions</span><b><?php echo esc_html($pendingTotal===null?'UNKNOWN':(string)$pendingTotal); ?></b></div><div><span>Approval authority in this aggregate</span><b>NO</b></div><div><span>External execution authority</span><b>NO</b></div></div>
            <?php foreach ($groups as $title => $rows) : ?>
                <div class="df-subpanel" id="<?php echo esc_attr(match ($title) {'Listing / publish decisions (Gate 3)'=>'df-approval-listing','Personalization exceptions'=>'df-approval-personalization','POD readiness exceptions'=>'df-approval-pod','Fulfillment exceptions'=>'df-approval-fulfillment'}); ?>"><h4><?php echo esc_html($title); ?></h4>
                <?php if ($rows === null) : ?><div class="df-notice df-notice-error">Pending evidence unavailable. Review the dedicated workflow; no empty queue is inferred.</div>
                <?php elseif ($rows === []) : ?><div class="df-empty">No pending items.</div><?php else : ?>
                    <div class="df-table-wrap"><table class="df-table"><thead><tr><th>ID</th><th>Subject</th><th>Environment</th><th>Status</th><th>Evidence</th><th>Created</th><th>Workflow</th></tr></thead><tbody>
                    <?php foreach ($rows as $row) :
                        $id = (int) ($row['id'] ?? 0);
                        $environment = (string) ($row['environment'] ?? '');
                        $status = (string) ($row['decision'] ?? $row['review_status'] ?? $row['state'] ?? 'PENDING');
                        $subject = isset($row['listing_id']) ? 'Listing #' . (int) $row['listing_id']
                            : (isset($row['order_line_item_id']) ? 'Order line #' . (int) $row['order_line_item_id']
                            : (isset($row['provider_mapping_id']) ? 'POD mapping #' . (int) $row['provider_mapping_id']
                            : 'Order #' . (int) ($row['order_id'] ?? 0))); ?>
                        <tr><td><?php echo esc_html((string) $id); ?></td><td><?php echo esc_html($subject); ?></td><td><?php echo esc_html($environment); ?></td><td><?php echo esc_html($status); ?></td><td><?php echo esc_html($this->subjectEvidenceState($row)); ?></td><td><?php echo esc_html((string) ($row['created_at'] ?? '')); ?></td>
                    <td><a class="df-button df-button-compact" href="<?php echo esc_url($this->evidenceUrl((string)$title, $id)); ?>">Open review evidence</a></td></tr>
                    <?php endforeach; ?></tbody></table></div>
                <?php endif; ?></div>
            <?php endforeach; ?>
            <div class="df-muted">The count covers all pending downstream decisions; each group displays its most recent 50. Use the dedicated Listings, POD/Personalization, or Orders/Fulfillment workflow to make the governed decision. No approval is inferred from this aggregation view. Every decision must be made in its dedicated governed workflow; this view grants no publish, production, fulfillment, refund, tax or money-movement authority.</div>
        </section><?php
        return (string) ob_get_clean();
    }

    /** Count all pending records independently of the bounded evidence rows above. */
    private function pendingOperationalCount(): ?int
    {
        global $wpdb;
        $wpdb->last_error='';
        $count=$wpdb->get_var(
            'SELECT (SELECT COUNT(*) FROM ' . Tables::listing_readiness_reviews() . " WHERE decision='PENDING')"
            . ' + (SELECT COUNT(*) FROM ' . Tables::personalization_submissions() . " WHERE review_status NOT IN ('APPROVED','REJECTED'))"
            . ' + (SELECT COUNT(*) FROM ' . Tables::pod_readiness_reviews() . " WHERE decision='PENDING')"
            . ' + (SELECT COUNT(*) FROM ' . Tables::fulfillment_readiness_reviews() . " WHERE decision='PENDING')"
        );
        return $count===null || !empty($wpdb->last_error) ? null : (int)$count;
    }

    private function workflowUrl(string $group): string
    {
        $view = str_contains($group, 'Listing') ? 'listings'
            : (str_contains($group, 'POD') ? 'pod_personalized' : 'orders');
        return add_query_arg(['df_view' => $view], home_url('/'));
    }

    private function evidenceUrl(string $group, int $reviewId): string
    {
        if ($reviewId < 1) { return $this->workflowUrl($group); }
        $type = match ($group) {
            'Listing / publish decisions (Gate 3)' => 'listing_review',
            'Personalization exceptions' => 'personalization',
            'POD readiness exceptions' => 'pod_review',
            'Fulfillment exceptions' => 'fulfillment_review',
            default => '',
        };
        if ($type === '') { return $this->workflowUrl($group); }
        return add_query_arg(['df_focus_type' => $type, 'df_focus_id' => $reviewId], $this->workflowUrl($group)) . '#df-evidence-' . $type . '-' . $reviewId;
    }

    /** A display classification, never approval or execution authority. */
    private function subjectEvidenceState(array $row): string
    {
        if (array_key_exists('subject_id', $row) && (int) $row['subject_id'] < 1) { return 'MISSING SUBJECT — REVIEW'; }
        if (isset($row['subject_order_id']) && (int) $row['subject_order_id'] !== (int) ($row['order_id'] ?? 0)) { return 'CONFLICTING ORDER — REVIEW'; }
        if (!empty($row['subject_updated_at']) && !empty($row['created_at']) && strcmp((string) $row['subject_updated_at'], (string) $row['created_at']) > 0) { return 'SUBJECT CHANGED — RECHECK'; }
        if (isset($row['personalization_schema_id']) && (int) $row['personalization_schema_id'] < 1) { return 'MISSING SCHEMA REFERENCE — REVIEW'; }
        if (array_key_exists('schema_subject_id', $row) && (int) $row['schema_subject_id'] < 1) { return 'MISSING SCHEMA — REVIEW'; }
        if (isset($row['line_product_version_id'], $row['schema_product_version_id']) && (int) $row['line_product_version_id'] !== (int) $row['schema_product_version_id']) { return 'SCHEMA PRODUCT MISMATCH — REVIEW'; }
        if (isset($row['line_environment']) && (string) $row['line_environment'] !== (string) ($row['environment'] ?? '')) { return 'ENVIRONMENT MISMATCH — REVIEW'; }
        if (isset($row['schema_state']) && (string) $row['schema_state'] !== 'APPROVED') { return 'SCHEMA NOT APPROVED — REVIEW'; }
        return 'RECORDED — VERIFY IN WORKFLOW';
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
        <section class="df-panel df-u3-product-approvals" id="df-approval-product">
            <div class="df-panel-head"><div><h2>Product approval inbox</h2><p>Gate 2 — inspect protected product files, marketing assets, latest-revision deterministic QA and semantic/policy QA evidence before deciding.</p></div><span><?php $productCount=$this->pendingProductCount();echo esc_html($productCount===null?'UNKNOWN':(string)$productCount); ?> need decision</span></div>
            <div class="df-muted">The count covers all pending product plans; this panel displays the most recent 50. Gate 2 remains human-controlled. Product Approval does not activate Etsy publishing, POD fulfillment, orders or tax automation.</div>
            <?php if ($rows === null) : ?><div class="df-notice df-notice-error">Product approval evidence unavailable. No empty queue is inferred.</div><?php elseif ($rows === []) : ?><div class="df-empty">No finished products currently require Product Approval.</div><?php else : foreach ($rows as $row) : ?>
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

    /** Count the plans represented by the bounded product inbox, independent of bundle versions. */
    private function pendingProductCount(): ?int
    {
        global $wpdb;
        $wpdb->last_error='';
        $count=$wpdb->get_var(
            'SELECT COUNT(*) FROM ' . Tables::production_plans() . ' pp'
            . ' INNER JOIN ' . Tables::product_versions() . ' pv ON pv.id=pp.product_version_id'
            . ' INNER JOIN ' . Tables::products() . " p ON p.id=pv.product_id WHERE pp.state='REVIEW_REQUIRED'"
        );
        return $count===null || !empty($wpdb->last_error) ? null : (int)$count;
    }

    /** @return ?list<array<string,mixed>> */
    private function pendingProducts(): ?array
    {
        global $wpdb;
        $sql = "SELECT pp.id AS plan_id,pp.product_version_id,pp.channel,pp.state AS plan_state,pv.version_label,p.id AS product_id,p.name AS product_name,rb.id AS bundle_id,rb.state AS bundle_state FROM " . Tables::production_plans() . " pp INNER JOIN " . Tables::product_versions() . " pv ON pv.id=pp.product_version_id INNER JOIN " . Tables::products() . " p ON p.id=pv.product_id LEFT JOIN " . Tables::release_bundles() . " rb ON rb.id=(SELECT rb2.id FROM " . Tables::release_bundles() . " rb2 WHERE rb2.production_plan_id=pp.id ORDER BY rb2.id DESC LIMIT 1) WHERE pp.state='REVIEW_REQUIRED' ORDER BY pp.id DESC LIMIT 50";
        $wpdb->last_error='';
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (! is_array($rows) || !empty($wpdb->last_error)) { return null; }
        foreach ($rows as &$row) {
            $planId = (int) $row['plan_id'];
            $assetCount = $this->safeCount($wpdb->prepare('SELECT COUNT(*) FROM ' . Tables::production_plan_assets() . ' WHERE production_plan_id=%d AND is_required=1', $planId));
            $row['assets'] = $this->assetsForPlan($planId);
            if ($assetCount === null || $row['assets'] === null) { return null; }
            $row['asset_count'] = $assetCount;
            $revisionFailures = 0;
            foreach ((array) $row['assets'] as $asset) {
                $revisionId = (int) ($asset['revision_id'] ?? 0);
                if ($revisionId < 1 || (string) ($asset['revision_state'] ?? '') !== 'QA_PASSED') { $revisionFailures++; continue; }
                $qaTotal = $this->safeCount($wpdb->prepare("SELECT COUNT(*) FROM " . Tables::production_qa() . " WHERE target_type='revision' AND target_id=%d", $revisionId));
                $qaBad = $this->safeCount($wpdb->prepare("SELECT COUNT(*) FROM " . Tables::production_qa() . " WHERE target_type='revision' AND target_id=%d AND status NOT IN ('PASS','WAIVED')", $revisionId));
                if ($qaTotal === null || $qaBad === null) { return null; }
                if ($qaTotal < 1 || $qaBad > 0) { $revisionFailures++; }
            }
            if (count((array) $row['assets']) < (int) $row['asset_count']) { $revisionFailures += (int) $row['asset_count'] - count((array) $row['assets']); }
            $row['semantic_checks'] = $this->semanticChecks($planId);
            if ($row['semantic_checks'] === null) { return null; }
            $semanticTotal = count($row['semantic_checks']);
            $semanticFailures = count(array_filter((array) $row['semantic_checks'], static fn(array $check): bool => ! in_array((string) ($check['status'] ?? ''), ['PASS', 'WAIVED'], true)));
            $row['qa_failures'] = $revisionFailures + $semanticFailures;
            $row['semantic_qa_status'] = $semanticTotal >= 7 && $semanticFailures === 0 ? 'PASS' : 'BLOCKED';
        }
        unset($row);
        return $rows;
    }

    /** @return ?list<array<string,mixed>> */
    private function semanticChecks(int $planId): ?array
    {
        global $wpdb;
        $wpdb->last_error='';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT check_type,status,details,created_at FROM " . Tables::production_qa() . " WHERE target_type='plan' AND target_id=%d AND check_type LIKE 'semantic_%%' ORDER BY id ASC", $planId), ARRAY_A);
        return is_array($rows) && empty($wpdb->last_error) ? array_values($rows) : null;
    }

    /** @return ?list<array<string,mixed>> */
    private function assetsForPlan(int $planId): ?array
    {
        global $wpdb;
        $sql = 'SELECT s.asset_key,s.asset_type,s.purpose,s.format,ar.id AS revision_id,ar.storage_reference,ar.mime_type,ar.byte_size,ar.state AS revision_state FROM ' . Tables::production_plan_assets() . ' pa INNER JOIN ' . Tables::asset_specs() . ' s ON s.id=pa.asset_spec_id INNER JOIN ' . Tables::asset_revisions() . ' ar ON ar.id=(SELECT ar2.id FROM ' . Tables::asset_revisions() . ' ar2 WHERE ar2.asset_spec_id=s.id ORDER BY ar2.id DESC LIMIT 1) WHERE pa.production_plan_id=%d AND pa.is_required=1 ORDER BY pa.sequence_no ASC';
        $wpdb->last_error='';
        $rows = $wpdb->get_results($wpdb->prepare($sql, $planId), ARRAY_A);
        if (! is_array($rows) || !empty($wpdb->last_error)) { return null; }
        foreach ($rows as &$row) { $row['filename'] = sanitize_file_name(basename((string) ($row['storage_reference'] ?? 'asset'))); }
        unset($row);
        return array_values($rows);
    }

    private function safeCount(string $sql): ?int
    {
        global $wpdb;
        $wpdb->last_error='';
        $value=$wpdb->get_var($sql);
        return is_numeric($value) && empty($wpdb->last_error) ? (int)$value : null;
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
