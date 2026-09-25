<?php

declare(strict_types=1);

namespace DigiForge\Portal;

use DigiForge\Core\Settings;
use DigiForge\Database\Tables;
use DigiForge\Launch\ExecutionEngine;
use DigiForge\Launch\ResearchActivationPreflight;
use DigiForge\Integrations\Repository as IntegrationRepository;
use DigiForge\Operations\Readiness;
use DigiForge\Research\Repository as ResearchRepository;
use DigiForge\Security\Logger;

/** Private front-end control center mounted with [digiforge_admin_portal]. */
final class Portal
{
    private const SHORTCODE = 'digiforge_admin_portal';
    private const REVIEW_ACTION = 'digiforge_portal_review_research';
    private const DEVELOP_ACTION = 'digiforge_portal_develop_candidate';
    private const SAVE_AI_SECRET_ACTION = 'digiforge_portal_save_ai_secret';

    /** @var array<string,array{label:string,cap:string}> */
    private const NAV = [
        'dashboard' => ['label' => 'Dashboard', 'cap' => 'manage_digiforge'],
        'approvals' => ['label' => 'Approval Inbox', 'cap' => 'manage_digiforge_research'],
        'attention' => ['label' => 'Attention & Recovery', 'cap' => 'manage_digiforge'],
        'research' => ['label' => 'Research', 'cap' => 'manage_digiforge_research'],
        'products' => ['label' => 'Product Factory', 'cap' => 'manage_digiforge_products'],
        'digital' => ['label' => 'Digital Products', 'cap' => 'manage_digiforge_digital'],
        'production' => ['label' => 'Production', 'cap' => 'manage_digiforge_production'],
        'pod_personalized' => ['label' => 'POD — Personalized', 'cap' => 'manage_digiforge_pod'],
        'pod_future_nonpersonalized' => ['label' => 'POD — Future Non-Personalized', 'cap' => 'manage_digiforge_pod'],
        'listings' => ['label' => 'Listings & Etsy', 'cap' => 'manage_digiforge_listings'],
        'orders' => ['label' => 'Orders & Fulfillment', 'cap' => 'manage_digiforge_orders'],
        'finance' => ['label' => 'Finance & Analytics', 'cap' => 'manage_digiforge_finance'],
        'integrations' => ['label' => 'Integrations', 'cap' => 'manage_digiforge_connections'],
        'audit' => ['label' => 'Audit Log', 'cap' => 'manage_digiforge'],
        'system' => ['label' => 'System & Controls', 'cap' => 'manage_digiforge'],
    ];

    public function register(): void
    {
        add_shortcode(self::SHORTCODE, [$this, 'render']);
        add_action('admin_post_' . self::REVIEW_ACTION, [$this, 'review']);
        add_action('admin_post_' . self::DEVELOP_ACTION, [$this, 'develop']);
        add_action('admin_post_' . self::SAVE_AI_SECRET_ACTION, [$this, 'saveAiSecret']);
    }

