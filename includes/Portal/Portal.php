<?php

declare(strict_types=1);

namespace DigiForge\Portal;

use DigiForge\Core\Settings;
use DigiForge\AI\CostKpiReadModel;
use DigiForge\AI\LifecycleDenominatorReadModel;
use DigiForge\Orders\OperationsReadModel as OrderOperationsReadModel;
use DigiForge\Listings\WebhookReconciliationReadModel;
use DigiForge\Listings\EtsyReconciliationOperatorReadModel;
use DigiForge\Listings\EtsyDigitalAttachmentReadModel;
use DigiForge\Listings\EtsyWebhookReadiness;
use DigiForge\Orders\ReconciliationReadModel as OrderReconciliationReadModel;
use DigiForge\Database\Tables;
use DigiForge\Launch\ExecutionEngine;
use DigiForge\Launch\ResearchActivationPreflight;
use DigiForge\Integrations\Repository as IntegrationRepository;
use DigiForge\Operations\Readiness;
use DigiForge\Operations\RecoveryEvidence;
use DigiForge\Operations\OperationalExceptionReadModel;
use DigiForge\Operations\AuditCorrelationReadModel;
use DigiForge\POD\ProviderStatusReadModel;
use DigiForge\POD\PersonalizedCatalogReference;
use DigiForge\Research\Repository as ResearchRepository;
use DigiForge\Security\Logger;
use DigiForge\Queue\OperatorQueueReadModel;

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
        'businesses' => ['label' => 'Businesses / Brands / Shops', 'cap' => 'manage_digiforge'],
        'approvals' => ['label' => 'Approval Inbox', 'cap' => 'manage_digiforge_research'],
        'attention' => ['label' => 'Attention & Recovery', 'cap' => 'manage_digiforge'],
        'research' => ['label' => 'Research', 'cap' => 'manage_digiforge_research'],
        'products' => ['label' => 'Product Factory', 'cap' => 'manage_digiforge_products'],
        'digital' => ['label' => 'Digital Products', 'cap' => 'manage_digiforge_digital'],
        'production' => ['label' => 'Production', 'cap' => 'manage_digiforge_production'],
        'pod_personalized' => ['label' => 'POD — Personalized', 'cap' => 'manage_digiforge_pod'],
        'pod_future_nonpersonalized' => ['label' => 'POD — Future Non-Personalized', 'cap' => 'manage_digiforge_pod'],
        'listings' => ['label' => 'Listings & Etsy', 'cap' => 'manage_digiforge_listings'],
        'orders' => ['label' => 'Orders / Personalization', 'cap' => 'manage_digiforge_orders'],
        'fulfillment' => ['label' => 'Fulfillment / Providers', 'cap' => 'manage_digiforge_orders'],
        'ai_budget' => ['label' => 'AI & Budget', 'cap' => 'manage_digiforge_ai'],
        'finance' => ['label' => 'Finance & GST', 'cap' => 'manage_digiforge_finance'],
        'analytics' => ['label' => 'Analytics', 'cap' => 'manage_digiforge_finance'],
        'automation' => ['label' => 'Automation / Queues', 'cap' => 'manage_digiforge'],
        'integrations' => ['label' => 'Integrations', 'cap' => 'manage_digiforge_connections'],
        'audit' => ['label' => 'Audit / Reconciliation', 'cap' => 'manage_digiforge'],
        'system' => ['label' => 'System & Controls', 'cap' => 'manage_digiforge'],
        'settings' => ['label' => 'Settings', 'cap' => 'manage_digiforge'],
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
        $returnUrl = isset($_POST['return_url']) ? wp_validate_redirect(esc_url_raw(wp_unslash($_POST['return_url'])), $this->baseUrl()) : $this->baseUrl();
        if ($integrationId < 1 || $value === '') {
            $this->redirectToUrl($returnUrl, 'Credential was not changed. Enter it in the secure DigiForge portal form.', true);
        }
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT id,provider,environment FROM ' . Tables::integrations() . ' WHERE id=%d LIMIT 1',
            $integrationId
        ), ARRAY_A);
        if (!is_array($row) || (string) $row['provider'] !== 'ai' || (string) $row['environment'] !== 'production') {
            $this->redirectToUrl($returnUrl, 'Only the production AI connector can be updated here.', true);
        }
        $result = (new IntegrationRepository())->storeSecret($integrationId, 'api_key', $value);
        unset($value);
        if (is_wp_error($result)) {
            $this->redirectToUrl($returnUrl, 'Credential could not be stored securely. ' . $result->get_error_message(), true);
        }
        Logger::audit('portal_ai_credential_replaced', ['integration_id' => $integrationId], 'integration', (string) $integrationId);
        $this->redirectToUrl($returnUrl, 'AI credential replaced securely. No activation switch was changed.');
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
        if ($view === 'fulfillment') { $this->fulfillmentProviders(); return; }
        if ($view === 'ai_budget') { $this->aiBudgetOperations(); return; }
        if ($view === 'audit') { $this->auditReconciliationOperations(); return; }
        if ($view === 'orders') { $this->orderOperations(); return; }
        if ($view === 'listings') { $this->listingOperations(); return; }
        if ($view === 'finance') { $this->financeOperations(); return; }
        if ($view === 'analytics') { $this->analyticsOperations(); return; }
        if ($view === 'automation') { $this->automationOperations(); return; }
        if ($view === 'businesses') { $this->businessOperations(); return; }
        if ($view === 'settings') { $this->settingsOperations(); return; }
        if ($view === 'pod_future_nonpersonalized') { $this->futureNonPersonalizedPod(); return; }
        if ($view === 'pod_personalized') { $this->personalizedPodSummary(); }

        foreach ($this->tables($view) as $label => $table) {
            $this->panelTable($label, $table);
        }
    }

    private function alertWorkflowUrl(string $sourceType):string
    {
        $source=strtolower($sourceType);
        $view=str_contains($source,'integration')||str_contains($source,'connector')||str_contains($source,'credential')?'integrations':(str_contains($source,'etsy')||str_contains($source,'listing')?'listings':(str_contains($source,'pod')||str_contains($source,'printify')?'pod_personalized':(str_contains($source,'order')||str_contains($source,'fulfill')?'orders':'attention')));
        return $this->url($view);
    }

    private function auditReconciliationOperations():void
    {
        $auditIdentity=(new AuditCorrelationReadModel())->recent('', '',50);
        $attention=(new AttentionReadModel())->summary();$etsy=(new EtsyReconciliationOperatorReadModel())->recent(50);$printify=(array)(($attention['printify_reconciliation']??[])['items']??[]);$exceptions=(new OperationalExceptionReadModel())->snapshot(50);
        $etsyRecentCount=count($etsy);
        $printifyFocus=isset($_GET['df_printify_unknown'])?absint(wp_unslash($_GET['df_printify_unknown'])):0;
        $focusedPrintify=$printifyFocus>0?array_values(array_filter($printify,static fn(array $r):bool=>(int)($r['id']??0)===$printifyFocus)):[];
        if($focusedPrintify!==[]){$printify=array_merge($focusedPrintify,array_values(array_filter($printify,static fn(array $r):bool=>(int)($r['id']??0)!==$printifyFocus)));}
        $etsyFocus=isset($_GET['df_etsy_operation'])?absint(wp_unslash($_GET['df_etsy_operation'])):0;
        $focusedEtsy=$etsyFocus>0?(new EtsyReconciliationOperatorReadModel())->byId($etsyFocus):null;
        if($focusedEtsy!==null&&!in_array($etsyFocus,array_map('intval',array_column($etsy,'id')),true))array_unshift($etsy,$focusedEtsy);
        echo '<section class="df-panel"><div class="df-panel-head"><div><h2>Audit / Reconciliation</h2><p>Cross-channel exception evidence. UNKNOWN is not success and must reconcile before any retry.</p></div><span class="df-status">READ ONLY</span></div><div class="df-signal-grid"><div><span>Recent Etsy reconciliation (up to 50)</span><b>'.esc_html((string)$etsyRecentCount).'</b></div><div><span>Printify unknown evidence</span><b>'.esc_html((string)count($printify)).'</b></div><div><span>Retry authority</span><b>NO</b></div><div><span>External execution authority</span><b>NO</b></div><div><span>Fulfillment exceptions</span><b>'.esc_html((string)count((array)$exceptions['fulfillment'])).'</b></div><div><span>Finance/GST exceptions</span><b>'.esc_html((string)(count((array)$exceptions['finance_ledger'])+count((array)$exceptions['tax']))).'</b></div></div>';
        if($etsyFocus>0&&$focusedEtsy===null)echo '<div class="df-notice df-notice-error">Requested Etsy reconciliation evidence is unavailable or no longer requires reconciliation. No retry is inferred.</div>';
        if($printifyFocus>0&&$focusedPrintify===[])echo '<div class="df-notice df-notice-error">Requested provider UNKNOWN evidence is unavailable. Reconcile before any retry; this view grants no execution authority.</div>';
        if($etsy!==[]){echo '<div class="df-table-wrap"><table class="df-table"><thead><tr><th>Etsy op</th><th>Shop</th><th>Type</th><th>State</th><th>Resource</th><th>Action</th><th>Workflow</th></tr></thead><tbody>';foreach($etsy as $row){echo '<tr'.((int)$row['id']===$etsyFocus?' id="df-etsy-operation-'.esc_attr((string)$etsyFocus).'"':'').'><td>#'.esc_html((string)$row['id']).'</td><td>'.esc_html((string)$row['shop_reference']).'</td><td>'.esc_html((string)$row['operation_type']).'</td><td>'.esc_html((string)$row['state']).'</td><td>'.esc_html((string)$row['resource_reference']).'</td><td>RECONCILE BEFORE ANY RETRY</td><td><a class="df-button df-button-compact" href="'.esc_url($this->url('listings')).'">Open Listings & Etsy</a></td></tr>';}echo '</tbody></table></div>';}else{echo '<div class="df-empty">No Etsy UNKNOWN/reconciliation-required operations.</div>';}
        if($printify!==[]){echo '<div class="df-table-wrap"><table class="df-table"><thead><tr><th>Authorization</th><th>Resolution</th><th>Operator action</th><th>Retry</th><th>Workflow</th></tr></thead><tbody>';foreach(array_slice($printify,0,50) as $row){$id=(int)($row['id']??0);$identity='<code>'.esc_html(substr((string)($row['authorization_hash']??''),0,12)).'…</code>';echo '<tr'.($id>0&&$id===$printifyFocus?' id="df-printify-unknown-'.esc_attr((string)$id).'"':'').'><td>'.($id>0?'<a href="'.esc_url(add_query_arg(['df_printify_unknown'=>$id],$this->url('audit')).'#df-printify-unknown-'.$id).'">'.$identity.'</a>':$identity.' <span class="df-muted">NO RECORD ID</span>').'</td><td>'.esc_html((string)($row['resolution_state']??'UNKNOWN')).'</td><td>'.esc_html((string)($row['operator_action']??'RECONCILE_BEFORE_ANY_RETRY')).'</td><td>NO</td><td><a class="df-button df-button-compact" href="'.esc_url($this->url('pod_personalized')).'">Open POD workflow</a></td></tr>';}echo '</tbody></table></div>';}else{echo '<div class="df-empty">No Printify UNKNOWN evidence.</div>';}
        echo '<p class="df-muted">These links only navigate to governed internal workflows. This view cannot retry, publish, produce, fulfill, refund, change tax state, or move money.</p><p class="df-muted">Bounded non-secret audit identities available: '.esc_html((string)count($auditIdentity)).'. Audit context payloads are excluded; visibility never grants retry or external execution authority.</p></section>';
        $this->panelTable('Audit Log',Tables::audit_log());
    }

    private function listingOperations():void
    {
        $webhook=EtsyWebhookReadiness::inspect();
        $attachments=(new EtsyDigitalAttachmentReadModel())->recent(50);
        echo '<section class="df-panel"><div class="df-panel-head"><div><h2>Etsy webhook configuration</h2><p>Server-side configuration evidence only. No subscription or provider connectivity check is performed here.</p></div><span class="df-status">READ ONLY</span></div><div class="df-signal-grid"><div><span>Configuration</span><b>'.esc_html((string)$webhook['state']).'</b></div><div><span>Signing secret configured</span><b>'.(!empty($webhook['signing_secret_configured'])?'YES':'NO').'</b></div><div><span>Order intake</span><b>LOCAL ONLY</b></div><div><span>Fulfillment authority</span><b>NO</b></div></div><p class="df-muted">Configure the Etsy-issued signing secret on the server. Do not enter secrets in the portal. This status does not verify Etsy subscription delivery or authorize order automation.</p></section>';
        echo '<section class="df-panel"><div class="df-panel-head"><div><h2>Confirmed digital file identity evidence</h2><p>Local operation ledger evidence for accepted Etsy uploads. This does not verify the current provider listing or repeat the upload.</p></div><span class="df-status">READ ONLY</span></div>';
        if($attachments===[]){echo '<div class="df-empty">No confirmed digital file upload evidence in the recent window.</div>';}else{echo '<div class="df-table-wrap"><table class="df-table"><thead><tr><th>Operation</th><th>Shop</th><th>Etsy listing</th><th>Listing file</th><th>Identity evidence</th><th>Updated</th><th>Authority</th></tr></thead><tbody>';foreach($attachments as $file){echo '<tr><td>#'.esc_html((string)$file['operation_id']).'</td><td>'.esc_html((string)$file['shop_reference']).'</td><td>'.esc_html((string)$file['listing_id']).'</td><td>'.esc_html((string)$file['listing_file_id']).'</td><td>'.esc_html((string)$file['evidence_state']).'</td><td>'.esc_html((string)$file['updated_at']).'</td><td>NO UPLOAD / NO PUBLISH</td></tr>';}echo '</tbody></table></div>';}echo '<p class="df-muted">Up to 50 recent confirmed operations are shown. Missing or conflicting persisted identity is REVIEW_REQUIRED. Recorded success does not prove live Etsy file availability and never grants a retry, upload or publish action.</p></section>';
        $recon=(new WebhookReconciliationReadModel())->snapshot('etsy');$counts=(array)$recon['counts'];
        echo '<section class="df-panel"><div class="df-panel-head"><div><h2>Etsy webhook reconciliation</h2><p>Verified inbound evidence only. This view never retries Etsy actions.</p></div><span class="df-status">READ ONLY</span></div><div class="df-signal-grid"><div><span>Verified</span><b>'.esc_html((string)$counts['verified']).'</b></div><div><span>Processed</span><b>'.esc_html((string)$counts['processed']).'</b></div><div><span>Failed</span><b>'.esc_html((string)$counts['failed']).'</b></div><div><span>Unresolved</span><b>'.esc_html((string)$counts['unresolved']).'</b></div><div><span>Evidence discrepancies</span><b>'.esc_html((string)($counts['discrepancies']??0)).'</b></div></div><p class="df-muted">Reconciliation never retries webhook processing automatically.</p></section>';
        foreach($this->tables('listings') as $label=>$table)$this->panelTable($label,$table);
    }

    private function orderOperations():void
    {
        $rows=(new OrderOperationsReadModel())->recent();$orderReconciliation=new OrderReconciliationReadModel();$exceptions=(new OperationalExceptionReadModel())->snapshot(50);$fulfillmentExceptions=(array)$exceptions['fulfillment'];
        echo '<section class="df-panel"><div class="df-panel-head"><div><h2>Order readiness</h2><p>Readiness evidence is separate from external fulfillment authorization.</p></div><span class="df-status">READ ONLY</span></div>';
        if($rows===[]){echo '<div class="df-empty">No orders found in the recent window.</div>';}else{
        echo '<div class="df-table-wrap"><table class="df-table"><thead><tr><th>Order</th><th>Shop</th><th>State</th><th>Mode</th><th>Ready</th><th>External authorization</th></tr></thead><tbody>';
        foreach($rows as $row){$r=(array)$row['readiness'];$rec=$orderReconciliation->forOrder((int)$row['id']);$disc=$orderReconciliation->discrepancies((int)$row['id'],25);echo '<tr><td>#'.esc_html((string)$row['id']).'</td><td>'.esc_html((string)$row['shop_reference']).'</td><td>'.esc_html((string)$row['state']).'</td><td>'.esc_html((string)($r['fulfillment_mode']??'UNKNOWN')).'</td><td>'.(!empty($r['ready'])?'YES':'NO').' / reconciliation '.(!empty($rec['reconciled'])?'OK':'REVIEW').' / discrepancies '.esc_html((string)count($disc)).'</td><td>NO</td></tr>';}
        echo '</tbody></table></div>';}echo '<p class="df-muted">Fulfillment exceptions requiring evidence review: '.esc_html((string)$exceptions['fulfillment_total']).'. The most recent 25 are displayed. This count does not authorize provider execution.</p>';if($fulfillmentExceptions!==[]){echo '<div class="df-table-wrap"><table class="df-table"><thead><tr><th>Intent</th><th>Order</th><th>State</th><th>Audit identity</th><th>Workflow</th></tr></thead><tbody>';foreach(array_slice($fulfillmentExceptions,0,25) as $row){echo '<tr><td>#'.esc_html((string)$row['id']).' '.esc_html((string)$row['intent_type']).'</td><td>#'.esc_html((string)$row['order_id']).'</td><td>'.esc_html((string)$row['state']).'</td><td><code>fulfillment_intent:'.esc_html((string)$row['id']).'</code></td><td><a class="df-button df-button-compact" href="'.esc_url($this->url('audit')).'">Open audit/reconciliation</a></td></tr>';}echo '</tbody></table></div>';}echo '</section>';
    }

    private function aiBudgetOperations():void
    {
        $shop=ShopOperationsReadModel::normalize(isset($_GET['df_shop'])?sanitize_key(wp_unslash($_GET['df_shop'])):ShopOperationsReadModel::ALL);$data=(new OperationalDepthReadModel())->aiBudget($shop);$cost=(array)$data['cost'];$policies=(array)$data['policies'];$hierarchy=(new HierarchicalPolicyReadModel())->snapshot($shop);$scenarios=(new HierarchicalPolicyReadModel())->scenarios($shop);
        echo '<section class="df-panel"><div class="df-panel-head"><div><h2>AI & Budget</h2><p>Shop-scoped policy identities, quantities and estimated/actual spend. Policy evidence never grants execution authority. Global disable always wins; a narrower shop/workflow scope cannot override a disabled parent.</p></div><span class="df-status">READ ONLY</span></div><div class="df-signal-grid"><div><span>Shop scope</span><b>'.esc_html(ShopOperationsReadModel::shops()[$shop]).'</b></div><div><span>Active policy records</span><b>'.esc_html((string)count($policies)).'</b></div><div><span>Attributed quantity</span><b>'.esc_html((string)($cost['quantity']??0)).'</b></div><div><span>Estimated spend</span><b>'.esc_html(number_format((float)($cost['estimated_cost']??0),4)).'</b></div><div><span>Actual spend</span><b>'.esc_html(number_format((float)($cost['actual_cost']??0),4)).'</b></div><div><span>External execution authority</span><b>NO</b></div></div>';
        if($policies!==[]){echo '<div class="df-table-wrap"><table class="df-table"><thead><tr><th>Shop</th><th>Environment</th><th>Currency</th><th>State</th><th>Policy identity</th><th>Updated</th></tr></thead><tbody>';foreach($policies as $row){echo '<tr><td>'.esc_html((string)$row['shop_key']).'</td><td>'.esc_html((string)$row['environment']).'</td><td>'.esc_html((string)$row['currency']).'</td><td>'.esc_html((string)$row['state']).'</td><td><code>'.esc_html(substr((string)$row['policy_hash'],0,12)).'…</code></td><td>'.esc_html((string)$row['updated_at']).'</td></tr>';}echo '</tbody></table></div>';}else{echo '<div class="df-empty">No AI policy exists for this shop scope.</div>';}echo '<p class="df-muted">Planning scenarios evaluated: '.esc_html((string)count((array)$scenarios['scenarios'])).'. Quantities 1 / 5 / 10 are evaluated for each governed stage against the current shop policy and budget ceilings. Scenarios are preflight evidence only; they do not run AI or authorize external execution.</p></section>';
    }

    private function fulfillmentProviders():void
    {
        $plans=(new OperationalDepthReadModel())->fulfillmentProviders(50);$providerStatus=(new ProviderStatusReadModel())->snapshot(25);
        echo '<section class="df-panel"><div class="df-panel-head"><div><h2>Fulfillment / Providers</h2><p>Provider-plan evidence and readiness identities. A prepared or approved plan is not production authorization.</p></div><span class="df-status">READ ONLY</span></div>';
        if($plans===[]){echo '<div class="df-empty">No fulfillment plans found.</div></section>';return;}
        $needsReview=count(array_filter($plans,static fn(array $p):bool=>in_array((string)$p['evidence_state'],['EVIDENCE_INVALID_REVIEW','CURRENT_READINESS_UNAVAILABLE','STALE_RECHECK'],true)));
        echo '<p class="df-muted">Recent approved plans needing evidence review: '.esc_html((string)$needsReview).' of up to 50 recent plans. Draft and historical states are excluded. Current evidence never authorizes provider production.</p>';
        echo '<div class="df-table-wrap"><table class="df-table"><thead><tr><th>Plan</th><th>Order</th><th>Provider</th><th>State</th><th>Payload</th><th>Readiness</th><th>Evidence review</th><th>Provider execution</th><th>Production</th></tr></thead><tbody>';foreach($plans as $row){echo '<tr><td>#'.esc_html((string)$row['id']).' '.esc_html((string)$row['plan_version']).'</td><td>#'.esc_html((string)$row['order_id']).'</td><td>'.esc_html((string)$row['provider']).'</td><td>'.esc_html((string)$row['state']).'</td><td><code>'.esc_html(substr((string)$row['payload_hash'],0,12)).'…</code></td><td><code>'.esc_html(substr((string)$row['readiness_hash'],0,12)).'…</code></td><td>'.esc_html((string)$row['evidence_state']).'</td><td>NO</td><td>NO</td></tr>';}echo '</tbody></table></div><p class="df-muted">Provider execution and production require their separate governed authorization boundary; this view cannot submit or retry a provider order.</p><div class="df-signal-grid"><div><span>Printify UNKNOWN outcomes</span><b>'.esc_html((string)$providerStatus['unknown_outcomes']).'</b></div><div><span>Unresolved reconciliation</span><b>'.esc_html((string)$providerStatus['unresolved_reconciliations']).'</b></div><div><span>Provider execution authority</span><b>NO</b></div><div><span>Retry authority</span><b>NO</b></div></div><p class="df-muted">UNKNOWN provider outcomes require reconciliation before any retry; this evidence cannot authorize production.</p></section>';
    }

    private function financeOperations():void
    {
        $shop=ShopOperationsReadModel::normalize(isset($_GET['df_shop'])?sanitize_key(wp_unslash($_GET['df_shop'])):ShopOperationsReadModel::ALL);$kpi=(new CostKpiReadModel())->snapshot($shop);$denominators=(new LifecycleDenominatorReadModel())->snapshot();$exceptions=(new OperationalExceptionReadModel())->snapshot(50);$ledgerExceptions=(array)$exceptions['finance_ledger'];$taxExceptions=(array)$exceptions['tax'];
        echo '<section class="df-panel"><div class="df-panel-head"><div><h2>AI cost KPIs</h2><p>Actual and estimated attributable AI cost for the selected shop scope.</p></div><span class="df-status">READ ONLY</span></div><div class="df-signal-grid"><div><span>Attributed units</span><b>'.esc_html((string)$kpi['quantity']).'</b></div><div><span>Estimated cost</span><b>'.esc_html(number_format((float)$kpi['estimated_cost'],4)).'</b></div><div><span>Actual cost</span><b>'.esc_html(number_format((float)$kpi['actual_cost'],4)).'</b></div><div><span>Cost / unit</span><b>'.esc_html(number_format((float)$kpi['cost_per_attributed_unit'],4)).'</b></div></div></section>';echo '<section class="df-panel"><div class="df-panel-head"><div><h2>Lifecycle denominators</h2><p>Authoritative raw lifecycle counts only. They are not attributed to AI spend.</p></div><span class="df-status">READ ONLY</span></div><div class="df-signal-grid"><div><span>Opportunities</span><b>'.esc_html((string)$denominators['opportunities']).'</b></div><div><span>Developed products</span><b>'.esc_html((string)$denominators['developed_products']).'</b></div><div><span>Approved listings</span><b>'.esc_html((string)$denominators['approved_listings']).'</b></div><div><span>Received orders</span><b>'.esc_html((string)$denominators['received_orders']).'</b></div></div><p class="df-muted">AI cost attribution: NOT ESTABLISHED</p></section>';echo '<section class="df-panel"><div class="df-panel-head"><div><h2>Finance / GST exceptions</h2><p>Unreconciled ledger and tax-review evidence only.</p></div><span class="df-status">READ ONLY</span></div><div class="df-signal-grid"><div><span>Ledger exceptions</span><b>'.esc_html((string)count($ledgerExceptions)).'</b></div><div><span>Tax/GST review exceptions</span><b>'.esc_html((string)count($taxExceptions)).'</b></div><div><span>Money movement authority</span><b>NO</b></div><div><span>Tax filing authority</span><b>NO</b></div></div><p class="df-muted">Exception evidence never files GST/tax, changes tax classification, issues refunds, or moves money.</p>';if($ledgerExceptions!==[]||$taxExceptions!==[]){echo '<div class="df-table-wrap"><table class="df-table"><thead><tr><th>Type</th><th>ID</th><th>Source</th><th>Status</th><th>Audit identity</th><th>Workflow</th></tr></thead><tbody>';foreach(array_slice($ledgerExceptions,0,25) as $row){echo '<tr><td>Ledger</td><td>#'.esc_html((string)$row['id']).'</td><td>'.esc_html((string)$row['source_type']).' #'.esc_html((string)$row['source_id']).'</td><td>'.esc_html((string)$row['reconciliation_state']).'</td><td><code>finance_ledger:'.esc_html((string)$row['id']).'</code></td><td><a class="df-button df-button-compact" href="'.esc_url($this->url('audit')).'">Open audit/reconciliation</a></td></tr>';}foreach(array_slice($taxExceptions,0,25) as $row){echo '<tr><td>Tax/GST</td><td>#'.esc_html((string)$row['id']).'</td><td>'.esc_html((string)$row['source_type']).' #'.esc_html((string)$row['source_id']).'</td><td>'.esc_html((string)$row['classification']).' / '.esc_html((string)$row['review_status']).'</td><td><code>tax_classification:'.esc_html((string)$row['id']).'</code></td><td><a class="df-button df-button-compact" href="'.esc_url($this->url('audit')).'">Open audit/reconciliation</a></td></tr>';}echo '</tbody></table></div>';}echo '</section>';
        foreach($this->tables('finance') as $label=>$table)$this->panelTable($label,$table);
    }

    private function businessOperations(): void
    {
        $shop=ShopOperationsReadModel::normalize(isset($_GET['df_shop'])?sanitize_key(wp_unslash($_GET['df_shop'])):ShopOperationsReadModel::ALL);
        $ops=(new ShopOperationsReadModel())->snapshot($shop);
        echo '<section class="df-panel"><div class="df-panel-head"><div><h2>Businesses / Brands / Shops</h2><p>Shop-scoped operating context. AI policy and usage evidence do not grant external execution authority.</p></div><span class="df-status">READ ONLY</span></div><div class="df-signal-grid"><div><span>Selected scope</span><b>'.esc_html((string)$ops['shop_label']).'</b></div><div><span>AI policies</span><b>'.esc_html((string)count((array)$ops['ai_policies'])).'</b></div><div><span>AI usage groups</span><b>'.esc_html((string)count((array)$ops['ai_usage'])).'</b></div><div><span>External execution</span><b>NO</b></div></div></section>';
    }

    private function analyticsOperations(): void
    {
        $shop=ShopOperationsReadModel::normalize(isset($_GET['df_shop'])?sanitize_key(wp_unslash($_GET['df_shop'])):ShopOperationsReadModel::ALL);
        $kpi=(new CostKpiReadModel())->snapshot($shop);$denominators=(new LifecycleDenominatorReadModel())->snapshot();
        echo '<section class="df-panel"><div class="df-panel-head"><div><h2>Analytics</h2><p>Read-only operational measurement, separated from finance ledger administration.</p></div><span class="df-status">READ ONLY</span></div><div class="df-signal-grid"><div><span>Attributed units</span><b>'.esc_html((string)$kpi['quantity']).'</b></div><div><span>Actual AI cost</span><b>'.esc_html(number_format((float)$kpi['actual_cost'],4)).'</b></div><div><span>Developed products</span><b>'.esc_html((string)$denominators['developed_products']).'</b></div><div><span>Received orders</span><b>'.esc_html((string)$denominators['received_orders']).'</b></div></div><p class="df-muted">Analytics is evidence only and does not infer publish, production, refund, tax or money-movement authority.</p></section>';
        $this->panelTable('Analytics Snapshots',Tables::analytics_snapshots());
    }

    private function automationOperations(): void
    {
        $health=(new \DigiForge\Observability\HealthMonitor())->snapshot();$q=(array)($health['queue']??[]);$queue=(array)($q['recovery']??[]);$states=(array)($queue['states']??[]);$queueRead=new OperatorQueueReadModel();$items=$queueRead->recentAttention(50);$diagnostics=$queueRead->diagnostics();$recoveryEvidence=$queueRead->recoveryEvidence(25);$recoveryClass=(new OperatorQueueReadModel())->recoveryClassification();$idempotency=(new OperatorQueueReadModel())->unresolvedIdempotency(25);
        $jobFocus=isset($_GET['df_job_id'])?absint(wp_unslash($_GET['df_job_id'])):0;
        $focusedJob=$jobFocus>0?$queueRead->attentionById($jobFocus):null;
        if($focusedJob!==null&&!in_array($jobFocus,array_map('intval',array_column($items,'id')),true))array_unshift($items,$focusedJob);
        echo '<section class="df-panel"><div class="df-panel-head"><div><h2>Automation / Queues</h2><p>Read-only queue and recovery visibility. Viewing this page never runs, retries or replays a job.</p></div><span class="df-status">READ ONLY</span></div><div class="df-signal-grid"><div><span>Queue query</span><b>'.(!empty($q['query_ok'])?'VERIFIED':'FAILED').'</b></div><div><span>Expired leases</span><b>'.esc_html((string)($q['expired_leases']??0)).'</b></div><div><span>FAILED</span><b>'.esc_html((string)($states['FAILED']??0)).'</b></div><div><span>BLOCKED</span><b>'.esc_html((string)($states['BLOCKED']??0)).'</b></div><div><span>HUMAN_REVIEW</span><b>'.esc_html((string)($states['HUMAN_REVIEW']??0)).'</b></div><div><span>DEAD_LETTER</span><b>'.esc_html((string)($states['DEAD_LETTER']??0)).'</b></div><div><span>Execution authority</span><b>NO</b></div><div><span>Automatic retry from view</span><b>NO</b></div></div>';
        echo '<div class="df-signal-grid"><div><span>Orphaned expired leases</span><b>'.esc_html((string)$diagnostics['orphaned_expired_leases']).'</b></div><div><span>Rate-limit evidence</span><b>'.esc_html((string)$diagnostics['rate_limit_evidence']).'</b></div><div><span>Unresolved idempotency</span><b>'.esc_html((string)$diagnostics['unresolved_idempotency_evidence']).'</b></div><div><span>Replay permitted</span><b>NO</b></div></div>';
        if($idempotency!==[]){echo '<p class="df-muted">Bounded unresolved idempotency drill-down: '.esc_html((string)count($idempotency)).' records. Operation keys are redacted to SHA-256 identities; replay and retry remain NO.</p>';}echo '<p class="df-muted">Recovery classifications: '.esc_html($recoveryClass['classifications']===[]?'NONE':implode(', ',$recoveryClass['classifications'])).'. Classification is evidence only; automatic recovery, replay and retry remain NO.</p>'; 
        echo '<p class="df-muted"><strong>Recovery guidance:</strong> UNKNOWN external outcomes must be reconciled before any retry. FAILED/BLOCKED/HUMAN_REVIEW/DEAD_LETTER are evidence for operator inspection, not retry permission. Use Attention & Recovery for cross-channel reconciliation evidence.</p><p class="df-muted">Correlated recovery evidence: '.esc_html((string)$recoveryEvidence['correlation']['attention_count']).' attention records / '.esc_html((string)$recoveryEvidence['correlation']['idempotency_count']).' unresolved idempotency records. Automatic recovery authority: NO.</p>';
        if($jobFocus>0&&$focusedJob===null)echo '<div class="df-notice df-notice-error">Requested queue attention record is unavailable or no longer needs review. No retry or replay is inferred.</div>';
        if($items===[]){echo '<div class="df-empty">No queue items currently require operator attention.</div>';}else{echo '<div class="df-table-wrap"><table class="df-table"><thead><tr><th>ID</th><th>Type</th><th>State</th><th>Attempts</th><th>Last error</th><th>Updated</th><th>Retry</th><th>Authority</th></tr></thead><tbody>';foreach($items as $item){$id=(int)$item['id'];echo '<tr'.($id===$jobFocus?' id="df-job-'.esc_attr((string)$id).'"':'').'><td><a href="'.esc_url(add_query_arg(['df_job_id'=>$id],$this->url('automation')).'#df-job-'.$id).'">#'.esc_html((string)$id).'</a></td><td>'.esc_html((string)$item['job_type']).'</td><td>'.esc_html((string)$item['state']).'</td><td>'.esc_html((string)$item['attempts']).'/'.esc_html((string)$item['max_attempts']).'</td><td>'.esc_html((string)($item['last_error']??'')).'</td><td>'.esc_html((string)$item['updated_at']).'</td><td>NO</td><td>NO</td></tr>';}echo '</tbody></table></div>';}echo '</section>';
    }

    private function settingsOperations(): void
    {
        $readiness=(new Readiness())->report();$switches=(array)($readiness['effective_switches']??[]);$policyRepo=new ScopedCapabilityPolicyRepository();$policyCurrent=$policyRepo->current(100);$policyHistory=$policyRepo->recent('', '',50);
        echo '<section class="df-panel"><div class="df-panel-head"><div><h2>Settings</h2><p>Operational configuration summary. High-risk execution controls remain in System & Controls with their existing authorization gates.</p></div><span class="df-status">READ ONLY</span></div><div class="df-signal-grid"><div><span>Version</span><b>'.esc_html(DIGIFORGE_VERSION).'</b></div><div><span>Schema</span><b>'.esc_html((string)($readiness['schema']['current']??'UNKNOWN')).'</b></div><div><span>Externally locked</span><b>'.(!empty($readiness['externally_locked'])?'YES':'NO').'</b></div><div><span>Readiness</span><b>'.esc_html((string)($readiness['status']??'REVIEW_REQUIRED')).'</b></div><div><span>Effective switches ON</span><b>'.esc_html((string)count(array_filter($switches))).'</b></div><div><span>Recovery drill</span><b>'.(!empty($readiness['checks']['recovery_drill_passed'])?'PASS':'NOT PASSED').'</b></div></div><p class="df-muted">This page is observational. It cannot enable research, AI, Etsy, POD, order automation, GST automation or external execution.</p><div class="df-signal-grid"><div><span>Current scoped policies</span><b>'.esc_html((string)count($policyCurrent)).'</b></div><div><span>Recent policy evidence</span><b>'.esc_html((string)count($policyHistory)).'</b></div><div><span>Policy execution authority</span><b>NO</b></div></div><p class="df-muted">Scoped policy history is append-only evidence. A policy row cannot override STOP ALL or grant external execution authority.</p></section>';
    }

    private function personalizedPodSummary(): void
    {
        $m=PersonalizedCatalogReference::metadata();
        $ops=(new ShopOperationsReadModel())->snapshot('personalized_pod');$catalog=(array)($ops['catalog']??[]);
        echo '<section class="df-panel"><div class="df-panel-head"><div><h2>Master 500 governed source</h2><p>Immutable Personalized POD reference. Catalog data is evidence, not production authority.</p></div><span class="df-status">'.esc_html((string)$m['source_state']).'</span></div>';
        echo '<div class="df-signal-grid"><div><span>Catalog</span><b>'.esc_html((string)$m['catalog_key']).'</b></div><div><span>Concepts</span><b>'.esc_html((string)$m['listing_count']).'</b></div><div><span>Engines</span><b>'.esc_html((string)$m['personalization_engine_count']).'</b></div><div><span>Production authority</span><b>NO</b></div></div></section>';
        if($catalog!==[])echo '<section class="df-panel"><div class="df-panel-head"><div><h2>Governed catalog version</h2><p>Persisted provenance and migration lineage. Visibility does not grant production authority.</p></div><span class="df-status">'.esc_html((string)($catalog['source_state']??'UNKNOWN')).'</span></div><div class="df-signal-grid"><div><span>Version</span><b>'.esc_html((string)($catalog['version_label']??'' )).'</b></div><div><span>Rows</span><b>'.esc_html((string)($catalog['row_count']??0)).'</b></div><div><span>Parent version</span><b>'.esc_html((string)($catalog['parent_version_id']??0)).'</b></div><div><span>Authority</span><b>'.(!empty($catalog['production_authority'])?'YES':'NO').'</b></div></div><p class="df-muted">Source SHA256: '.esc_html((string)($catalog['source_sha256']??'')).' · Fingerprint: '.esc_html((string)($catalog['fingerprint']??'')).'</p></section>';
    }

    private function topbar(string $view): void
    {
        $readiness = (new Readiness())->report();
        $stopAll = Settings::get('stop_all', true) === true;
        $shop=ShopOperationsReadModel::normalize(isset($_GET['df_shop'])?sanitize_key(wp_unslash($_GET['df_shop'])):ShopOperationsReadModel::ALL);
        ?>
        <header class="df-topbar">
            <div>
                <p class="df-eyebrow">DigiCraftify automation platform</p>
                <h1><?php echo esc_html(self::NAV[$view]['label']); ?></h1>
            </div>
            <div class="df-topbar-status">
                <form method="get" class="df-shop-selector"><input type="hidden" name="df_view" value="<?php echo esc_attr($view); ?>"><label>Shop <select name="df_shop" onchange="this.form.submit()"><?php foreach(ShopOperationsReadModel::shops() as $key=>$label): ?><option value="<?php echo esc_attr($key); ?>" <?php selected($shop,$key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label></form>
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
            'Open operational alerts' => $this->countExcludingStates(Tables::operational_alerts(), 'state', ['RESOLVED', 'DISMISSED', 'SUPERSEDED', 'CLOSED']),
            'Pending listing decisions' => $this->countByState(Tables::listing_readiness_reviews(), 'decision', 'PENDING'),
            'Personalization reviews' => $this->countExcludingStates(Tables::personalization_submissions(), 'review_status', ['APPROVED', 'REJECTED']),
            'Pending POD decisions' => $this->countByState(Tables::pod_readiness_reviews(), 'decision', 'PENDING'),
            'Pending fulfillment decisions' => $this->countByState(Tables::fulfillment_readiness_reviews(), 'decision', 'PENDING'),
            'Blocked finance intents' => $this->countByState(Tables::finance_intents(), 'state', 'BLOCKED'),
            'Integrations' => $this->count(Tables::integrations()),
        ];
        $shop=ShopOperationsReadModel::normalize(isset($_GET['df_shop'])?sanitize_key(wp_unslash($_GET['df_shop'])):ShopOperationsReadModel::ALL);
        $shopOps=(new ShopOperationsReadModel())->snapshot($shop);
        echo '<section class="df-panel"><div class="df-panel-head"><div><h2>Current shop scope</h2><p>Dashboard counts remain authoritative global counts unless explicitly labeled shop-scoped.</p></div><span class="df-status">READ ONLY</span></div><div class="df-signal-grid"><div><span>Scope</span><b>'.esc_html((string)$shopOps['shop_label']).'</b></div><div><span>AI policies in scope</span><b>'.esc_html((string)count((array)$shopOps['ai_policies'])).'</b></div><div><span>AI usage groups in scope</span><b>'.esc_html((string)count((array)$shopOps['ai_usage'])).'</b></div><div><span>External execution</span><b>NO</b></div></div></section>';
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
        $attention=(new AttentionReadModel())->summary();
        $preflight = (new ResearchActivationPreflight())->report();$health=(new \DigiForge\Observability\HealthMonitor())->snapshot();$queueRecovery=(array)($health['queue']['recovery']??[]);$readiness=(new Readiness())->report();
        $blockers = array_values(array_filter((array) ($preflight['blockers'] ?? []), 'is_scalar'));
        $alerts = $wpdb->get_results(
            'SELECT id,environment,alert_type,source_type,source_id,severity,state,created_at,updated_at '
            . 'FROM ' . Tables::operational_alerts()
            . " WHERE state NOT IN ('RESOLVED','DISMISSED','SUPERSEDED','CLOSED') ORDER BY FIELD(severity,'CRITICAL','ERROR','WARNING','INFO'), id DESC LIMIT 50",
            ARRAY_A
        );
        $alertFocus=isset($_GET['df_alert_id'])?absint(wp_unslash($_GET['df_alert_id'])):0;
        $focusedAlert=$alertFocus>0?$wpdb->get_row($wpdb->prepare('SELECT id,environment,alert_type,source_type,source_id,severity,state,created_at,updated_at FROM '.Tables::operational_alerts()." WHERE id=%d AND state NOT IN ('RESOLVED','DISMISSED','SUPERSEDED','CLOSED')",$alertFocus),ARRAY_A):null;
        if(is_array($focusedAlert)&&!in_array($alertFocus,array_map('intval',array_column(is_array($alerts)?$alerts:[],'id')),true))$alerts=array_merge([$focusedAlert],is_array($alerts)?$alerts:[]);
        echo '<section class="df-card-grid"><article class="df-stat-card"><span>Total attention items</span><strong>'.esc_html((string)$attention['total_attention']).'</strong></article><article class="df-stat-card"><span>Personalization reviews</span><strong>'.esc_html((string)$attention['personalization_reviews']).'</strong></article><article class="df-stat-card"><span>Fulfillment decisions</span><strong>'.esc_html((string)$attention['fulfillment_decisions']).'</strong></article><article class="df-stat-card"><span>Open alerts</span><strong>'.esc_html((string)$attention['open_operational_alerts']).'</strong></article><article class="df-stat-card"><span>Orders needing reconciliation</span><strong>'.esc_html((string)$attention['orders_needing_reconciliation']).'</strong></article></section>';
        $integrity=(array)($attention['production_provenance_integrity_projection']??[]);$persistence=(array)($attention['production_permit_persistence_drilldown']??[]);
        $recoveryEvidence=RecoveryEvidence::snapshot();$dbEvidence=(array)($recoveryEvidence['database_backup']??[]);$pkgEvidence=(array)($recoveryEvidence['plugin_package']??[]);$backupHistory=(array)($recoveryEvidence['database_backup_history']??[]);
        echo '<section class="df-panel"><div class="df-panel-head"><div><h2>Queue recovery & release safety</h2><p>Queue failures, concrete recovery evidence and release locks are operator-visible. Visibility does not execute recovery.</p></div><span class="df-status">READ ONLY</span></div><div class="df-signal-grid"><div><span>Queue query</span><b>'.(!empty($queueRecovery['query_ok'])?'VERIFIED':'FAILED').'</b></div><div><span>Queue attention</span><b>'.esc_html((string)($queueRecovery['attention_total']??0)).'</b></div><div><span>Recovery drill</span><b>'.esc_html((string)($readiness['recovery']['status']??'UNKNOWN')).'</b></div><div><span>DB backup evidence</span><b>'.esc_html((string)($recoveryEvidence['database_backup_status']??'MISSING')).'</b></div><div><span>Rollback package evidence</span><b>'.(!empty($recoveryEvidence['plugin_package_retrievable'])&&!empty($recoveryEvidence['plugin_package_identity_recorded'])&&!empty($recoveryEvidence['checksum_verified'])?'VERIFIED':'MISSING / UNPROVEN').'</b></div><div><span>Externally locked</span><b>'.(!empty($readiness['externally_locked'])?'YES':'NO').'</b></div></div><div class="df-table-wrap"><table class="df-table"><thead><tr><th>Evidence</th><th>Identifier</th><th>Captured/version</th><th>Source/checksum</th><th>Location</th><th>Retrievable</th></tr></thead><tbody><tr><td>Database backup</td><td>'.esc_html((string)($dbEvidence['identifier']??'NOT RECORDED')).'</td><td>'.esc_html((string)($dbEvidence['captured_at']??'—')).'</td><td>—</td><td>'.esc_html((string)($dbEvidence['location']??'—')).'</td><td>'.(!empty($recoveryEvidence['database_backup_retrievable'])?'VERIFIED / FRESH '.esc_html((string)($dbEvidence['verified_at']??'')):(!empty($dbEvidence['verified_at'])?'STALE / REVERIFY':'NO / UNKNOWN')).'</td></tr><tr><td>Rollback plugin</td><td>'.esc_html((string)($pkgEvidence['identifier']??'NOT RECORDED')).'</td><td>'.esc_html((string)($pkgEvidence['version']??'—')).'</td><td><code>'.esc_html(isset($pkgEvidence['source_commit'])?substr((string)$pkgEvidence['source_commit'],0,12).'…':'—').'</code> / '.(!empty($pkgEvidence['checksum_verified'])?'SHA VERIFIED':'UNVERIFIED').'</td><td>'.esc_html((string)($pkgEvidence['location']??'—')).'</td><td>'.(!empty($pkgEvidence['retrievable'])?'YES':'NO / UNKNOWN').'</td></tr></tbody></table></div><p class="df-muted">Database backup verification expires after 24 hours. STALE / REVERIFY means independently confirm that the same backup is still retrievable and submit fresh verification provenance; do not merely refresh the timestamp. Missing or UNKNOWN artifact evidence is fail-closed. Evidence never grants retry, activation, Etsy publish, POD production or other external execution authority.</p><p><strong>Backup verification history:</strong> '.esc_html((string)count($backupHistory)).' most recent evidence record(s) available read-only; history never grants retry or execution authority.</p></section>';
        echo '<section class="df-panel"><div class="df-panel-head"><div><h2>Production integrity & persistence evidence</h2><p>Audit and reconciliation evidence only. These records never permit retry or external execution.</p></div><span class="df-status">READ ONLY</span></div><div class="df-signal-grid"><div><span>Current open integrity</span><b>'.esc_html((string)($integrity['open_count']??0)).'</b></div><div><span>Historical integrity</span><b>'.esc_html((string)($integrity['historical_count']??0)).'</b></div><div><span>Acknowledged integrity</span><b>'.esc_html((string)($integrity['acknowledged_count']??0)).'</b></div><div><span>Persistence UNKNOWN</span><b>'.esc_html((string)($attention['production_permit_persistence_unknown']??0)).'</b></div></div>';
        if(!empty($persistence['items'])){echo '<div class="df-table-wrap"><table class="df-table"><thead><tr><th>Authorization</th><th>Package</th><th>Persistence</th><th>First observed</th><th>Last observed</th><th>Retry</th><th>Execution authority</th></tr></thead><tbody>';foreach((array)$persistence['items'] as $row){echo '<tr><td><code>'.esc_html(substr((string)$row['authorization_hash'],0,12)).'…</code></td><td>#'.esc_html((string)$row['package_id']).'</td><td>'.esc_html((string)$row['persistence_state']).'</td><td>'.esc_html((string)$row['first_observed_at']).'</td><td>'.esc_html((string)$row['last_observed_at']).'</td><td>NO</td><td>NO</td></tr>';}echo '</tbody></table></div>';}else{echo '<div class="df-empty">No permit persistence observations.</div>';}echo '</section>';
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
            . '<span>' . esc_html((string) $attention['open_operational_alerts']) . ' open total</span></div>';
        if($alertFocus>0&&!is_array($focusedAlert))echo '<div class="df-notice df-notice-error">Requested operational alert is unavailable or closed. No recovery action is inferred.</div>';
        if (!is_array($alerts) || $alerts === []) {
            echo '<div class="df-empty">No open operational alerts.</div>';
        } else {
            echo '<div class="df-table-wrap"><table class="df-table"><thead><tr><th>ID</th><th>Environment</th><th>Severity</th><th>Type</th><th>Source evidence</th><th>State</th><th>Created</th></tr></thead><tbody>';
            foreach ($alerts as $alert) {
                $sourceUrl=(string)$alert['source_type']==='etsy_operation' && (int)$alert['source_id']>0 ? add_query_arg(['df_etsy_operation'=>(int)$alert['source_id']],$this->url('audit')).'#df-etsy-operation-'.(int)$alert['source_id'] : $this->alertWorkflowUrl((string)$alert['source_type']);
                echo '<tr'.((int)$alert['id']===$alertFocus?' id="df-alert-'.esc_attr((string)$alertFocus).'"':'').'><td><a href="' . esc_url(add_query_arg(['df_alert_id'=>(int)$alert['id']],$this->url('attention')).'#df-alert-'.(int)$alert['id']) . '">#' . esc_html((string) $alert['id']) . '</a></td><td>' . esc_html((string) $alert['environment']) . '</td><td>'
                    . esc_html((string) $alert['severity']) . '</td><td>' . esc_html((string) $alert['alert_type']) . '</td><td>'
                    . ((string) $alert['source_type'] === '' || (int) $alert['source_id'] < 1 ? 'MISSING SOURCE REFERENCE — REVIEW' : '<a href="' . esc_url($sourceUrl) . '">' . esc_html((string) $alert['source_type']) . '#' . esc_html((string) $alert['source_id']) . '</a>') . '</td><td>'
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
        $integrationReadiness=(new IntegrationReadinessReadModel())->snapshot();
        $rows = $integrationReadiness['items'];
        echo '<section class="df-panel"><div class="df-panel-head"><div><h2>Provider integrations</h2>'
            . '<p>Credentials are never displayed in this portal. Readiness is derived from stored integration evidence only; opening this page never performs a provider connectivity test.</p></div><span class="df-status">READ ONLY</span></div><div class="df-signal-grid"><div><span>Total connectors</span><b>'.esc_html((string)$integrationReadiness['counts']['total']).'</b></div><div><span>Enabled</span><b>'.esc_html((string)$integrationReadiness['counts']['enabled']).'</b></div><div><span>Connected evidence</span><b>'.esc_html((string)$integrationReadiness['counts']['connected']).'</b></div><div><span>Needs attention</span><b>'.esc_html((string)$integrationReadiness['counts']['attention']).'</b></div><div><span>External execution authority</span><b>NO</b></div></div><div class="df-integration-grid">';
        foreach (is_array($rows) ? $rows : [] as $row) {
            echo '<article class="df-integration-card"><h3>' . esc_html((string) $row['display_name']) . '</h3>'
                . '<dl class="df-kv">'
                . '<div><dt>Status</dt><dd>' . esc_html((string) $row['status']) . '</dd></div>'
                . '<div><dt>Evidence</dt><dd>' . esc_html((string) $row['evidence_state']) . '</dd></div>'
                . '<div><dt>Review reason</dt><dd>' . esc_html((string) $row['attention_reason']) . '</dd></div>'
                . '<div><dt>Provider</dt><dd>' . esc_html((string) $row['provider']) . '</dd></div>'
                . '<div><dt>Environment</dt><dd>' . esc_html((string) $row['environment']) . '</dd></div>'
                . '<div><dt>Enabled</dt><dd>' . (! empty($row['enabled']) ? 'YES' : 'NO') . '</dd></div>'
                . '<div><dt>Updated</dt><dd>' . esc_html((string) $row['updated_at']) . '</dd></div>'
                . '</dl>'
                . (((string) $row['provider'] === 'ai' && (string) $row['environment'] === 'production') ? '<form class="df-credential-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">' . '<input type="hidden" name="action" value="' . esc_attr(self::SAVE_AI_SECRET_ACTION) . '">' . wp_nonce_field(self::SAVE_AI_SECRET_ACTION, '_wpnonce', true, false) . '<input type="hidden" name="integration_id" value="' . esc_attr((string) $row['id']) . '">' . '<input type="hidden" name="return_url" value="' . esc_url($this->url('integrations')) . '">' . '<label>Replace encrypted AI credential <input type="password" name="credential_value" autocomplete="new-password" required></label>' . '<button type="submit">Save Credential</button></form>' : '')
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
        $focusTables = [
            'listing_review' => Tables::listing_readiness_reviews(),
            'personalization' => Tables::personalization_submissions(),
            'pod_review' => Tables::pod_readiness_reviews(),
            'fulfillment_review' => Tables::fulfillment_readiness_reviews(),
        ];
        $focusType = isset($_GET['df_focus_type']) ? sanitize_key(wp_unslash($_GET['df_focus_type'])) : '';
        $focusId = isset($_GET['df_focus_id']) ? absint(wp_unslash($_GET['df_focus_id'])) : 0;
        $focused = $focusId > 0 && isset($focusTables[$focusType]) && $focusTables[$focusType] === $table;
        if ($focused) {
            $record = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $focusId), ARRAY_A);
            if (is_array($record) && !in_array($focusId, array_map('intval', array_column(is_array($rows) ? $rows : [], 'id')), true)) {
                $rows = array_merge([$record], is_array($rows) ? $rows : []);
            }
            if (!is_array($record)) { echo '<div class="df-notice df-notice-error">Requested evidence record is unavailable. Review the source reference; no decision is inferred.</div>'; }
        }
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
            $anchor = $focused && (int) ($row['id'] ?? 0) === $focusId ? ' id="df-evidence-' . esc_attr($focusType . '-' . $focusId) . '"' : '';
            echo '<tr' . $anchor . '>';
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

    /** @param list<string> $states */
    private function countExcludingStates(string $table, string $column, array $states): int
    {
        global $wpdb;
        if (! preg_match('/^[a-z0-9_]+$/i', $column) || $states === []) { return 0; }
        $placeholders = implode(',', array_fill(0, count($states), '%s'));
        $sql = "SELECT COUNT(*) FROM {$table} WHERE {$column} NOT IN ({$placeholders})";
        return (int) $wpdb->get_var($wpdb->prepare($sql, ...$states));
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

    private function redirectToUrl(string $url, string $message, bool $error = false): never
    {
        $target = wp_validate_redirect($url, $this->baseUrl());
        $target = add_query_arg([
            'df_view' => 'integrations',
            'df_message' => $message,
            'df_error' => $error ? 1 : 0,
        ], $target);
        wp_safe_redirect($target);
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
        $args=['df_view'=>$view];
        if(isset($_GET['df_shop']))$args['df_shop']=ShopOperationsReadModel::normalize(sanitize_key(wp_unslash($_GET['df_shop'])));
        return add_query_arg($args, $this->baseUrl());
    }
}
