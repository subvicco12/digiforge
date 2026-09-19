<?php
declare(strict_types=1);

namespace DigiForge\Portal;

use DigiForge\Core\Settings;
use DigiForge\Database\Tables;

/**
 * Read-only production command center for the private DigiForge portal.
 *
 * This layer only projects existing database state into operator-facing
 * summaries. It cannot approve, publish, fulfill, notify, or call providers.
 */
final class ProductionCommandCenter
{
    public function register(): void
    {
        add_filter('do_shortcode_tag', [$this, 'enhance'], 30, 4);
    }

    /** @param array<string,mixed> $attr @param array<int,string> $match */
    public function enhance(string $output, string $tag, array $attr, array $match): string
    {
        if ($tag !== 'digiforge_admin_portal' || ! is_user_logged_in() || ! current_user_can('manage_digiforge')) {
            return $output;
        }

        $view = isset($_GET['df_view']) ? sanitize_key(wp_unslash($_GET['df_view'])) : 'dashboard';
        $panel = match ($view) {
            'dashboard' => $this->dashboardPanel(),
            'approvals' => $this->approvalSummary(),
            default => '',
        };
        if ($panel === '') {
            return $output;
        }

        $position = strrpos($output, '</main>');
        return $position === false
            ? $output . $panel
            : substr($output, 0, $position) . $panel . substr($output, $position);
    }