    public function render(): string
    {
        if (! is_user_logged_in()) {
            return '<div class="df-login-card"><h2>DigiForge sign-in required</h2><a href="'
                . esc_url(wp_login_url($this->baseUrl())) . '">Sign in</a></div>';
        }
        if (! current_user_can('manage_digiforge')) {
            return $this->notice('You are not authorized to access DigiForge.', true);
        }

        wp_enqueue_style('digiforge-portal', DIGIFORGE_URL . 'assets/portal.css', [], DIGIFORGE_VERSION);
        $view = isset($_GET['df_view']) ? sanitize_key(wp_unslash($_GET['df_view'])) : 'dashboard';
        if (! isset(self::NAV[$view]) || ! current_user_can(self::NAV[$view]['cap'])) {
            $view = 'dashboard';
        }

        ob_start();
        ?>
        <div class="df-portal-shell">
            <aside class="df-portal-sidebar">
                <div class="df-brand">
                    <span class="df-brand-mark">DF</span>
                    <div><strong>DigiForge</strong><small>Operations Console</small></div>
                </div>
                <nav class="df-nav">
                    <?php foreach (self::NAV as $slug => $item) : ?>
                        <?php if (! current_user_can($item['cap'])) { continue; } ?>
                        <a class="<?php echo $view === $slug ? 'is-active' : ''; ?>"
                           href="<?php echo esc_url($this->url($slug)); ?>">
                            <?php echo esc_html($item['label']); ?>
                        </a>
                    <?php endforeach; ?>
                </nav>
                <div class="df-sidebar-footer">
                    <span><?php echo esc_html(wp_get_current_user()->display_name); ?></span>
                    <a href="<?php echo esc_url(wp_logout_url($this->baseUrl())); ?>">Sign out</a>
                </div>
            </aside>
            <main class="df-portal-main">
                <?php $this->topbar($view); ?>
                <?php $this->flash(); ?>
                <?php $this->view($view); ?>
            </main>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public function review(): void
    {
        $this->guard('manage_digiforge_research');
        check_admin_referer(self::REVIEW_ACTION);
        $id = isset($_POST['candidate_id']) ? absint($_POST['candidate_id']) : 0;
        $decision = isset($_POST['decision'])
            ? strtoupper(sanitize_key(wp_unslash($_POST['decision'])))
            : '';
        $notes = isset($_POST['notes'])
            ? sanitize_textarea_field(wp_unslash($_POST['notes']))
            : '';
        $result = (new ResearchRepository())->review($id, $decision, $notes);
        if (is_wp_error($result)) {
            $this->redirect('approvals', $result->get_error_message(), true);
        }
        Logger::audit(
            'portal_research_review_completed',
            ['candidate_id' => $id, 'decision' => $decision],
            'research_candidate',
            (string) $id
        );
        $this->redirect('approvals', sprintf('Candidate #%d marked %s.', $id, $decision));
    }

    public function saveAiSecret(): void
    {
        $this->guard('manage_digiforge_connections');
        check_admin_referer(self::SAVE_AI_SECRET_ACTION);
        $integrationId = isset($_POST['integration_id']) ? absint($_POST['integration_id']) : 0;
        $value = isset($_POST['credential_value']) ? trim((string) wp_unslash($_POST['credential_value'])) : '';
        if ($integrationId < 1 || $value === '') {
            $this->redirect('integrations', 'Credential was not changed. Enter it in the secure DigiForge portal form.', true);
        }
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT id,provider,environment FROM ' . Tables::integrations() . ' WHERE id=%d LIMIT 1',
            $integrationId
        ), ARRAY_A);
        if (!is_array($row) || (string) $row['provider'] !== 'ai' || (string) $row['environment'] !== 'production') {
            $this->redirect('integrations', 'Only the production AI connector can be updated here.', true);
        }
        $result = (new IntegrationRepository())->storeSecret($integrationId, 'api_key', $value);
        unset($value);
        if (is_wp_error($result)) {
            $this->redirect('integrations', 'Credential could not be stored securely. ' . $result->get_error_message(), true);
        }
        Logger::audit('portal_ai_credential_replaced', ['integration_id' => $integrationId], 'integration', (string) $integrationId);
        $this->redirect('integrations', 'AI credential replaced securely. No activation switch was changed.');
    }

    public function develop(): void
    {
        $this->guard('manage_digiforge_products');
        check_admin_referer(self::DEVELOP_ACTION);
        $id = isset($_POST['candidate_id']) ? absint($_POST['candidate_id']) : 0;
        $shop = isset($_POST['shop']) ? sanitize_key(wp_unslash($_POST['shop'])) : 'digital';
        $shop = in_array($shop, ['digital', 'goods'], true) ? $shop : 'digital';
        $key = sprintf('portal-develop-%d-%s-%s', $id, $shop, gmdate('YmdHi'));
        $result = (new ExecutionEngine())->develop($id, ['shop' => $shop], $key);
        if (is_wp_error($result)) {
            $this->redirect('approvals', $result->get_error_message(), true);
        }
        $productId = (int) ($result['product']['id'] ?? 0);
        $this->redirect('products', sprintf('Candidate #%d developed into product #%d.', $id, $productId));
    }

    private function view(string $view): void
    {
        if ($view === 'dashboard') { $this->dashboard(); return; }
        if ($view === 'approvals') { $this->approvals(); return; }
        if ($view === 'attention') { $this->attention(); return; }
        if ($view === 'research') { $this->research(); return; }
        if ($view === 'integrations') { $this->integrations(); return; }
        if ($view === 'system') { $this->system(); return; }
        if ($view === 'pod_future_nonpersonalized') { $this->futureNonPersonalizedPod(); return; }
        foreach ($this->tables($view) as $label => $table) {
            $this->panelTable($label, $table);
        }
    }

    private function topbar(string $view): void
    {
        $readiness = (new Readiness())->report();
        $stopAll = Settings::get('stop_all', true) === true;
        ?>
        <header class="df-topbar">
            <div>
                <p class="df-eyebrow">DigiCraftify automation platform</p>
                <h1><?php echo esc_html(self::NAV[$view]['label']); ?></h1>
            </div>
            <div class="df-topbar-status">
                <span class="df-pill <?php echo $stopAll ? 'df-pill-danger' : 'df-pill-ok'; ?>">
                    STOP ALL: <?php echo $stopAll ? 'ON' : 'OFF'; ?>
                </span>
                <span class="df-pill"><?php echo esc_html((string) ($readiness['status'] ?? 'REVIEW_REQUIRED')); ?></span>
            </div>
        </header>
        <?php
    }

    private function dashboard(): void
    {
        $cards = [
            'Pending opportunity approvals' => $this->pendingCount(),
            'Product approvals' => $this->countByState(Tables::production_plans(), 'state', 'REVIEW_REQUIRED'),
            'Publish-ready listings' => $this->countByState(Tables::listings(), 'state', 'PUBLISH_READY'),
            'Products' => $this->count(Tables::products()),
            'Listings' => $this->count(Tables::listings()),
            'Orders' => $this->count(Tables::orders()),
            'Alerts' => $this->count(Tables::operational_alerts()),
            'Integrations' => $this->count(Tables::integrations()),
        ];
        echo '<section class="df-card-grid">';
        foreach ($cards as $label => $value) {
            echo '<article class="df-stat-card"><span>' . esc_html($label) . '</span><strong>'
                . esc_html((string) $value) . '</strong></article>';
        }
        echo '</section>';
        echo '<section class="df-panel"><div class="df-panel-head"><h2>Approval queue</h2><a href="'
            . esc_url($this->url('approvals')) . '">Open full queue</a></div>';
        $this->candidateCards(ResearchRepository::REVIEW_PENDING, 3, false);
        echo '</section>';
        echo '<section class="df-panel"><div class="df-panel-head"><div><h2>Production journey</h2><p>One view of the three human gates and automated work between them.</p></div></div><div class="df-signal-grid">'
            . '<div><span>Gate 1</span><b>Opportunity Approval</b></div>'
            . '<div><span>Automated</span><b>Product Factory + QA</b></div>'
            . '<div><span>Gate 2</span><b>Product Approval</b></div>'
            . '<div><span>Automated</span><b>Listing Package + Validation</b></div>'
            . '<div><span>Gate 3</span><b>Listing / Publish Approval</b></div>'
            . '</div><p class="df-muted">External Etsy/POD/order/tax execution remains controlled by the existing fail-closed switches.</p></section>';
        $this->systemSummary();
    }

    private function attention(): void
    {
        global $wpdb;
        $preflight = (new ResearchActivationPreflight())->report();
        $blockers = array_values(array_filter((array) ($preflight['blockers'] ?? []), 'is_scalar'));
        $alerts = $wpdb->get_results(
            'SELECT id,environment,alert_type,source_type,source_id,severity,state,created_at,updated_at '
            . 'FROM ' . Tables::operational_alerts()
            . " WHERE state NOT IN ('RESOLVED','CLOSED') ORDER BY FIELD(severity,'CRITICAL','ERROR','WARNING','INFO'), id DESC LIMIT 50",
            ARRAY_A
        );
        echo '<section class="df-panel"><div class="df-panel-head"><div><h2>Attention & Recovery</h2>'
            . '<p>Human-attention signals are shown here without inferring readiness from arbitrary operational status text.</p></div>'
            . '<span class="df-status">' . esc_html($blockers === [] ? 'NO PREFLIGHT BLOCKERS' : count($blockers) . ' PREFLIGHT BLOCKER(S)') . '</span></div>';
        if ($blockers !== []) {
            echo '<div class="df-notice df-notice-error"><strong>Research activation blockers:</strong> '
                . esc_html(implode(', ', array_map('strval', $blockers))) . '</div>';
        } else {
            echo '<div class="df-notice df-notice-success">Research activation preflight currently has no blockers.</div>';
        }
        echo '<div class="df-signal-grid">'
            . '<div><span>Readiness</span><b>' . esc_html((string) ($preflight['checks']['readiness_ready_locked'] ?? 'UNKNOWN')) . '</b></div>'
            . '<div><span>Recovery</span><b>' . esc_html((string) ($preflight['checks']['recovery_pass'] ?? 'UNKNOWN')) . '</b></div>'
            . '<div><span>External execution</span><b>' . esc_html((string) ($preflight['checks']['external_lock'] ?? 'UNKNOWN')) . '</b></div>'
            . '<div><span>Credential decryptability</span><b>' . esc_html((string) ($preflight['checks']['credential_decryptable'] ?? 'UNKNOWN')) . '</b></div>'
            . '</div></section>';
        echo '<section class="df-panel"><div class="df-panel-head"><div><h2>Open operational alerts</h2><p>These records require human review or explicit recovery handling.</p></div>'
            . '<span>' . esc_html((string) count(is_array($alerts) ? $alerts : [])) . ' open</span></div>';
        if (!is_array($alerts) || $alerts === []) {
            echo '<div class="df-empty">No open operational alerts.</div>';
        } else {
            echo '<div class="df-table-wrap"><table class="df-table"><thead><tr><th>ID</th><th>Environment</th><th>Severity</th><th>Type</th><th>Source</th><th>State</th><th>Created</th></tr></thead><tbody>';
            foreach ($alerts as $alert) {
                echo '<tr><td>' . esc_html((string) $alert['id']) . '</td><td>' . esc_html((string) $alert['environment']) . '</td><td>'
                    . esc_html((string) $alert['severity']) . '</td><td>' . esc_html((string) $alert['alert_type']) . '</td><td>'
                    . esc_html((string) $alert['source_type']) . '#' . esc_html((string) $alert['source_id']) . '</td><td>'
                    . esc_html((string) $alert['state']) . '</td><td>' . esc_html((string) $alert['created_at']) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</section>';
    }

    private function approvals(): void
    {
        echo '<section class="df-panel"><div class="df-panel-head"><div><h2>Research approval inbox</h2>'
            . '<p>Approve or reject every candidate before product development.</p></div></div>';
        $this->candidateCards(ResearchRepository::REVIEW_PENDING, 50, true);
        echo '</section>';
        foreach ($this->tables('reviews') as $label => $table) {
            $this->panelTable($label, $table);
        }
    }

    private function research(): void
    {
        echo '<section class="df-panel"><div class="df-panel-head"><h2>All research candidates</h2></div>';
        $this->candidateCards('', 50, true);
        echo '</section>';
        $researchTables = [
            'Sources' => Tables::research_sources(),
            'Observations' => Tables::research_observations(),
            'Evidence' => Tables::research_evidence(),
            'Reviews' => Tables::research_reviews(),
        ];
        foreach ($researchTables as $label => $table) {
            $this->panelTable($label, $table);
        }
    }

    private function candidateCards(string $status, int $limit, bool $details): void
    {
        $rows = $this->candidates($status, $limit);
        if ($rows === []) {
            echo '<div class="df-empty">No matching research candidates.</div>';
            return;
        }
        foreach ($rows as $row) {
            $signals = $this->decode($row['score_inputs'] ?? '');
            $evidence = $details ? $this->evidence((int) $row['id']) : [];
            ?>
            <article class="df-candidate">
                <div class="df-candidate-head">
                    <div>
                        <span class="df-kicker">Candidate #<?php echo esc_html((string) $row['id']); ?></span>
                        <h3><?php echo esc_html((string) $row['title']); ?></h3>
                        <span class="df-status df-status-<?php echo esc_attr(strtolower((string) $row['review_status'])); ?>">
                            <?php echo esc_html((string) $row['review_status']); ?>
                        </span>
                    </div>
                    <div class="df-score"><strong><?php echo esc_html(number_format((float) $row['score'], 2)); ?></strong><span>/100</span></div>
                </div>
                <p><?php echo esc_html((string) ($row['summary'] ?? '')); ?></p>
                <?php if ($signals !== []) : ?>
                    <div class="df-signal-grid">
                        <?php foreach ($signals as $name => $value) : ?>
                            <div>
                                <span><?php echo esc_html(ucwords(str_replace('_', ' ', (string) $name))); ?></span>
                                <b><?php echo esc_html((string) $value); ?></b>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if ($evidence !== []) : ?>
                    <details class="df-evidence">
                        <summary>View evidence (<?php echo esc_html((string) count($evidence)); ?>)</summary>
                        <?php foreach ($evidence as $item) : ?>
                            <div class="df-evidence-item">
                                <strong><?php echo esc_html((string) ($item['observation_title'] ?? 'Evidence')); ?></strong>
                                <p><?php echo esc_html((string) ($item['value'] ?? '')); ?></p>
                                <?php if (! empty($item['url'])) : ?>
                                    <a href="<?php echo esc_url((string) $item['url']); ?>" target="_blank" rel="noopener noreferrer">Source</a>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </details>
                <?php endif; ?>
                <?php if ((string) $row['review_status'] === ResearchRepository::REVIEW_PENDING) { $this->reviewForm((int) $row['id']); } ?>
                <?php if ((string) $row['review_status'] === ResearchRepository::REVIEW_APPROVED) { $this->developForm((int) $row['id']); } ?>
            </article>
            <?php
        }
    }

    private function reviewForm(int $id): void
    {
        ?>
        <form class="df-review-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::REVIEW_ACTION); ?>">
            <input type="hidden" name="candidate_id" value="<?php echo esc_attr((string) $id); ?>">
            <?php wp_nonce_field(self::REVIEW_ACTION); ?>
            <textarea name="notes" rows="2" placeholder="Optional review notes"></textarea>
            <div class="df-actions">
                <button class="df-button df-button-primary" name="decision" value="APPROVED">Approve</button>
                <button class="df-button df-button-danger" name="decision" value="REJECTED">Reject</button>
            </div>
        </form>
        <?php
    }

    private function developForm(int $id): void
    {
        if (! current_user_can('manage_digiforge_products')) { return; }
        ?>
        <form class="df-review-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::DEVELOP_ACTION); ?>">
            <input type="hidden" name="candidate_id" value="<?php echo esc_attr((string) $id); ?>">
            <?php wp_nonce_field(self::DEVELOP_ACTION); ?>
            <label>Target shop
                <select name="shop">
                    <option value="digital">DigiCraftifyDigital</option>
                    <option value="goods">DigiCraftifyGoods</option>
                </select>
            </label>
            <button class="df-button df-button-primary">Develop approved candidate</button>
        </form>
        <?php
    }

    private function futureNonPersonalizedPod(): void
    {
        echo '<section class="df-panel"><div class="df-panel-head"><div><h2>Future Non-Personalized POD</h2><p>Reserved product lane for future POD products that do not require customer personalization.</p></div><span class="df-status">PLANNED · INERT</span></div>';
        echo '<div class="df-notice">This lane is intentionally planning/read-only only. No non-personalized POD execution, publishing, ordering, fulfillment, or provider activation is enabled by this tab.</div>';
        echo '<div class="df-signal-grid">'
            . '<div><span>Current personalized lane</span><b>ACTIVE FOUNDATION</b></div>'
            . '<div><span>Non-personalized execution</span><b>NOT IMPLEMENTED</b></div>'
            . '<div><span>External provider actions</span><b>LOCKED</b></div>'
            . '<div><span>Product publishing</span><b>OFF</b></div>'
            . '</div></section>';
        echo '<section class="df-panel"><div class="df-panel-head"><div><h2>Shared POD catalog</h2><p>Supplier/catalog evidence is shared; this view does not infer personalization classification where the repository has no authoritative classification field.</p></div></div>';
        $this->panelTable('Shared POD Catalog', Tables::pod_catalog());
        echo '</section>';
    }

    private function integrations(): void
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            'SELECT id,provider,environment,connection_key,display_name,status,enabled,updated_at FROM '
            . Tables::integrations() . ' ORDER BY id ASC',
            ARRAY_A
        );
        echo '<section class="df-panel"><div class="df-panel-head"><div><h2>Provider integrations</h2>'
            . '<p>Credentials are never displayed in this portal.</p></div></div><div class="df-integration-grid">';
        foreach (is_array($rows) ? $rows : [] as $row) {
            echo '<article class="df-integration-card"><h3>' . esc_html((string) $row['display_name']) . '</h3>'
                . '<dl class="df-kv">'
                . '<div><dt>Status</dt><dd>' . esc_html((string) $row['status']) . '</dd></div>'
                . '<div><dt>Provider</dt><dd>' . esc_html((string) $row['provider']) . '</dd></div>'
                . '<div><dt>Environment</dt><dd>' . esc_html((string) $row['environment']) . '</dd></div>'
                . '<div><dt>Enabled</dt><dd>' . (! empty($row['enabled']) ? 'YES' : 'NO') . '</dd></div>'
                . '<div><dt>Updated</dt><dd>' . esc_html((string) $row['updated_at']) . '</dd></div>'
                . '</dl>'
                . (((string) $row['provider'] === 'ai' && (string) $row['environment'] === 'production') ? '<form class="df-credential-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">' . '<input type="hidden" name="action" value="' . esc_attr(self::SAVE_AI_SECRET_ACTION) . '">' . wp_nonce_field(self::SAVE_AI_SECRET_ACTION, '_wpnonce', true, false) . '<input type="hidden" name="integration_id" value="' . esc_attr((string) $row['id']) . '">' . '<label>Replace encrypted AI credential <input type="password" name="credential_value" autocomplete="new-password" required></label>' . '<button type="submit">Save Credential</button></form>' : '')
                . '</article>';
        }
        echo '</div></section>';
    }

    private function system(): void
    {
        $this->systemSummary();
        echo '<section class="df-panel"><div class="df-panel-head"><h2>Control state</h2></div>'
            . '<p class="df-muted">Critical activation controls remain fail-closed and read-only here.</p>'
            . '<div class="df-switch-list">';
        $switches = [
            'stop_all', 'activation_authorized', 'automation_armed', 'research', 'ai',
            'product_development', 'printify', 'gelato', 'etsy_draft', 'etsy_publish',
            'order_automation', 'gst_automation',
        ];
        foreach ($switches as $name) {
            $enabled = Settings::get($name, $name === 'stop_all');
            echo '<div><span>' . esc_html(ucwords(str_replace('_', ' ', $name))) . '</span><b class="'
                . ($enabled ? 'is-on' : 'is-off') . '">' . ($enabled ? 'ON' : 'OFF') . '</b></div>';
        }
        echo '</div></section>';
    }

    private function systemSummary(): void
    {
        $report = (new Readiness())->report();
        echo '<section class="df-panel"><div class="df-panel-head"><h2>System summary</h2></div><dl class="df-kv">'
            . '<div><dt>Version</dt><dd>' . esc_html(DIGIFORGE_VERSION) . '</dd></div>'
            . '<div><dt>Readiness</dt><dd>' . esc_html((string) ($report['status'] ?? 'UNKNOWN')) . '</dd></div>'
            . '<div><dt>Schema</dt><dd>' . esc_html((string) ($report['schema']['current'] ?? '?')) . '</dd></div>'
            . '<div><dt>External lock</dt><dd>' . (! empty($report['externally_locked']) ? 'LOCKED' : 'UNLOCKED') . '</dd></div>'
            . '</dl></section>';
    }

    private function panelTable(string $label, string $table): void
    {
        echo '<section class="df-panel"><div class="df-panel-head"><h2>' . esc_html($label) . '</h2><span>'
            . esc_html((string) $this->count($table)) . ' records</span></div>';
        $this->table($table);
        echo '</section>';
    }

    private function table(string $table): void
    {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC LIMIT 50", ARRAY_A);
        if (! is_array($rows) || $rows === []) {
            echo '<div class="df-empty">No records found.</div>';
            return;
        }
        $columns = array_slice(array_keys($rows[0]), 0, 8);
        echo '<div class="df-table-wrap"><table class="df-table"><thead><tr>';
        foreach ($columns as $column) {
            echo '<th>' . esc_html(ucwords(str_replace('_', ' ', (string) $column))) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr>';
            foreach ($columns as $column) {
                echo '<td>' . esc_html($this->cell((string) $column, $row[$column] ?? '')) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    /** @return array<string,string> */
    private function tables(string $view): array
    {
        return match ($view) {
            'products' => [
                'Opportunities' => Tables::opportunities(),
                'Product Families' => Tables::product_families(),
                'Products' => Tables::products(),
                'Product Versions' => Tables::product_versions(),
            ],
            'digital' => [
                'Digital Products' => Tables::digital_products(),
                'Digital Files' => Tables::digital_files(),
                'File Versions' => Tables::digital_file_versions(),
                'Packages' => Tables::digital_packages(),
                'Previews' => Tables::digital_previews(),
                'Templates' => Tables::digital_templates(),
                'Licenses' => Tables::digital_licenses(),
                'Download Checks' => Tables::digital_download_checks(),
            ],
            'production' => [
                'Asset Specs' => Tables::asset_specs(),
                'Production Plans' => Tables::production_plans(),
                'Production Intents' => Tables::production_intents(),
                'Asset Revisions' => Tables::asset_revisions(),
                'Production QA' => Tables::production_qa(),
                'Release Bundles' => Tables::release_bundles(),
            ],
            'pod_personalized' => [
                'POD Catalog' => Tables::pod_catalog(),
                'Mappings' => Tables::pod_mappings(),
                'Print Areas' => Tables::pod_print_areas(),
                'Personalization Schemas' => Tables::personalization_schemas(),
                'Provider Intents' => Tables::pod_provider_intents(),
                'Cost Snapshots' => Tables::pod_cost_snapshots(),
                'Readiness Reviews' => Tables::pod_readiness_reviews(),
            ],
            'listings' => [
                'Listings' => Tables::listings(),
                'Listing SEO' => Tables::listing_seo(),
                'Listing Media' => Tables::listing_media(),
                'POD Bindings' => Tables::listing_pod_bindings(),
                'Etsy Draft Packages' => Tables::etsy_draft_packages(),
                'Etsy Intents' => Tables::etsy_intents(),
                'Readiness Reviews' => Tables::listing_readiness_reviews(),
            ],
            'orders' => [
                'Orders' => Tables::orders(),
                'Order Line Items' => Tables::order_line_items(),
                'Personalization' => Tables::personalization_submissions(),
                'Fulfillment Plans' => Tables::fulfillment_plans(),
                'Fulfillment Intents' => Tables::fulfillment_intents(),
                'Readiness Reviews' => Tables::fulfillment_readiness_reviews(),
            ],
            'finance' => [
                'Ledger' => Tables::finance_ledger(),
                'FX Snapshots' => Tables::fx_snapshots(),
                'Tax Classifications' => Tables::tax_classifications(),
                'Periods' => Tables::finance_periods(),
                'Analytics' => Tables::analytics_snapshots(),
                'Alerts' => Tables::operational_alerts(),
                'Finance Intents' => Tables::finance_intents(),
            ],
            'reviews' => [
                'AI Reviews' => Tables::ai_reviews(),
                'Production QA' => Tables::production_qa(),
                'POD Readiness' => Tables::pod_readiness_reviews(),
                'Listing Readiness' => Tables::listing_readiness_reviews(),
                'Fulfillment Readiness' => Tables::fulfillment_readiness_reviews(),
            ],
            'audit' => ['Recent Audit Events' => Tables::audit_log()],
            default => [],
        };
    }

    /** @return array<int,array<string,mixed>> */
    private function candidates(string $status, int $limit): array
    {
        global $wpdb;
        $limit = min(100, max(1, $limit));
        $sql = 'SELECT * FROM ' . Tables::research_candidates();
        if ($status !== '') {
            $sql .= $wpdb->prepare(' WHERE review_status=%s', $status);
        }
        $sql .= $wpdb->prepare(' ORDER BY id DESC LIMIT %d', $limit);
        $rows = $wpdb->get_results($sql, ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    /** @return array<int,array<string,mixed>> */
    private function evidence(int $candidateId): array
    {
        global $wpdb;
        $sql = 'SELECT e.value,e.provenance,o.title AS observation_title,o.provenance AS observation_provenance '
            . 'FROM ' . Tables::research_candidate_evidence() . ' ce '
            . 'INNER JOIN ' . Tables::research_evidence() . ' e ON e.id=ce.evidence_id '
            . 'LEFT JOIN ' . Tables::research_observations() . ' o ON o.id=e.observation_id '
            . 'WHERE ce.candidate_id=%d ORDER BY e.id ASC';
        $rows = $wpdb->get_results($wpdb->prepare($sql, $candidateId), ARRAY_A);
        if (! is_array($rows)) { return []; }
        foreach ($rows as &$row) {
            $evidence = $this->decode($row['provenance'] ?? '');
            $observation = $this->decode($row['observation_provenance'] ?? '');
            $row['url'] = (string) ($evidence['url'] ?? $observation['url'] ?? '');
            unset($row['provenance'], $row['observation_provenance']);
        }
        unset($row);
        return $rows;
    }

    private function pendingCount(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . Tables::research_candidates() . ' WHERE review_status=%s',
                ResearchRepository::REVIEW_PENDING
            )
        );
    }

    private function count(string $table): int
    {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    }

    private function countByState(string $table, string $column, string $state): int
    {
        global $wpdb;
        if (! preg_match('/^[a-z0-9_]+$/i', $column)) { return 0; }
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column}=%s", $state));
    }

    private function cell(string $key, mixed $value): string
    {
        if (Logger::isCredentialKey($key) || $key === 'ciphertext') { return '[REDACTED]'; }
        if (is_array($value) || is_object($value)) { $value = wp_json_encode($value); }
        $text = wp_strip_all_tags((string) $value);
        return mb_strlen($text) > 180 ? mb_substr($text, 0, 177) . '…' : $text;
    }

    /** @return array<string,mixed> */
    private function decode(mixed $value): array
    {
        if (is_array($value)) { return $value; }
        $decoded = is_string($value) ? json_decode($value, true) : null;
        return is_array($decoded) ? $decoded : [];
    }

    private function flash(): void
    {
        $message = isset($_GET['df_message'])
            ? sanitize_text_field(wp_unslash($_GET['df_message']))
            : '';
        if ($message === '') { return; }
        $error = isset($_GET['df_error'])
            && rest_sanitize_boolean(wp_unslash($_GET['df_error']));
        echo $this->notice($message, $error); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    private function notice(string $message, bool $error = false): string
    {
        $class = $error ? 'df-notice-error' : 'df-notice-success';
        return '<div class="df-notice ' . esc_attr($class) . '">' . esc_html($message) . '</div>';
    }

    private function guard(string $capability): void
    {
        if (! is_user_logged_in() || ! current_user_can($capability)) {
            wp_die(
                esc_html__('You are not authorized to perform this DigiForge action.', 'digiforge'),
                '',
                ['response' => 403]
            );
        }
    }

    private function redirect(string $view, string $message, bool $error = false): never
    {
        $args = [
            'df_view' => $view,
            'df_message' => $message,
            'df_error' => $error ? 1 : 0,
        ];
        wp_safe_redirect(add_query_arg($args, $this->baseUrl()));
        exit;
    }

    private function baseUrl(): string
    {
        $id = get_queried_object_id();
        $url = $id > 0 ? get_permalink($id) : home_url('/');
        return is_string($url) && $url !== '' ? $url : home_url('/');
    }

    private function url(string $view): string
    {
        return add_query_arg('df_view', $view, $this->baseUrl());
    }
}