    private function dashboardPanel(): string
    {
        $metrics = $this->metrics();
        $externalEnabled = Settings::is_enabled('printify')
            || Settings::is_enabled('gelato')
            || Settings::is_enabled('etsy_draft')
            || Settings::is_enabled('etsy_publish')
            || Settings::is_enabled('order_automation')
            || Settings::is_enabled('gst_automation');

        ob_start(); ?>
        <section class="df-panel df-command-center">
            <div class="df-panel-head">
                <div>
                    <p class="df-eyebrow">Production candidate command center</p>
                    <h2>What needs attention now</h2>
                    <p>One operational view across research, production, QA, listings, orders, integrations and exceptions.</p>
                </div>
                <span class="df-pill <?php echo $externalEnabled ? 'df-pill-danger' : 'df-pill-ok'; ?>">
                    External execution: <?php echo $externalEnabled ? 'REVIEW ACTIVE CONTROLS' : 'LOCKED'; ?>
                </span>
            </div>

            <div class="df-ops-grid">
                <?php foreach ($metrics as $metric) : ?>
                    <a class="df-ops-card" href="<?php echo esc_url($this->viewUrl((string) $metric['view'])); ?>">
                        <span><?php echo esc_html((string) $metric['label']); ?></span>
                        <strong><?php echo esc_html((string) $metric['value']); ?></strong>
                        <small><?php echo esc_html((string) $metric['hint']); ?></small>
                    </a>
                <?php endforeach; ?>
            </div>

            <div class="df-gate-strip" aria-label="DigiForge human approval gates">
                <div><b>Gate 1</b><span>Opportunity approval</span></div>
                <div><b>Gate 2</b><span>Finished product approval</span></div>
                <div><b>Gate 3</b><span>Listing / publish approval</span></div>
            </div>

            <?php $this->recentActivity(); ?>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    private function approvalSummary(): string
    {
        $items = [
            ['label' => 'Research decisions', 'value' => $this->countWhere(Tables::research_candidates(), "review_status='PENDING'"), 'view' => 'research'],
            ['label' => 'Product reviews', 'value' => $this->countWhere(Tables::production_plans(), "state='REVIEW_REQUIRED'"), 'view' => 'production'],
            ['label' => 'Listing reviews', 'value' => $this->countWhere(Tables::listings(), "state='REVIEW_REQUIRED'"), 'view' => 'listings'],
            ['label' => 'Publish reviews', 'value' => $this->countWhere(Tables::etsy_intents(), "state='READY_FOR_REVIEW'"), 'view' => 'listings'],
            ['label' => 'Fulfillment reviews', 'value' => $this->countWhere(Tables::orders(), "state IN ('REVIEW_REQUIRED','ON_HOLD')"), 'view' => 'orders'],
            ['label' => 'Open exceptions', 'value' => $this->countWhere(Tables::operational_alerts(), "state='OPEN'"), 'view' => 'finance'],
        ];

        ob_start(); ?>
        <section class="df-panel df-decision-summary">
            <div class="df-panel-head">
                <div><h2>Decision summary</h2><p>WHAT NEEDS MY DECISION? — consolidated across the three human approval gates and exception queues.</p></div>
            </div>
            <div class="df-ops-grid df-ops-grid-compact">
                <?php foreach ($items as $item) : ?>
                    <a class="df-ops-card" href="<?php echo esc_url($this->viewUrl((string) $item['view'])); ?>">
                        <span><?php echo esc_html((string) $item['label']); ?></span>
                        <strong><?php echo esc_html((string) $item['value']); ?></strong>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    /** @return list<array{label:string,value:int|string,hint:string,view:string}> */
    private function metrics(): array
    {
        $needsApproval =
            $this->countWhere(Tables::research_candidates(), "review_status='PENDING'")
            + $this->countWhere(Tables::production_plans(), "state='REVIEW_REQUIRED'")
            + $this->countWhere(Tables::listings(), "state='REVIEW_REQUIRED'")
            + $this->countWhere(Tables::etsy_intents(), "state='READY_FOR_REVIEW'")
            + $this->countWhere(Tables::orders(), "state IN ('REVIEW_REQUIRED','ON_HOLD')");

        $inDevelopment =
            $this->countWhere(Tables::product_versions(), "state IN ('DRAFT','REVIEW')")
            + $this->countWhere(Tables::production_plans(), "state IN ('DRAFT','VALIDATED')");

        $qaFailed =
            $this->countWhere(Tables::asset_revisions(), "state='QA_FAILED'")
            + $this->countWhere(Tables::production_qa(), "status='FAIL'");

        $readyForListing = $this->scalar(
            'SELECT COUNT(*) FROM ' . Tables::product_versions() . ' pv '
            . 'LEFT JOIN ' . Tables::listings() . ' l ON l.product_version_id=pv.id '
            . "WHERE pv.state IN ('APPROVED','RELEASED') AND l.id IS NULL"
        );

        $ordersAttention = $this->countWhere(Tables::orders(), "state IN ('RECEIVED','REVIEW_REQUIRED','ON_HOLD')");
        $exceptions = $this->countWhere(Tables::operational_alerts(), "state='OPEN'");
        $integrationTotal = $this->scalar('SELECT COUNT(*) FROM ' . Tables::integrations());
        $integrationReady = $this->scalar(
            'SELECT COUNT(*) FROM ' . Tables::integrations()
            . " WHERE enabled=1 AND status IN ('CONNECTED','CONFIGURED')"
        );

        return [
            ['label' => 'Needs approval', 'value' => $needsApproval, 'hint' => 'Human decisions across gates', 'view' => 'approvals'],
            ['label' => 'In development', 'value' => $inDevelopment, 'hint' => 'Product/spec/production work', 'view' => 'products'],
            ['label' => 'QA failed', 'value' => $qaFailed, 'hint' => 'Blocked until repaired', 'view' => 'production'],
            ['label' => 'Ready for product review', 'value' => $this->countWhere(Tables::production_plans(), "state='REVIEW_REQUIRED'"), 'hint' => 'Gate 2', 'view' => 'approvals'],
            ['label' => 'Ready for listing', 'value' => $readyForListing, 'hint' => 'Approved product without listing', 'view' => 'listings'],
            ['label' => 'Ready to publish', 'value' => $this->countWhere(Tables::etsy_intents(), "state='READY_FOR_REVIEW'"), 'hint' => 'Gate 3 remains human-controlled', 'view' => 'approvals'],
            ['label' => 'Orders requiring attention', 'value' => $ordersAttention, 'hint' => 'Received/review/on hold', 'view' => 'orders'],
            ['label' => 'Exceptions / alerts', 'value' => $exceptions, 'hint' => 'Open operational alerts', 'view' => 'finance'],
            ['label' => 'Integration health', 'value' => $integrationReady . '/' . $integrationTotal, 'hint' => 'Enabled and connected/configured', 'view' => 'integrations'],
        ];
    }

    private function recentActivity(): void
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            'SELECT event_type,object_type,object_id,created_at FROM ' . Tables::audit_log() . ' ORDER BY id DESC LIMIT 8',
            ARRAY_A
        );
        $rows = is_array($rows) ? $rows : [];
        ?>
        <div class="df-subpanel df-recent-activity">
            <div class="df-panel-head"><h3>Recent activity</h3><a href="<?php echo esc_url($this->viewUrl('audit')); ?>">Open audit log</a></div>
            <?php if ($rows === []) : ?>
                <div class="df-empty">No recent activity.</div>
            <?php else : ?>
                <div class="df-stack">
                    <?php foreach ($rows as $row) : ?>
                        <div class="df-row">
                            <div>
                                <strong><?php echo esc_html(ucwords(str_replace('_', ' ', (string) ($row['event_type'] ?? 'event')))); ?></strong>
                                <div class="df-muted">
                                    <?php echo esc_html(trim((string) ($row['object_type'] ?? '') . ' #' . (string) ($row['object_id'] ?? ''), ' #')); ?>
                                </div>
                            </div>
                            <span class="df-muted"><?php echo esc_html((string) ($row['created_at'] ?? '')); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function countWhere(string $table, string $where): int
    {
        return $this->scalar('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where);
    }

    private function scalar(string $sql): int
    {
        global $wpdb;
        $value = $wpdb->get_var($sql);
        return is_numeric($value) ? (int) $value : 0;
    }

    private function viewUrl(string $view): string
    {
        return add_query_arg('df_view', sanitize_key($view), home_url('/'));
    }
}
