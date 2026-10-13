<?php

declare(strict_types=1);

namespace DigiForge\Portal;

use DigiForge\Core\Settings;
use DigiForge\AI\CostKpiReadModel;
use DigiForge\AI\LifecycleDenominatorReadModel;
use DigiForge\AI\ShopAiGovernanceRepository;
use DigiForge\AI\ShopAiPlan;
use DigiForge\Orders\OperationsReadModel as OrderOperationsReadModel;
use DigiForge\Orders\HumanGateOperationsReadModel;
use DigiForge\Orders\Repository as OrderRepository;
use DigiForge\POD\BusinessScopeRepository;
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
use DigiForge\Operations\RecoveryOrchestrator;
use DigiForge\Operations\RecoveryVerificationEvidence;
use DigiForge\Operations\RecoveryDrillReviewCandidate;
use DigiForge\Operations\RecoveryDrillAcceptance;
use DigiForge\Operations\OperationalExceptionReadModel;
use DigiForge\Operations\AuditCorrelationReadModel;
use DigiForge\POD\ProviderStatusReadModel;
use DigiForge\POD\PersonalizedCatalogReference;
use DigiForge\POD\MasterCatalogV2IngestionReadModel;
use DigiForge\POD\MasterCatalogV2AcceptanceRepository;
use DigiForge\POD\PersonalizedPodOperationsReadModel;
use DigiForge\POD\ProductionAuthorizationRepository;
use DigiForge\POD\RenderEvidenceOperationsReadModel;
use DigiForge\POD\RenderEvidenceRepository;
use DigiForge\Finance\OperationsReadModel as FinanceOperationsReadModel;
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
    private const SAVE_AI_POLICY_ACTION = 'digiforge_portal_save_ai_policy';
    private const LISTING_REVIEW_ACTION = 'digiforge_portal_listing_review';
    private const POD_PACKAGE_REVIEW_ACTION = 'digiforge_portal_pod_package_review';
    private const POD_RENDER_REVIEW_ACTION = 'digiforge_portal_pod_render_review';
    private const MASTER500_ACCEPTANCE_ACTION = 'digiforge_portal_master500_acceptance';
    private const RECOVERY_PLAN_ACTION = 'digiforge_portal_recovery_plan';
    private const RECOVERY_ACCEPT_ACTION = 'digiforge_portal_recovery_accept';

    private const PERSONALIZATION_REVIEW_ACTION = 'digiforge_portal_personalization_review';
    private const OWNERSHIP_REVIEW_ACTION = 'digiforge_portal_ownership_review';
    private const NOTIFICATION_PREFERENCES_ACTION = 'digiforge_portal_notification_preferences';
    private const REPLACEMENT_REQUEST_ACTION = 'digiforge_portal_replacement_request';
    private const REPLACEMENT_DECISION_ACTION = 'digiforge_portal_replacement_decision';
    private const DRAFT_QA_REVIEW_ACTION = 'digiforge_portal_draft_qa_review';
    private const DRAFT_QA_PREVIEW_ACTION = 'digiforge_portal_draft_qa_preview';
    private ?array $draftQaPreview = null;
    private array $authorizedShops = [];
    private bool $allShopsAuthorized = false;

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
        add_action('admin_post_' . self::SAVE_AI_POLICY_ACTION, [$this, 'saveAiPolicy']);
        add_action('admin_post_' . self::LISTING_REVIEW_ACTION, [$this, 'listingReview']);
        add_action('admin_post_' . self::POD_PACKAGE_REVIEW_ACTION, [$this, 'podPackageReview']);
        add_action('admin_post_' . self::POD_RENDER_REVIEW_ACTION, [$this, 'podRenderReview']);
        add_action('admin_post_' . self::MASTER500_ACCEPTANCE_ACTION, [$this, 'master500Acceptance']);
        add_action('admin_post_' . self::RECOVERY_PLAN_ACTION, [$this, 'recoveryPlan']);
        add_action('admin_post_' . self::RECOVERY_ACCEPT_ACTION, [$this, 'recoveryAccept']);
        add_action('admin_post_' . self::PERSONALIZATION_REVIEW_ACTION, [$this, 'personalizationReview']);
        add_action('admin_post_' . self::OWNERSHIP_REVIEW_ACTION, [$this, 'ownershipReview']);
        add_action('admin_post_' . self::NOTIFICATION_PREFERENCES_ACTION, [$this, 'saveNotificationPreferences']);
        add_action('admin_post_' . self::REPLACEMENT_REQUEST_ACTION, [$this, 'requestReplacementReview']);
        add_action('admin_post_' . self::REPLACEMENT_DECISION_ACTION, [$this, 'decideReplacementReview']);
        add_action('admin_post_' . self::DRAFT_QA_PREVIEW_ACTION, [$this, 'previewDraftQa']);
        add_action('admin_post_' . self::DRAFT_QA_REVIEW_ACTION, [$this, 'recordDraftQa']);
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

        $access=\DigiForge\Security\ShopAccess::allowedShops(self::NAV[$view]['cap']);
        if(is_wp_error($access))return $this->notice('Shop authorization evidence is unavailable.',true);
        $this->authorizedShops=$access;$this->allShopsAuthorized=count(array_intersect(['digital','personalized_pod','standard_pod','jewelry'],$access))===4;
        $requested=$_GET['df_shop']??'all';
        if(!is_string($requested)||!array_key_exists($requested,ShopOperationsReadModel::shops())||($requested==='all'&&!$this->allShopsAuthorized)||($requested!=='all'&&!in_array($requested,$access,true)))return $this->notice('An explicit assignment is required for the requested shop scope.',true);
        // These views contain global or fixed-shop aggregates; partial grants cannot read them.
        if(!$this->allShopsAuthorized&&!in_array($view,['approvals','research','products','digital','production','orders','fulfillment','ai_budget'],true))return $this->notice('This view contains global evidence and requires assignments for all four shops.',true);
        ob_start();
        ?>
        <div class="df-portal-shell">
            <aside class="df-portal-sidebar">
                <div class="df-brand">
                    <span class="df-brand-mark">DF</span>
                    <div><strong>DigiForge</strong><small>Operations Console</small></div>
                </div>
                <nav class="df-nav" aria-label="DigiForge navigation">
                    <?php foreach (self::NAV as $slug => $item) : ?>
                        <?php if (! current_user_can($item['cap'])) { continue; } ?>
                        <a <?php if ($view === $slug) { echo 'aria-current="page"'; } ?> class="<?php echo $view === $slug ? 'is-active' : ''; ?>"
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

    public function personalizationReview(): void
    {
        $this->guard('manage_digiforge_orders');check_admin_referer(self::PERSONALIZATION_REVIEW_ACTION);
        $id=isset($_POST['submission_id'])?absint($_POST['submission_id']):0;$decision=isset($_POST['decision'])?strtoupper(sanitize_key(wp_unslash($_POST['decision']))):'';
        if($id<1||!in_array($decision,['APPROVED','REJECTED'],true))$this->redirect('orders','Valid personalization submission and APPROVED/REJECTED decision required.',true);
        $this->requireConfirmation(self::PERSONALIZATION_REVIEW_ACTION,$id,'orders');$result=(new OrderRepository())->reviewPersonalization($id,$decision);if(is_wp_error($result))$this->redirect('orders',$result->get_error_message(),true);
        Logger::audit('portal_personalization_human_reviewed',['submission_id'=>$id,'decision'=>$decision,'external_execution_authorized'=>false,'external_execution_performed'=>false],'personalization_submission',(string)$id);
        $this->redirect('orders',sprintf('Personalization submission #%d marked %s. No provider or marketplace execution was authorized.',$id,$decision));
    }

    public function requestReplacementReview(): void
    {
        $this->guard('manage_digiforge_orders');check_admin_referer(self::REPLACEMENT_REQUEST_ACTION);
        $id=absint($_POST['order_id']??0);$shop=sanitize_key(wp_unslash($_POST['shop_key']??''));$key=sanitize_text_field(wp_unslash($_POST['request_key']??''));
        $this->requireConfirmation(self::REPLACEMENT_REQUEST_ACTION,$id,'orders');$result=(new \DigiForge\Orders\ReplacementReviewRepository())->request($id,$shop,$key);if(is_wp_error($result))$this->redirect('orders',$result->get_error_message(),true);
        $this->redirect('orders','Local replacement support case #'.(int)$result['case_id'].' requested. No provider production, order mutation or refund authorized.');
    }

    public function decideReplacementReview(): void
    {
        $this->guard('manage_digiforge_orders');check_admin_referer(self::REPLACEMENT_DECISION_ACTION);
        $id=absint($_POST['case_id']??0);$shop=sanitize_key(wp_unslash($_POST['shop_key']??''));$decision=strtoupper(sanitize_key(wp_unslash($_POST['decision']??'')));
        $this->requireConfirmation(self::REPLACEMENT_DECISION_ACTION,$id,'orders');$result=(new \DigiForge\Orders\ReplacementReviewRepository())->decide($id,$shop,$decision);if(is_wp_error($result))$this->redirect('orders',$result->get_error_message(),true);
        $this->redirect('orders','Local replacement support evidence reviewed. Any replacement production requires its separate existing authorization gate; no refund authorized.');
    }

    public function previewDraftQa():void
    {
        $this->guard('manage_digiforge_listings');check_admin_referer(self::DRAFT_QA_PREVIEW_ACTION);$body=wp_unslash($_POST);$id=$body['create_operation_id']??null;
        if(!(is_int($id)&&$id>0)&&!(is_string($id)&&preg_match('/^[1-9][0-9]*$/D',$id)===1))$this->redirect('listings','Exact confirmed draft target required.',true);
        $access=ReviewConfirmation::authorizeTarget(self::DRAFT_QA_REVIEW_ACTION,(int)$id);if(is_wp_error($access))$this->redirect('listings','Explicit shop assignment required for this draft target.',true);
        $context=(new \DigiForge\Listings\DraftQaReviewRepository())->inspect((int)$id);if(is_wp_error($context))$this->redirect('listings',$context->get_error_message(),true);
        $params=['checks'=>$body['checks']??null,'objective_evidence_hash'=>$context['objective_evidence_hash'],'intent_id'=>$context['intent_id'],'draft_package_id'=>$context['draft_package_id']];
        $confirmation=ReviewConfirmation::inspect(self::DRAFT_QA_REVIEW_ACTION,(int)$id,is_string($body['shop_key']??null)?$body['shop_key']:'',$params);if(is_wp_error($confirmation))$this->redirect('listings',$confirmation->get_error_message(),true);
        $this->draftQaPreview=['context'=>$context,'parameters'=>$params];$get=$_GET;try{$_GET=['df_view'=>'listings','df_shop'=>$context['shop_key']];echo '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><title>Confirm local draft QA review</title><link rel="stylesheet" href="'.esc_url(DIGIFORGE_URL.'assets/portal.css').'"></head><body>'.$this->render().'</body></html>';}finally{$_GET=$get;}
    }

    public function recordDraftQa():void
    {
        $this->guard('manage_digiforge_listings');check_admin_referer(self::DRAFT_QA_REVIEW_ACTION);$body=wp_unslash($_POST);$id=absint($body['create_operation_id']??0);$this->requireConfirmation(self::DRAFT_QA_REVIEW_ACTION,$id,'listings');
        $result=(new \DigiForge\Listings\DraftQaReviewRepository())->record((int)$body['intent_id'],(int)$body['draft_package_id'],$id,$body['checks'],'CONFIRM_DRAFT_QA_REVIEW',$body['objective_evidence_hash']);if(is_wp_error($result))$this->redirect('listings',$result->get_error_message(),true);
        $this->redirect('listings','Immutable local draft QA review recorded. Publish authorization and execution remain separate.');
    }

    public function saveNotificationPreferences(): void
    {
        $this->guard('manage_digiforge');check_admin_referer(self::NOTIFICATION_PREFERENCES_ACTION);
        $show=isset($_POST['show_success_notifications'])&&wp_unslash($_POST['show_success_notifications'])==='1';
        $result=(new NotificationPreferences())->save($show);if(is_wp_error($result))$this->redirect('settings',$result->get_error_message(),true);
        $this->redirect('settings','Portal notification preference saved. Errors and safety evidence always remain visible.');
    }

    public function ownershipReview(): void
    {
        $this->guard('manage_digiforge_pod');check_admin_referer(self::OWNERSHIP_REVIEW_ACTION);
        $id=isset($_POST['mapping_id'])?absint($_POST['mapping_id']):0;if($id<1)$this->redirect('orders','Valid ownership mapping required.',true);
        $this->requireConfirmation(self::OWNERSHIP_REVIEW_ACTION,$id,'orders');$result=(new BusinessScopeRepository())->approveMapping($id);if(is_wp_error($result))$this->redirect('orders',$result->get_error_message(),true);
        Logger::audit('portal_pod_ownership_human_reviewed',['mapping_id'=>$id,'state'=>(string)($result['state']??''),'external_execution_authorized'=>false,'external_execution_performed'=>false],'pod_business_mapping',(string)$id);
        $this->redirect('orders',sprintf('Ownership mapping #%d approved as human scope evidence. No provider or marketplace execution was authorized.',$id));
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
        $this->requireConfirmation(self::REVIEW_ACTION,$id,'approvals');
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

    public function master500Acceptance(): void
    {
        $this->guard('manage_digiforge_pod');
        check_admin_referer(self::MASTER500_ACCEPTANCE_ACTION);
        $versionId=isset($_POST['catalog_version_id'])?absint($_POST['catalog_version_id']):0;
        $decision=isset($_POST['decision'])?strtoupper(sanitize_key(wp_unslash($_POST['decision']))):'';
        $this->requireConfirmation(self::MASTER500_ACCEPTANCE_ACTION,$versionId,'pod_personalized');$result=(new MasterCatalogV2AcceptanceRepository())->record($versionId,$decision,get_current_user_id());
        if(is_wp_error($result))$this->redirect('pod_personalized',$result->get_error_message(),true);
        Logger::audit('portal_master500_migration_acceptance_recorded',[
            'catalog_version_id'=>$versionId,'decision'=>(string)$result['decision'],
            'acknowledgement_hash'=>(string)$result['acknowledgement_hash'],
            'production_authority'=>false,'promotion_authorized'=>false,
            'external_execution_authorized'=>false,'external_execution_performed'=>false,
        ],'catalog_version',(string)$versionId);
        $this->redirect('pod_personalized',sprintf('Master 500 v2 candidate #%d marked %s as immutable human migration evidence. No promotion, publication or provider execution was authorized.',$versionId,(string)$result['decision']));
    }

    public function podRenderReview(): void
    {
        $this->guard('manage_digiforge_pod');
        check_admin_referer(self::POD_RENDER_REVIEW_ACTION);
        $id=isset($_POST['render_evidence_id'])?absint($_POST['render_evidence_id']):0;
        if($id<1)$this->redirect('pod_personalized','Valid render evidence required.',true);
        $this->requireConfirmation(self::POD_RENDER_REVIEW_ACTION,$id,'pod_personalized');$result=(new RenderEvidenceRepository())->approve($id);
        if(is_wp_error($result))$this->redirect('pod_personalized',$result->get_error_message(),true);
        Logger::audit('portal_pod_render_human_reviewed',['render_evidence_id'=>$id,'review_status'=>(string)($result['review_status']??''),'external_execution_authorized'=>false,'external_execution_performed'=>false],'pod_render_evidence',(string)$id);
        $this->redirect('pod_personalized',sprintf('Render evidence #%d human visual review recorded. Production remains externally locked.',$id));
    }

    public function podPackageReview(): void
    {
        $this->guard('manage_digiforge_pod');
        check_admin_referer(self::POD_PACKAGE_REVIEW_ACTION);
        $id=isset($_POST['package_id'])?absint($_POST['package_id']):0;
        if($id<1)$this->redirect('pod_personalized','Valid authorization package required.',true);
        $this->requireConfirmation(self::POD_PACKAGE_REVIEW_ACTION,$id,'pod_personalized');$result=(new ProductionAuthorizationRepository())->approveForReview($id);
        if(is_wp_error($result))$this->redirect('pod_personalized',$result->get_error_message(),true);
        Logger::audit('portal_pod_authorization_package_human_reviewed',[
            'package_id'=>$id,
            'state'=>(string)($result['state']??''),
            'external_execution_authorized'=>false,
            'external_execution_performed'=>false,
        ],'pod_authorization_package',(string)$id);
        $this->redirect('pod_personalized',sprintf('Authorization package #%d human review recorded. Production remains externally locked.',$id));
    }

    public function recoveryPlan(): void
    {
        $this->guard('manage_digiforge');
        check_admin_referer(self::RECOVERY_PLAN_ACTION);
        $this->requireAllShopAccess('manage_digiforge', 'attention');
        $target = isset($_POST['target_site_url']) ? esc_url_raw(wp_unslash($_POST['target_site_url'])) : '';
        $operationKey = isset($_POST['operation_key']) ? sanitize_text_field(wp_unslash($_POST['operation_key'])) : '';
        $backupIdentityOperationKey = isset($_POST['backup_identity_operation_key']) ? sanitize_text_field(wp_unslash($_POST['backup_identity_operation_key'])) : '';
        $result = RecoveryOrchestrator::plan([
            'target_environment' => 'staging',
            'target_site_url' => $target,
            'operation_key' => $operationKey,
            'backup_identity_operation_key' => $backupIdentityOperationKey,
        ]);
        if (is_wp_error($result)) {
            $this->redirect('attention', $result->get_error_message(), true);
        }
        Logger::audit('portal_recovery_orchestration_planned', [
            'operation_key' => (string) ($result['operation_key'] ?? ''),
            'target_environment' => 'staging',
            'external_actions_performed' => false,
            'external_execution_authorized' => false,
        ], 'system', 'recovery_orchestration');
        $message = ($result['state'] ?? '') === 'PROVIDER_REQUIRED'
            ? 'Recovery plan recorded safely. A governed hosting-provider adapter is still required before execution.'
            : 'Recovery plan recorded. Provider capability is available for a separately governed execution step.';
        $this->redirect('attention', $message);
    }

    public function recoveryAccept(): void
    {
        $this->guard('manage_digiforge');
        check_admin_referer(self::RECOVERY_ACCEPT_ACTION);
        $this->requireAllShopAccess('manage_digiforge', 'attention');
        $operationKey = isset($_POST['operation_key']) ? sanitize_text_field(wp_unslash($_POST['operation_key'])) : '';
        $evidenceHash = isset($_POST['verification_evidence_hash']) ? strtolower(sanitize_text_field(wp_unslash($_POST['verification_evidence_hash']))) : '';
        $user = wp_get_current_user();
        $performedBy = (string) ($user->user_email ?: $user->user_login);
        $result = RecoveryDrillAcceptance::accept($operationKey, $evidenceHash, $performedBy);
        if (is_wp_error($result)) {
            $this->redirect('attention', $result->get_error_message(), true);
        }
        $this->redirect('attention', 'Verified staging recovery drill accepted and recorded. No restore, retry, commerce action or external execution was performed.');
    }

    public function listingReview(): void
    {
        $this->guard('manage_digiforge_listings');
        check_admin_referer(self::LISTING_REVIEW_ACTION);
        $reviewId = isset($_POST['review_id']) ? absint($_POST['review_id']) : 0;
        $decision = isset($_POST['decision']) ? strtoupper(sanitize_key(wp_unslash($_POST['decision']))) : '';
        if (!in_array($decision, ['APPROVED','REJECTED'], true)) {
            $this->redirect('listings', 'Choose APPROVED or REJECTED for the pending Gate 3 review.', true);
        }
        $this->requireConfirmation(self::LISTING_REVIEW_ACTION,$reviewId,'listings');
        $result = (new \DigiForge\Listings\Repository())->decideReadinessReview($reviewId, $decision);
        if (is_wp_error($result)) {
            $this->redirect('listings', $result->get_error_message(), true);
        }
        Logger::audit('portal_listing_gate3_review_completed', ['review_id'=>$reviewId,'decision'=>$decision], 'listing_readiness_review', (string)$reviewId);
        $this->redirect('listings', sprintf('Gate 3 review #%d marked %s. No Etsy publish was performed.', $reviewId, $decision));
    }

    public function saveAiPolicy(): void
    {
        $this->guard('manage_digiforge_ai');
        check_admin_referer(self::SAVE_AI_POLICY_ACTION);
        $shop = isset($_POST['shop_key']) ? sanitize_key(wp_unslash($_POST['shop_key'])) : '';
        $currency = isset($_POST['currency']) ? strtoupper(sanitize_text_field(wp_unslash($_POST['currency']))) : 'INR';
        if ($shop === '' || $shop === ShopOperationsReadModel::ALL) {
            $this->redirect('ai_budget', 'Choose one concrete shop before saving an AI policy.', true);
        }
        $access=\DigiForge\Security\ShopAccess::authorize($shop,'manage_digiforge_ai');if(is_wp_error($access))$this->redirect('ai_budget','Explicit shop assignment required.',true);
        $number = static function (string $key): float {
            $raw = isset($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : '0';
            return is_numeric($raw) ? max(0, (float) $raw) : 0.0;
        };
        $policy = ['shop_key' => $shop, 'currency' => $currency, 'budgets' => [
            'run' => $number('budget_run'),
            'day' => $number('budget_day'),
            'month' => $number('budget_month'),
        ], 'stages' => []];
        global $wpdb;$wpdb->last_error='';$existingRow=$wpdb->get_row($wpdb->prepare('SELECT policy,policy_hash FROM '.Tables::shop_ai_policies().' WHERE shop_key=%s AND environment=%s AND state=%s',$shop,'production','ACTIVE'),ARRAY_A);
        if(!empty($wpdb->last_error))$this->redirect('ai_budget','Existing shop policy evidence unavailable; editing blocked.',true);
        $existingPolicy=[];if(is_array($existingRow)){$rawPolicy=(string)($existingRow['policy']??'');$existingPolicy=json_decode($rawPolicy,true);if(!is_array($existingPolicy)||!hash_equals((string)($existingRow['policy_hash']??''),hash('sha256',$rawPolicy)))$this->redirect('ai_budget','Existing shop policy fingerprint is invalid; editing blocked.',true);}
        foreach (ShopAiPlan::STAGES as $stage) {
            $policy['stages'][$stage] = array_replace((array)($existingPolicy['stages'][$stage]??[]), [
                'limit' => (int) floor($number('stage_' . $stage . '_limit')),
                'estimated_unit_cost' => $number('stage_' . $stage . '_unit_cost'),
            ]);
            $budgetKey='stage_'.$stage.'_monthly_budget';
            if(array_key_exists($budgetKey,$_POST)){
                $raw=wp_unslash($_POST[$budgetKey]);
                if(!is_string($raw))$this->redirect('ai_budget','Stage monthly budget must be a decimal amount or blank.',true);
                $raw=trim($raw);
                if($raw!==''&&preg_match('/^(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,6})?$/D',$raw)!==1)$this->redirect('ai_budget','Stage monthly budget must be an exact non-negative decimal with at most six decimal places.',true);
                $policy['stages'][$stage]['monthly_budget']=$raw===''?null:$raw;
            }
            $bindingKey='stage_'.$stage.'_task_binding';
            if(array_key_exists($bindingKey,$_POST)){
                $raw=wp_unslash($_POST[$bindingKey]);if(!is_string($raw))$this->redirect('ai_budget','Stage task binding must be a JSON object or blank.',true);
                $raw=trim($raw);$binding=$raw===''?null:json_decode($raw,true);
                if($raw!==''&&(!is_array($binding)||array_is_list($binding)))$this->redirect('ai_budget','Stage task binding must be a reviewed JSON object.',true);
                $policy['stages'][$stage]['task_binding']=$binding;
            }
        }
        try{$templateCap=\DigiForge\POD\TemplateCyclePolicy::fromForm(wp_unslash($_POST));}catch(\InvalidArgumentException $e){$this->redirect('ai_budget',$e->getMessage(),true);}
        if($templateCap!==null)$policy['template_cap']=$templateCap;
        $result = (new ShopAiGovernanceRepository())->savePolicy($policy, 'production');
        if (is_wp_error($result)) {
            $this->redirect('ai_budget', $result->get_error_message(), true);
        }
        Logger::audit('portal_shop_ai_policy_saved', [
            'shop_key' => $shop,
            'environment' => 'production',
            'policy_hash' => (string) ($result['policy_hash'] ?? ''),
            'external_execution_authorized' => false,
            'external_execution_performed' => false,
        ], 'shop_ai_policy', (string) ($result['id'] ?? ''));
        $this->redirect('ai_budget', 'Shop AI policy saved and read-back confirmed. No AI run, automation activation or external execution was authorized.');
    }

    public function saveAiSecret(): void
    {
        $this->guard('manage_digiforge_connections');
        check_admin_referer(self::SAVE_AI_SECRET_ACTION);
        $this->requireAllShopAccess('manage_digiforge_connections', 'integrations');
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
        $this->requireConfirmation(self::DEVELOP_ACTION,$id,'approvals');
        $result = (new ExecutionEngine())->develop($id, ['shop' => $shop], $key);
        if (is_wp_error($result)) {
            $this->redirect('approvals', $result->get_error_message(), true);
        }
        $productId = (int) ($result['product']['id'] ?? 0);
        $this->redirect('products', sprintf('Candidate #%d developed into product #%d.', $id, $productId));
    }

    private function requireAllShopAccess(string $cap,string $view):void
    {
        $allowed=\DigiForge\Security\ShopAccess::allowedShops($cap);
        if(is_wp_error($allowed)||count(array_intersect(['digital','personalized_pod','standard_pod','jewelry'],is_array($allowed)?$allowed:[]))!==4)$this->redirect($view,'Explicit assignments for all four shops are required for this global operation.',true);
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
        if ($view === 'ai_budget') { $this->aiBudgetOperations(); (new AiEvidenceWorkflow())->render(ShopOperationsReadModel::normalize(isset($_GET['df_shop'])?sanitize_key(wp_unslash($_GET['df_shop'])):'all')); return; }
        if ($view === 'audit') { $this->auditReconciliationOperations(); return; }
        if ($view === 'orders') { $this->orderOperations(); return; }
        if ($view === 'listings') { $this->listingOperations(); return; }
        if ($view === 'digital') { $this->digitalFactoryOperations(); return; }
        if ($view === 'finance') { $this->financeOperations(); return; }
        if ($view === 'analytics') { $this->analyticsOperations(); return; }
        if ($view === 'automation') { $this->automationOperations(); return; }
        if ($view === 'businesses') { $this->businessOperations(); return; }
        if ($view === 'settings') { $this->settingsOperations(); return; }
        if ($view === 'pod_future_nonpersonalized') { $this->futureNonPersonalizedPod(); return; }
        if ($view === 'pod_personalized') { $this->personalizedPodSummary(); (new \DigiForge\POD\DeterministicRenderWorkflow())->render(ShopOperationsReadModel::normalize(isset($_GET['df_shop'])?sanitize_key(wp_unslash($_GET['df_shop'])):'all')); }

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
        $selected=ShopOperationsReadModel::normalize(isset($_GET['df_shop'])?sanitize_key(wp_unslash($_GET['df_shop'])):ShopOperationsReadModel::ALL);$shop=$selected===ShopOperationsReadModel::ALL?null:$selected;
        $fulfillmentPlanFocus=isset($_GET['df_fulfillment_plan'])?absint(wp_unslash($_GET['df_fulfillment_plan'])):0;$orderFocus=isset($_GET['df_order'])?absint(wp_unslash($_GET['df_order'])):0;
        $auditIdentityState=null;$auditIdentity=(new AuditCorrelationReadModel())->recent('', '',50,$auditIdentityState);
        if($shop!==null){global $wpdb;$wpdb->last_error='';$auditIdentity=$wpdb->get_results('SELECT id,event_type,actor_id,object_type,object_id,created_at FROM '.Tables::audit_log().' WHERE '.$this->evidenceScopeFilter(Tables::audit_log()).' ORDER BY id DESC LIMIT 50',ARRAY_A);$auditIdentityState=is_array($auditIdentity)&&empty($wpdb->last_error)?'AVAILABLE':'UNAVAILABLE';if($auditIdentityState!=='AVAILABLE')$auditIdentity=[];}
        $etsyQueryState=null;$etsy=(new EtsyReconciliationOperatorReadModel())->recent(50,$etsyQueryState,$shop);$printifyProjection=(new \DigiForge\POD\PrintifyUnknownOperatorReadModel())->summary($shop);$printifyAvailable=($printifyProjection['query_state']??'UNAVAILABLE')==='AVAILABLE';$printify=(array)($printifyProjection['items']??[]);$exceptions=(new OperationalExceptionReadModel())->snapshot(50);
        $etsyRecentCount=count($etsy);
        $printifyFocus=isset($_GET['df_printify_unknown'])?absint(wp_unslash($_GET['df_printify_unknown'])):0;
        $focusedPrintify=$printifyFocus>0?array_values(array_filter($printify,static fn(array $r):bool=>(int)($r['id']??0)===$printifyFocus)):[];
        if($focusedPrintify!==[]){$printify=array_merge($focusedPrintify,array_values(array_filter($printify,static fn(array $r):bool=>(int)($r['id']??0)!==$printifyFocus)));}
        $etsyFocus=isset($_GET['df_etsy_operation'])?absint(wp_unslash($_GET['df_etsy_operation'])):0;
        $etsyFocusQueryState=null;$focusedEtsy=$etsyFocus>0?(new EtsyReconciliationOperatorReadModel())->byId($etsyFocus,$etsyFocusQueryState,$shop):null;
        if($focusedEtsy!==null&&!in_array($etsyFocus,array_map('intval',array_column($etsy,'id')),true))array_unshift($etsy,$focusedEtsy);
        echo '<section class="df-panel"'.($fulfillmentPlanFocus>0?' id="df-fulfillment-focus"':'').'><div class="df-panel-head"><div><h2>Audit / Reconciliation</h2><p>Cross-channel exception evidence. UNKNOWN is not success and must reconcile before any retry.</p></div><span class="df-status">READ ONLY</span></div><div class="df-signal-grid"><div><span>Recent Etsy reconciliation (up to 50)</span><b>'.esc_html($etsyQueryState==='AVAILABLE'?(string)$etsyRecentCount:'UNAVAILABLE').'</b></div><div><span>Printify unknown evidence</span><b>'.esc_html($printifyAvailable?(string)count($printify):'UNAVAILABLE').'</b></div><div><span>Retry authority</span><b>NO</b></div><div><span>External execution authority</span><b>NO</b></div><div><span>Fulfillment exceptions</span><b>'.esc_html(($exceptions['query_state']['fulfillment']??'UNAVAILABLE')==='AVAILABLE'?(string)count((array)$exceptions['fulfillment']):'UNAVAILABLE').'</b></div><div><span>Finance/GST exceptions</span><b>'.esc_html(($exceptions['query_state']['finance_ledger']??'UNAVAILABLE')==='AVAILABLE'&&($exceptions['query_state']['tax']??'UNAVAILABLE')==='AVAILABLE'?(string)(count((array)$exceptions['finance_ledger'])+count((array)$exceptions['tax'])):'UNAVAILABLE').'</b></div></div>';
        if($fulfillmentPlanFocus>0)echo '<div class="df-notice">Focused fulfillment evidence: plan #'.esc_html((string)$fulfillmentPlanFocus).($orderFocus>0?' · order #'.esc_html((string)$orderFocus):'').'. Review reconciliation evidence before any retry; this focus grants no execution authority.</div>';
        if(in_array('UNAVAILABLE',(array)($exceptions['query_state']??[]),true))echo '<div class="df-notice df-notice-error">Fulfillment or finance exception evidence unavailable. Database read failed; bounded counts cannot be treated as empty.</div>';
        if($etsyQueryState!=='AVAILABLE')echo '<div class="df-notice df-notice-error">Etsy reconciliation evidence unavailable. Database read failed; no empty queue is inferred and retry remains prohibited.</div>';
        if(!$printifyAvailable)echo '<div class="df-notice df-notice-error">Printify UNKNOWN evidence unavailable. Reconciliation status cannot be verified; retry remains prohibited.</div>';
        if($etsyFocus>0&&$focusedEtsy===null)echo $etsyFocusQueryState==='UNAVAILABLE'?'<div class="df-notice df-notice-error">Focused Etsy reconciliation lookup failed. Its state is unverified; reconcile before any retry.</div>':'<div class="df-notice df-notice-error">Requested Etsy reconciliation evidence is unavailable or no longer requires reconciliation. No retry is inferred.</div>';
        if($printifyFocus>0&&$focusedPrintify===[])echo '<div class="df-notice df-notice-error">Requested provider UNKNOWN evidence is unavailable. Reconcile before any retry; this view grants no execution authority.</div>';
        if($etsy!==[]){echo '<div class="df-table-wrap" role="region" tabindex="0" aria-label="Scrollable evidence table"><table class="df-table"><thead><tr><th>Etsy op</th><th>Shop</th><th>Type</th><th>State</th><th>Resource</th><th>Action</th><th>Workflow</th></tr></thead><tbody>';foreach($etsy as $row){echo '<tr'.((int)$row['id']===$etsyFocus?' id="df-etsy-operation-'.esc_attr((string)$etsyFocus).'"':'').'><td>#'.esc_html((string)$row['id']).'</td><td>'.esc_html((string)$row['shop_reference']).'</td><td>'.esc_html((string)$row['operation_type']).'</td><td>'.esc_html((string)$row['state']).'</td><td>'.esc_html((string)$row['resource_reference']).'</td><td>RECONCILE BEFORE ANY RETRY</td><td><a class="df-button df-button-compact" href="'.esc_url($this->url('listings')).'">Open Listings & Etsy</a></td></tr>';}echo '</tbody></table></div>';}elseif($etsyQueryState==='AVAILABLE'){echo '<div class="df-empty">No Etsy UNKNOWN/reconciliation-required operations.</div>';}
        if($printify!==[]){echo '<div class="df-table-wrap" role="region" tabindex="0" aria-label="Scrollable evidence table"><table class="df-table"><thead><tr><th>Authorization</th><th>Resolution</th><th>Operator action</th><th>Retry</th><th>Workflow</th></tr></thead><tbody>';foreach(array_slice($printify,0,50) as $row){$id=(int)($row['id']??0);$integrityUrl=$this->integrityEvidenceUrl((string)($row['authorization_hash']??''));$identity='<code>'.esc_html(substr((string)($row['authorization_hash']??''),0,12)).'…</code>';echo '<tr'.($id>0&&$id===$printifyFocus?' id="df-printify-unknown-'.esc_attr((string)$id).'"':'').'><td>'.($id>0?'<a href="'.esc_url(add_query_arg(['df_printify_unknown'=>$id],$this->url('audit')).'#df-printify-unknown-'.$id).'">'.$identity.'</a>':$identity.' <span class="df-muted">NO RECORD ID</span>').'</td><td>'.esc_html((string)($row['resolution_state']??'UNKNOWN')).'</td><td>'.esc_html((string)($row['operator_action']??'RECONCILE_BEFORE_ANY_RETRY')).'</td><td>NO</td><td><a class="df-button df-button-compact" href="'.esc_url($this->url('pod_personalized')).'">Open POD workflow</a> '.($integrityUrl!==''?'<a class="df-button df-button-compact" href="'.esc_url($integrityUrl).'">Integrity evidence</a>':'').'</td></tr>';}echo '</tbody></table></div>';}elseif($printifyAvailable){echo '<div class="df-empty">No Printify UNKNOWN evidence.</div>';}
        echo '<p class="df-muted">These links only navigate to governed internal workflows. This view cannot retry, publish, produce, fulfill, refund, change tax state, or move money.</p><p class="df-muted">Bounded non-secret audit identities available: '.esc_html($auditIdentityState==='AVAILABLE'?(string)count($auditIdentity):'UNAVAILABLE').'. Audit context payloads are excluded; visibility never grants retry or external execution authority.</p></section>';
        if($auditIdentityState!=='AVAILABLE')echo '<div class="df-notice df-notice-error">Audit identity evidence unavailable. Database read failed; an empty audit history is not inferred.</div>';
        $this->panelTable('Audit Log',Tables::audit_log());
    }

    private function digitalFactoryOperations():void
    {
        $repo=new \DigiForge\DigitalFactory\Repository();$selected=ShopOperationsReadModel::normalize(isset($_GET['df_shop'])?sanitize_key(wp_unslash($_GET['df_shop'])):ShopOperationsReadModel::ALL);$products=$repo->all('digital_product',1,50,$selected===ShopOperationsReadModel::ALL?null:$selected);$queryOk=!empty($products['query_ok']);$items=(array)($products['items']??[]);
        $counts=['total'=>0,'draft'=>0,'review'=>0,'approved'=>0];if($queryOk){foreach($items as $row){$counts['total']++;$state=strtoupper((string)($row['state']??''));if($state==='DRAFT')$counts['draft']++;elseif(str_contains($state,'REVIEW'))$counts['review']++;elseif(in_array($state,['APPROVED','RELEASE_READY','ACTIVE'],true))$counts['approved']++;}}
        echo '<section class="df-panel"><div class="df-panel-head"><div><h2>Digital Product Factory</h2><p>Portal-first lifecycle visibility for customer files, packages, previews, templates, licenses and download QA. Product Approval remains a separate human Gate 2 decision.</p></div><span class="df-status">INTERNAL WORKFLOW</span></div>';
        if(!$queryOk){echo '<div class="df-notice df-notice-error">Digital Factory evidence unavailable. Database reads failed; zero products or readiness is not inferred.</div>';}else{echo '<div class="df-signal-grid"><div><span>Recent digital products</span><b>'.esc_html((string)$counts['total']).'</b></div><div><span>Draft</span><b>'.esc_html((string)$counts['draft']).'</b></div><div><span>Review</span><b>'.esc_html((string)$counts['review']).'</b></div><div><span>Approved / release-ready</span><b>'.esc_html((string)$counts['approved']).'</b></div><div><span>External publish authority</span><b>NO</b></div></div>';if($items===[]){echo '<div class="df-empty">No digital products in the bounded view.</div>';}else{echo '<div class="df-table-wrap" role="region" tabindex="0" aria-label="Scrollable evidence table"><table class="df-table"><thead><tr><th>Digital product</th><th>Product version</th><th>Category</th><th>State</th><th>Readiness</th><th>Next governed step</th></tr></thead><tbody>';foreach($items as $row){$ready=is_array($row['readiness']??null)?$row['readiness']:json_decode((string)($row['readiness']??''),true);$blocked=is_array($ready)?count(array_filter($ready,static fn($v):bool=>$v!==true)):null;echo '<tr><td>#'.esc_html((string)$row['id']).' '.esc_html((string)$row['name']).'</td><td>#'.esc_html((string)$row['product_version_id']).'</td><td>'.esc_html((string)$row['category']).'</td><td>'.esc_html((string)$row['state']).'</td><td>'.esc_html($blocked===null?'UNVERIFIED':($blocked===0?'PASS':$blocked.' BLOCKED')).'</td><td><a class="df-button df-button-compact" href="'.esc_url($this->url('approvals')).'">Open Product Approval</a></td></tr>';}echo '</tbody></table></div>';}}
        echo '<p class="df-muted">This view cannot publish to Etsy, mutate a marketplace listing, fulfill an order, or grant external execution authority.</p></section>';
        foreach($this->tables('digital') as $label=>$table)$this->panelTable($label,$table);
    }

    private function listingOperations():void
    {
        $selectedShop=ShopOperationsReadModel::normalize(isset($_GET['df_shop'])?sanitize_key(wp_unslash($_GET['df_shop'])):ShopOperationsReadModel::ALL);$shop=$selectedShop===ShopOperationsReadModel::ALL?null:$selectedShop;
        $this->draftQaReviewForms($shop);
        $webhook=EtsyWebhookReadiness::inspect();
        $attachmentEvidenceState=null;$attachments=(new EtsyDigitalAttachmentReadModel())->recent(50,$attachmentEvidenceState,$shop);
        global $wpdb;
        $scope=$shop===null?'':$wpdb->prepare(' AND BINARY l.shop_reference=BINARY %s',$shop);
        $wpdb->last_error='';
        $pending=$wpdb->get_results("SELECT r.id AS review_id,r.listing_id,r.readiness,r.readiness_hash,r.created_at,l.product_version_id,l.title,l.state FROM ".Tables::listing_readiness_reviews()." r INNER JOIN ".Tables::listings()." l ON l.id=r.listing_id WHERE r.decision='PENDING'".$scope." ORDER BY r.id ASC LIMIT 50",ARRAY_A);
        $pendingAvailable=is_array($pending)&&empty($wpdb->last_error);
        echo '<section class="df-panel" id="df-listing-gate3"><div class="df-panel-head"><div><h2>Listing / publish decisions (Gate 3)</h2><p>Human decision over persisted listing-readiness evidence. A decision changes only the internal listing/review lifecycle; it does not publish to Etsy.</p></div><span class="df-status">HUMAN CONTROLLED</span></div>';
        if(!$pendingAvailable){echo '<div class="df-notice df-notice-error">Gate 3 review evidence is unavailable. No decision is permitted.</div>';}elseif($pending===[]){echo '<div class="df-empty">No listing Gate 3 reviews currently require a decision.</div>';}else{foreach($pending as $row){$raw=(string)$row['readiness'];$e=json_decode($raw,true);$checks=is_array($e)?(array)($e['checks']??[]):[];$snapshot=$e;if(is_array($snapshot))unset($snapshot['hash']);$canonical=is_array($snapshot)?\DigiForge\Listings\Validator::canonicalJson($snapshot):false;$hashValid=is_string($canonical)&&hash_equals((string)$row['readiness_hash'],hash('sha256',$canonical));$prereqs=!empty($checks['seo_present'])&&!empty($checks['approved_media_bound'])&&!empty($checks['pod_binding_valid']);$current=(new \DigiForge\Listings\Repository())->readiness((int)$row['listing_id']);$currentChecks=is_array($current)?(array)($current['checks']??[]):[];$currentEvidence=is_array($current)?(string)($current['hash']??''):'';$evidenceValid=$hashValid&&$prereqs&&!empty($currentChecks['product_approved'])&&!empty($currentChecks['shop_scope_present'])&&$currentEvidence!==''&&hash_equals((string)$row['readiness_hash'],$currentEvidence)&&(string)$row['state']==='REVIEW_REQUIRED';echo '<article class="df-candidate"><div class="df-candidate-head"><div><span class="df-kicker">Review #'.esc_html((string)$row['review_id']).' · Listing #'.esc_html((string)$row['listing_id']).' · Product Version #'.esc_html((string)$row['product_version_id']).'</span><h3>'.esc_html((string)$row['title']).'</h3><span class="df-status">PENDING</span></div></div><div class="df-signal-grid"><div><span>Listing state</span><b>'.esc_html((string)$row['state']).'</b></div><div><span>SEO</span><b>'.(!empty($checks['seo_present'])?'PASS':'BLOCKED').'</b></div><div><span>Approved media</span><b>'.(!empty($checks['approved_media_bound'])?'PASS':'BLOCKED').'</b></div><div><span>POD binding</span><b>'.(!empty($checks['pod_binding_valid'])?'PASS':'BLOCKED').'</b></div><div><span>Evidence hash</span><b><code>'.esc_html(substr((string)$row['readiness_hash'],0,16)).'…</code></b></div></div><form class="df-review-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="'.esc_attr(self::LISTING_REVIEW_ACTION).'"><input type="hidden" name="review_id" value="'.esc_attr((string)$row['review_id']).'">';wp_nonce_field(self::LISTING_REVIEW_ACTION);$this->confirmationFields(self::LISTING_REVIEW_ACTION,(int)$row['review_id']);if($evidenceValid){echo '<div class="df-actions"><button class="df-button df-button-primary" name="decision" value="APPROVED">Approve Listing</button><button class="df-button df-button-danger" name="decision" value="REJECTED">Reject / Revise</button></div>';}else{echo '<div class="df-notice df-notice-error">Persisted Gate 3 evidence is invalid, inconsistent, or blocked. Decision controls are withheld.</div>';}echo '</form><p class="df-muted">Approve/Reject records the human Gate 3 decision only. No Etsy publish, upload, POD submission, order execution, money movement or tax action occurs here.</p></article>';}}echo '</section>';
        echo '<section class="df-panel"><div class="df-panel-head"><div><h2>Etsy webhook configuration</h2><p>Server-side configuration evidence only. No subscription or provider connectivity check is performed here.</p></div><span class="df-status">READ ONLY</span></div><div class="df-signal-grid"><div><span>Configuration</span><b>'.esc_html((string)$webhook['state']).'</b></div><div><span>Signing secret configured</span><b>'.(!empty($webhook['signing_secret_configured'])?'YES':'NO').'</b></div><div><span>Order intake</span><b>LOCAL ONLY</b></div><div><span>Fulfillment authority</span><b>NO</b></div></div><p class="df-muted">Configure the Etsy-issued signing secret on the server. Do not enter secrets in the portal. This status does not verify Etsy subscription delivery or authorize order automation.</p></section>';
        echo '<section class="df-panel"><div class="df-panel-head"><div><h2>Confirmed digital file identity evidence</h2><p>Local operation ledger evidence for accepted Etsy uploads. This does not verify the current provider listing or repeat the upload.</p></div><span class="df-status">READ ONLY</span></div>';
        if($attachmentEvidenceState==='UNAVAILABLE'){echo '<div class="df-empty">Digital file upload evidence is unavailable. No absence of confirmed uploads is asserted.</div>';}elseif($attachments===[]){echo '<div class="df-empty">No confirmed digital file upload evidence in the recent window.</div>';}else{echo '<div class="df-table-wrap" role="region" tabindex="0" aria-label="Scrollable evidence table"><table class="df-table"><thead><tr><th>Operation</th><th>Shop</th><th>Etsy listing</th><th>Listing file</th><th>Identity evidence</th><th>Updated</th><th>Authority</th></tr></thead><tbody>';foreach($attachments as $file){echo '<tr><td>#'.esc_html((string)$file['operation_id']).'</td><td>'.esc_html((string)$file['shop_reference']).'</td><td>'.esc_html((string)$file['listing_id']).'</td><td>'.esc_html((string)$file['listing_file_id']).'</td><td>'.esc_html((string)$file['evidence_state']).'</td><td>'.esc_html((string)$file['updated_at']).'</td><td>NO UPLOAD / NO PUBLISH</td></tr>';}echo '</tbody></table></div>';}echo '<p class="df-muted">Up to 50 recent confirmed operations are shown. Missing or conflicting persisted identity is REVIEW_REQUIRED. Recorded success does not prove live Etsy file availability and never grants a retry, upload or publish action.</p></section>';
        $recon=(new WebhookReconciliationReadModel())->snapshot('etsy');$counts=(array)$recon['counts'];$reconUnavailable=(string)($recon['evidence_state']??'AVAILABLE')==='UNAVAILABLE';
        echo '<section class="df-panel"><div class="df-panel-head"><div><h2>Etsy webhook reconciliation</h2><p>Verified inbound evidence only. This view never retries Etsy actions.</p></div><span class="df-status">'.($reconUnavailable?'UNAVAILABLE':'READ ONLY').'</span></div>'.($reconUnavailable?'<div class="df-empty">Webhook reconciliation evidence is unavailable because the current database read failed. Zero counts are not asserted.</div>':'').'<div class="df-signal-grid"><div><span>Verified</span><b>'.($reconUnavailable?'—':esc_html((string)$counts['verified'])).'</b></div><div><span>Processed</span><b>'.($reconUnavailable?'—':esc_html((string)$counts['processed'])).'</b></div><div><span>Failed</span><b>'.($reconUnavailable?'—':esc_html((string)$counts['failed'])).'</b></div><div><span>Unresolved</span><b>'.($reconUnavailable?'—':esc_html((string)$counts['unresolved'])).'</b></div><div><span>Evidence discrepancies</span><b>'.($reconUnavailable?'—':esc_html((string)($counts['discrepancies']??0))).'</b></div></div><p class="df-muted">Reconciliation never retries webhook processing automatically.</p></section>';
        foreach($this->tables('listings') as $label=>$table)$this->panelTable($label,$table);
    }

    private function orderOperations():void
    {
        $selectedShop=ShopOperationsReadModel::normalize(isset($_GET['df_shop'])?sanitize_key(wp_unslash($_GET['df_shop'])):ShopOperationsReadModel::ALL);$shop=$selectedShop===ShopOperationsReadModel::ALL?null:$selectedShop;
        $ordersQueryState=null;$rows=(new OrderOperationsReadModel())->recent(50,$ordersQueryState,$shop);$orderReconciliation=new OrderReconciliationReadModel();$exceptions=(new OperationalExceptionReadModel())->snapshot(50,$shop);$fulfillmentExceptions=(array)$exceptions['fulfillment'];
        $humanGates=(new HumanGateOperationsReadModel())->snapshot(50,$shop);
        echo '<section class="df-panel"><div class="df-panel-head"><div><h2>Personalization & ownership review queue</h2><p>Explicit human gates before downstream POD readiness. This queue is read only and grants no marketplace or provider authority.</p></div><span class="df-status">HUMAN GATES</span></div>';
        if(($humanGates['query_state']['aggregate']??'UNAVAILABLE')!=='AVAILABLE')echo '<div class="df-notice df-notice-error">One or more human-gate evidence sources are unavailable. No empty queue or approval is inferred.</div>';
        if(($humanGates['query_state']['personalization']??'UNAVAILABLE')==='AVAILABLE'){echo '<h3>Personalization reviews</h3>';if($humanGates['personalization']===[])echo '<div class="df-empty">No pending personalization reviews in the bounded window.</div>';else{echo '<div class="df-table-wrap" role="region" tabindex="0" aria-label="Scrollable evidence table"><table class="df-table"><thead><tr><th>Submission</th><th>Order / line</th><th>Schema</th><th>Payload evidence</th><th>Status</th><th>Authority</th></tr></thead><tbody>';foreach($humanGates['personalization'] as $g)echo '<tr><td>#'.esc_html((string)$g['id']).'</td><td>#'.esc_html((string)($g['order_id']??0)).' / #'.esc_html((string)$g['order_line_item_id']).'</td><td>#'.esc_html((string)$g['personalization_schema_id']).'</td><td><code>'.esc_html(substr((string)$g['payload_hash'],0,12)).'…</code></td><td>'.esc_html((string)$g['review_status']).'</td><td><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="'.esc_attr(self::PERSONALIZATION_REVIEW_ACTION).'"><input type="hidden" name="submission_id" value="'.esc_attr((string)$g['id']).'">';wp_nonce_field(self::PERSONALIZATION_REVIEW_ACTION);$this->confirmationFields(self::PERSONALIZATION_REVIEW_ACTION,(int)$g['id']);echo '<button class="df-button df-button-compact" name="decision" value="APPROVED" type="submit">Approve evidence</button> <button class="df-button df-button-compact" name="decision" value="REJECTED" type="submit">Reject</button></form><small>External authority: NO</small></td></tr>';echo '</tbody></table></div>';}}
        if(($humanGates['query_state']['ownership']??'UNAVAILABLE')==='AVAILABLE'){echo '<h3>POD ownership mappings</h3>';if($humanGates['ownership']===[])echo '<div class="df-empty">No DRAFT ownership mappings in the bounded window.</div>';else{echo '<div class="df-table-wrap" role="region" tabindex="0" aria-label="Scrollable evidence table"><table class="df-table"><thead><tr><th>Mapping</th><th>Business / store / program</th><th>Product</th><th>Provider mapping</th><th>State</th><th>Authority</th></tr></thead><tbody>';foreach($humanGates['ownership'] as $g)echo '<tr><td>#'.esc_html((string)$g['id']).'</td><td>#'.esc_html((string)$g['business_id']).' / #'.esc_html((string)$g['store_id']).' / #'.esc_html((string)$g['product_program_id']).'</td><td>#'.esc_html((string)$g['product_version_id']).'</td><td>#'.esc_html((string)$g['provider_mapping_id']).'</td><td>'.esc_html((string)$g['state']).'</td><td><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="'.esc_attr(self::OWNERSHIP_REVIEW_ACTION).'"><input type="hidden" name="mapping_id" value="'.esc_attr((string)$g['id']).'">';wp_nonce_field(self::OWNERSHIP_REVIEW_ACTION);$this->confirmationFields(self::OWNERSHIP_REVIEW_ACTION,(int)$g['id']);echo '<button class="df-button df-button-compact" type="submit">Approve ownership</button></form><small>External authority: NO</small></td></tr>';echo '</tbody></table></div>';}}
        echo '<p class="df-muted">Human review evidence is separate from Etsy publish, POD production, provider retry and external execution authorization.</p></section>';
        echo '<section class="df-panel"><div class="df-panel-head"><div><h2>Order readiness</h2><p>Readiness evidence is separate from external fulfillment authorization.</p></div><span class="df-status">READ ONLY</span></div>';
        if($ordersQueryState!=='AVAILABLE'){echo '<div class="df-notice df-notice-error">Recent order evidence unavailable. Database read failed; no empty order window is inferred.</div>';}elseif($rows===[]){echo '<div class="df-empty">No orders found in the recent window.</div>';}else{
        echo '<div class="df-table-wrap" role="region" tabindex="0" aria-label="Scrollable evidence table"><table class="df-table"><thead><tr><th>Order</th><th>Shop</th><th>State</th><th>Mode</th><th>Ready</th><th>External authorization</th></tr></thead><tbody>';
        foreach($rows as $row){$r=(array)$row['readiness'];$rec=$orderReconciliation->forOrder((int)$row['id']);$discProjection=$orderReconciliation->discrepancyProjection((int)$row['id'],25);$recUnavailable=($rec['query_state']??'UNAVAILABLE')!=='AVAILABLE';$discUnavailable=($discProjection['query_state']??'UNAVAILABLE')!=='AVAILABLE';$disc=(array)($discProjection['items']??[]);echo '<tr><td>#'.esc_html((string)$row['id']).'</td><td>'.esc_html((string)$row['shop_reference']).'</td><td>'.esc_html((string)$row['state']).'</td><td>'.esc_html((string)($r['fulfillment_mode']??'UNKNOWN')).'</td><td>'.(isset($r['error'])?'UNAVAILABLE — '.esc_html((string)$r['error']):(!empty($r['ready'])?'YES':'NO')).' / reconciliation '.($recUnavailable?'UNAVAILABLE':(!empty($rec['reconciled'])?'OK':'REVIEW')).' / discrepancies '.($discUnavailable?'UNAVAILABLE':esc_html((string)count($disc))).'</td><td>NO</td></tr>';if($recUnavailable||$discUnavailable)echo '<tr><td colspan="6"><div class="df-notice df-notice-error">Order #'.esc_html((string)$row['id']).' reconciliation evidence unavailable. Database read failed; zero discrepancies and normal review status are not inferred. No retry or external execution authority is granted.</div></td></tr>';}
        echo '</tbody></table></div>';}if(($exceptions['query_state']['fulfillment']??'UNAVAILABLE')!=='AVAILABLE')echo '<div class="df-notice df-notice-error">Fulfillment exception evidence unavailable. Database read failed; no empty queue is inferred.</div>';echo '<p class="df-muted">Fulfillment exceptions requiring evidence review: '.esc_html($exceptions['fulfillment_total']===null?'UNAVAILABLE':(string)$exceptions['fulfillment_total']).'. The most recent 25 are displayed. This count does not authorize provider execution.</p>';if($fulfillmentExceptions!==[]){echo '<div class="df-table-wrap" role="region" tabindex="0" aria-label="Scrollable evidence table"><table class="df-table"><thead><tr><th>Intent</th><th>Order</th><th>State</th><th>Audit identity</th><th>Workflow</th></tr></thead><tbody>';foreach(array_slice($fulfillmentExceptions,0,25) as $row){echo '<tr><td>#'.esc_html((string)$row['id']).' '.esc_html((string)$row['intent_type']).'</td><td>#'.esc_html((string)$row['order_id']).'</td><td>'.esc_html((string)$row['state']).'</td><td><code>fulfillment_intent:'.esc_html((string)$row['id']).'</code></td><td><a class="df-button df-button-compact" href="'.esc_url($this->url('audit')).'">Open audit/reconciliation</a></td></tr>';}echo '</tbody></table></div>';}echo '</section>';
        $support=(new \DigiForge\Orders\ReplacementReviewRepository())->snapshot($shop);
        echo '<section class="df-panel"><div class="df-panel-head"><h2>Replacement support review</h2><span class="df-status">LOCAL HUMAN REVIEW</span></div><p>Requests freeze the exact stored order and listing/product/provider line identities, including closed orders. Case approval reviews evidence only. Replacement production and refunds require separate authority.</p>';
        if($ordersQueryState==='AVAILABLE')foreach($rows as $row){echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="'.esc_attr(self::REPLACEMENT_REQUEST_ACTION).'"><input type="hidden" name="order_id" value="'.esc_attr((string)$row['id']).'"><input type="hidden" name="shop_key" value="'.esc_attr($selectedShop).'"><input type="hidden" name="request_key" value="'.esc_attr('replacement-'.wp_generate_uuid4()).'">';wp_nonce_field(self::REPLACEMENT_REQUEST_ACTION);$this->confirmationFields(self::REPLACEMENT_REQUEST_ACTION,(int)$row['id']);echo '<p>Order #'.esc_html((string)$row['id']).' · '.esc_html((string)$row['shop_reference'].' / '.(string)$row['environment'].' / '.(string)$row['state']).' <button class="df-button df-button-compact" type="submit">Request local replacement review</button></p></form>';}
        if($support['query_state']!=='AVAILABLE')echo '<div class="df-notice df-notice-error">Replacement support evidence unavailable. No empty inbox or reviewed case is inferred.</div>';
        elseif($support['items']===[])echo '<div class="df-empty">No replacement support cases in this selected shop window.</div>';
        else{echo '<div class="df-table-wrap" role="region" tabindex="0" aria-label="Scrollable replacement support evidence"><table class="df-table"><thead><tr><th>Case / order</th><th>Shop / environment</th><th>Frozen source</th><th>Review state</th><th>Local decision</th></tr></thead><tbody>';foreach($support['items'] as $case){echo '<tr><td>#'.esc_html((string)$case['case_id']).' / #'.esc_html((string)$case['order_id']).'</td><td>'.esc_html((string)$case['shop_reference'].' / '.(string)$case['environment']).'</td><td>'.esc_html((string)$case['source_order_state'].' / '.(string)$case['line_count'].' lines').' <code>'.esc_html((string)$case['source_hash']).'</code></td><td>'.esc_html((string)$case['state']).'</td><td>';if($case['state']==='REQUESTED'){echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="'.esc_attr(self::REPLACEMENT_DECISION_ACTION).'"><input type="hidden" name="case_id" value="'.esc_attr((string)$case['case_id']).'"><input type="hidden" name="shop_key" value="'.esc_attr($selectedShop).'">';wp_nonce_field(self::REPLACEMENT_DECISION_ACTION);$this->confirmationFields(self::REPLACEMENT_DECISION_ACTION,(int)$case['case_id']);echo '<button class="df-button df-button-compact" name="decision" value="APPROVED_LOCAL_REVIEW" type="submit">Approve local case evidence</button> <button class="df-button df-button-compact" name="decision" value="REJECTED_LOCAL_REVIEW" type="submit">Reject local case evidence</button></form>';}else echo 'Immutable decision / source recheck required';echo '</td></tr>';}echo '</tbody></table></div>';}echo '</section>';
    }

    private function aiBudgetOperations():void
    {
        $shop=ShopOperationsReadModel::normalize(isset($_GET['df_shop'])?sanitize_key(wp_unslash($_GET['df_shop'])):ShopOperationsReadModel::ALL);$data=(new OperationalDepthReadModel())->aiBudget($shop);$cost=(array)$data['cost'];$policies=(array)$data['policies'];if(($data['policies_query_state']??'UNAVAILABLE')!=='AVAILABLE'||($cost['query_state']??'UNAVAILABLE')!=='AVAILABLE'){echo '<section class="df-panel"><h2>AI & Budget</h2><div class="df-notice df-notice-error">AI policy or cost evidence unavailable. Database read failed; counts and spend cannot be verified.</div><p>External execution authority: NO</p></section>';return;}$hierarchy=(new HierarchicalPolicyReadModel())->snapshot($shop);$scenarios=(new HierarchicalPolicyReadModel())->scenarios($shop);
        echo '<section class="df-panel"><div class="df-panel-head"><div><h2>AI & Budget</h2><p>Recorded amounts may exclude pending provider charges; zero is not verified zero spend. Shop-scoped policy identities, quantities and estimated/actual spend. Policy evidence never grants execution authority. Global disable always wins; a narrower shop/workflow scope cannot override a disabled parent.</p></div><span class="df-status">READ ONLY</span></div><div class="df-signal-grid"><div><span>Shop scope</span><b>'.esc_html(ShopOperationsReadModel::shops()[$shop]).'</b></div><div><span>Active policy records</span><b>'.esc_html((string)count($policies)).'</b></div><div><span>Attributed quantity</span><b>'.esc_html((string)($cost['quantity']??0)).'</b></div><div><span>Estimated spend</span><b>'.esc_html(number_format((float)($cost['estimated_cost']??0),4)).'</b></div><div><span>Recorded actual spend</span><b>'.esc_html(number_format((float)($cost['actual_cost']??0),4)).'</b></div><div><span>External execution authority</span><b>NO</b></div></div>';
        if($policies!==[]){echo '<div class="df-table-wrap" role="region" tabindex="0" aria-label="Scrollable evidence table"><table class="df-table"><thead><tr><th>Shop</th><th>Environment</th><th>Currency</th><th>State</th><th>Policy identity</th><th>Updated</th></tr></thead><tbody>';foreach($policies as $row){echo '<tr><td>'.esc_html((string)$row['shop_key']).'</td><td>'.esc_html((string)$row['environment']).'</td><td>'.esc_html((string)$row['currency']).'</td><td>'.esc_html((string)$row['state']).'</td><td><code>'.esc_html(substr((string)$row['policy_hash'],0,12)).'…</code></td><td>'.esc_html((string)$row['updated_at']).'</td></tr>';}echo '</tbody></table></div>';}else{echo '<div class="df-empty">No AI policy exists for this shop scope.</div>';}if(($scenarios['query_state']??'PARTIAL_UNAVAILABLE')!=='AVAILABLE')echo '<div class="df-notice df-notice-error">One or more AI planning scenarios could not verify authoritative shop policy/usage evidence. No permissive planning result is inferred.</div>';echo '<p class="df-muted">Planning scenarios evaluated: '.esc_html((string)count((array)$scenarios['scenarios'])).' · unavailable: '.esc_html((string)($scenarios['unavailable_scenarios']??0)).'. Quantities 1 / 5 / 10 are evaluated for each governed stage against the current shop policy and budget ceilings. Scenarios are preflight evidence only; they do not run AI or authorize external execution.</p>';
        if($shop!==ShopOperationsReadModel::ALL){$policyProjection=(new ShopAiGovernanceRepository())->evaluate($shop,'production');$missing=is_wp_error($policyProjection)&&$policyProjection->get_error_code()==='ai_policy_missing';if(is_wp_error($policyProjection)&&!$missing){echo '<hr><h3>Shop AI policy</h3><div class="df-notice df-notice-error">Authoritative shop AI policy evidence is unavailable. Editing is blocked to avoid replacing an unknown policy with defaults.</div>';}else{$editor=$missing?['currency'=>'INR','budgets'=>['run'=>0,'day'=>0,'month'=>0],'stages'=>[]]:(array)$policyProjection;echo '<hr><h3>Shop AI policy</h3><p class="df-muted">Local governance only. Saving ceilings does not run AI, arm automation, or authorize external execution.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="'.esc_attr(self::SAVE_AI_POLICY_ACTION).'"><input type="hidden" name="shop_key" value="'.esc_attr($shop).'">';wp_nonce_field(self::SAVE_AI_POLICY_ACTION);echo '<p><label>Currency <input name="currency" maxlength="3" value="'.esc_attr((string)($editor['currency']??'INR')).'" required></label> <label>Run budget <input type="number" min="0" step="0.000001" name="budget_run" value="'.esc_attr((string)($editor['budgets']['run']??0)).'"></label> <label>Daily budget <input type="number" min="0" step="0.000001" name="budget_day" value="'.esc_attr((string)($editor['budgets']['day']??0)).'"></label> <label>Monthly budget <input type="number" min="0" step="0.000001" name="budget_month" value="'.esc_attr((string)($editor['budgets']['month']??0)).'"></label></p><div class="df-table-wrap" role="region" tabindex="0" aria-label="Scrollable evidence table"><table class="df-table"><thead><tr><th>Stage</th><th>Monthly quantity ceiling</th><th>Estimated unit cost</th><th>Stage monthly spend ceiling</th><th>Monetary admission</th><th>Reviewed task / provider models</th></tr></thead><tbody>';foreach(ShopAiPlan::STAGES as $stage){$stagePolicy=(array)($editor['stages'][$stage]??[]);echo '<tr><td>'.esc_html($stage).'</td><td><input type="number" min="0" step="1" aria-label="'.esc_attr($stage . ' monthly quantity ceiling').'" name="stage_'.esc_attr($stage).'_limit" value="'.esc_attr((string)($stagePolicy['limit']??0)).'"></td><td><input type="number" min="0" step="0.000001" aria-label="'.esc_attr($stage . ' estimated unit cost').'" name="stage_'.esc_attr($stage).'_unit_cost" value="'.esc_attr((string)($stagePolicy['estimated_unit_cost']??0)).'"></td><td><input type="text" inputmode="decimal" aria-label="'.esc_attr($stage.' monthly spend ceiling').'" name="stage_'.esc_attr($stage).'_monthly_budget" value="'.esc_attr((string)($stagePolicy['monthly_budget']??'')).'" placeholder="No independent ceiling"><small>Blank uses parent limits; 0 denies provider spending.</small></td><td>'.esc_html(match($stagePolicy['monetary_admission_state']??'MISSING_CEILING'){'EXPLICIT_ZERO_CEILING'=>'Explicit zero ceiling: provider spending denied','CEILING_CONFIGURED_QUOTE_REQUIRED'=>'Ceiling configured: exact provider quote required',default=>'Missing monetary ceiling: provider spending denied'}).'</td><td><textarea name="stage_'.esc_attr($stage).'_task_binding" aria-label="'.esc_attr($stage.' reviewed task binding').'" rows="3">'.esc_textarea(isset($stagePolicy['task_binding'])?(string)wp_json_encode($stagePolicy['task_binding']):'').'</textarea><small>Reviewed task_id and registered-model to exact provider-model mapping. Blank keeps legacy routing advisory.</small></td></tr>';}echo '</tbody></table></div>';$capacity=(array)($editor['template_capacity']??[]);if(($capacity['state']??'')==='UNAVAILABLE'){echo '<p class="df-notice df-notice-error">Template capacity evidence unavailable. Saving is blocked until the policy and usage can be verified.</p></form>';}else{echo '<fieldset><legend>New production-template capacity</legend><p>Leave all fields blank to disable new template creation. Limits do not authorize production.</p><label>Templates per cycle <input type="number" min="1" max="500" step="1" name="template_cycle_limit" value="'.esc_attr((string)($capacity['limit']??'')).'"></label> <label>Cycle seconds <input type="number" min="60" max="31536000" step="1" name="template_cycle_seconds" value="'.esc_attr((string)($capacity['duration_seconds']??'')).'"></label> <label>UTC anchor <input name="template_cycle_anchor" placeholder="YYYY-MM-DD HH:MM:SS" value="'.esc_attr((string)($capacity['anchor_utc']??'')).'"></label><p>Current cycle used: '.esc_html((string)($capacity['used']??'not configured')).' · remaining: '.esc_html((string)($capacity['remaining']??'not configured')).'</p></fieldset><p><button class="df-button" type="submit">Save shop AI policy</button></p></form>';}}}else{echo '<div class="df-notice">Select one concrete shop to edit its AI policy. All-shops scope remains read only.</div>';}echo '</section>';
        CommercialKpiView::render((array)($cost['commercial_kpis']??[]));
    }

    private function fulfillmentProviders():void
    {
        $selectedShop=ShopOperationsReadModel::normalize(isset($_GET['df_shop'])?sanitize_key(wp_unslash($_GET['df_shop'])):ShopOperationsReadModel::ALL);$shop=$selectedShop===ShopOperationsReadModel::ALL?null:$selectedShop;
        $queryState=null;$plans=(new OperationalDepthReadModel())->fulfillmentProviders(50,$queryState,$shop);$providerStatus=(new ProviderStatusReadModel())->snapshot(25,$shop);
        echo '<section class="df-panel"><div class="df-panel-head"><div><h2>Fulfillment / Providers</h2><p>Provider-plan evidence and readiness identities. A prepared or approved plan is not production authorization.</p></div><span class="df-status">READ ONLY</span></div>';
        if($queryState==='UNAVAILABLE'){echo '<div class="df-notice df-notice-error">Fulfillment plan evidence unavailable. Database read failed; plan count and readiness cannot be verified.</div></section>';return;}
        if(($providerStatus['query_state']??'UNAVAILABLE')!=='AVAILABLE')echo '<div class="df-notice df-notice-error">Printify UNKNOWN outcome evidence unavailable. Reconciliation counts cannot be verified; retry remains prohibited.</div>';if($plans===[]){echo '<div class="df-empty">No fulfillment plans found.</div></section>';return;}
        $needsReview=count(array_filter($plans,static fn(array $p):bool=>in_array((string)$p['evidence_state'],['EVIDENCE_INVALID_REVIEW','CURRENT_READINESS_UNAVAILABLE','STALE_RECHECK'],true)));
        echo '<p class="df-muted">Recent approved plans needing evidence review: '.esc_html((string)$needsReview).' of up to 50 recent plans. Draft and historical states are excluded. Current evidence never authorizes provider production.</p>';
        echo '<div class="df-table-wrap" role="region" tabindex="0" aria-label="Scrollable evidence table"><table class="df-table"><thead><tr><th>Plan</th><th>Order</th><th>Provider</th><th>State</th><th>Payload</th><th>Readiness</th><th>Evidence review</th><th>Operator route</th><th>Provider execution</th><th>Production</th></tr></thead><tbody>';foreach($plans as $row){echo '<tr><td>#'.esc_html((string)$row['id']).' '.esc_html((string)$row['plan_version']).'</td><td>#'.esc_html((string)$row['order_id']).'</td><td>'.esc_html((string)$row['provider']).'</td><td>'.esc_html((string)$row['state']).'</td><td><code>'.esc_html(substr((string)$row['payload_hash'],0,12)).'…</code></td><td><code>'.esc_html(substr((string)$row['readiness_hash'],0,12)).'…</code></td><td>'.esc_html((string)$row['evidence_state']).'</td><td>';if(in_array((string)$row['evidence_state'],['EVIDENCE_INVALID_REVIEW','CURRENT_READINESS_UNAVAILABLE','STALE_RECHECK'],true)){echo '<a class="df-button df-button-compact" href="'.esc_url(add_query_arg(['df_fulfillment_plan'=>(int)$row['id'],'df_order'=>(int)$row['order_id']],$this->url('audit'))).'#df-fulfillment-focus">Open reconciliation</a>';}else{echo 'NO ACTION';}echo '</td><td>NO</td><td>NO</td></tr>';}echo '</tbody></table></div><p class="df-muted">Provider execution and production require their separate governed authorization boundary; this view cannot submit or retry a provider order.</p><div class="df-signal-grid"><div><span>Printify UNKNOWN outcomes</span><b>'.esc_html($providerStatus['unknown_outcomes']===null?'UNAVAILABLE':(string)$providerStatus['unknown_outcomes']).'</b></div><div><span>Unresolved reconciliation</span><b>'.esc_html($providerStatus['unresolved_reconciliations']===null?'UNAVAILABLE':(string)$providerStatus['unresolved_reconciliations']).'</b></div><div><span>Provider execution authority</span><b>NO</b></div><div><span>Retry authority</span><b>NO</b></div></div><p class="df-muted">UNKNOWN provider outcomes require reconciliation before any retry; this evidence cannot authorize production.</p></section>';
    }

    private function financeOperations():void
    {
        $shop=ShopOperationsReadModel::normalize(isset($_GET['df_shop'])?sanitize_key(wp_unslash($_GET['df_shop'])):ShopOperationsReadModel::ALL);$kpi=(new CostKpiReadModel())->snapshot($shop);$costUnavailable=($kpi['query_state']??'UNAVAILABLE')!=='AVAILABLE';$denominators=(new LifecycleDenominatorReadModel())->snapshot();$exceptions=(new OperationalExceptionReadModel())->snapshot(50);$ledgerExceptions=(array)$exceptions['finance_ledger'];$taxExceptions=(array)$exceptions['tax'];
        $financeOps=(new FinanceOperationsReadModel())->snapshot(25);$periods=(array)$financeOps['periods'];
        echo '<section class="df-panel"><div class="df-panel-head"><div><h2>Profitability periods</h2><p>Deterministic local ledger rollups for revenue, costs, profit and margin. Metrics are displayed only when their persisted hash verifies.</p></div><span class="df-status">READ ONLY</span></div>';
        if(($financeOps['query_state']['periods']??'UNAVAILABLE')!=='AVAILABLE'){echo '<div class="df-notice df-notice-error">Finance period evidence unavailable. Profitability is not inferred.</div>';}elseif($periods===[]){echo '<div class="df-empty">No calculated finance periods in the bounded view.</div>';}else{echo '<div class="df-table-wrap" role="region" tabindex="0" aria-label="Scrollable evidence table"><table class="df-table"><thead><tr><th>Period</th><th>Environment</th><th>Revenue</th><th>Net operating profit</th><th>Margin</th><th>Reconciliation</th><th>Evidence</th></tr></thead><tbody>';foreach($periods as $row){$m=(array)$row['metrics'];echo '<tr><td>'.esc_html((string)$row['period_start'].' — '.(string)$row['period_end']).'</td><td>'.esc_html((string)$row['environment']).'</td><td>'.esc_html(!empty($row['metrics_valid'])?number_format((float)($m['gross_revenue']??0),2).' '.(string)$row['base_currency']:'UNVERIFIED').'</td><td>'.esc_html(!empty($row['metrics_valid'])?number_format((float)($m['net_operating_profit']??0),2).' '.(string)$row['base_currency']:'UNVERIFIED').'</td><td>'.esc_html(!empty($row['metrics_valid'])?number_format((float)($m['margin_percent']??0),2).'%':'UNVERIFIED').'</td><td>'.esc_html(!empty($row['metrics_valid'])?(string)($m['unresolved_reconciliation_count']??'UNKNOWN'):'UNVERIFIED').'</td><td>'.esc_html(!empty($row['metrics_valid'])?'HASH VERIFIED':'BLOCKED').'</td></tr>';}echo '</tbody></table></div>';}echo '<p class="df-muted">Profitability evidence cannot move money, approve refunds, file GST/tax, or authorize external accounting actions.</p></section>';
        if(($exceptions['query_state']['finance_ledger']??'UNAVAILABLE')!=='AVAILABLE'||($exceptions['query_state']['tax']??'UNAVAILABLE')!=='AVAILABLE')echo '<div class="df-notice df-notice-error">Finance or tax exception evidence unavailable. Database read failed; no empty review queue is inferred.</div>';echo '<section class="df-panel"><div class="df-panel-head"><div><h2>AI cost KPIs</h2><p>Actual and estimated attributable AI cost for the selected shop scope.</p></div><span class="df-status">READ ONLY</span></div><div class="df-signal-grid"><div><span>Attributed units</span><b>'.esc_html($costUnavailable?'UNAVAILABLE':(string)$kpi['quantity']).'</b></div><div><span>Estimated cost</span><b>'.esc_html($costUnavailable?'UNAVAILABLE':number_format((float)$kpi['estimated_cost'],4)).'</b></div><div><span>Actual cost</span><b>'.esc_html($costUnavailable?'UNAVAILABLE':number_format((float)$kpi['actual_cost'],4)).'</b></div><div><span>Cost / unit</span><b>'.esc_html($costUnavailable?'UNAVAILABLE':number_format((float)$kpi['cost_per_attributed_unit'],4)).'</b></div></div></section>';echo '<section class="df-panel"><div class="df-panel-head"><div><h2>Lifecycle denominators</h2><p>Authoritative raw lifecycle counts only. They are not attributed to AI spend.</p></div><span class="df-status">READ ONLY</span></div><div class="df-signal-grid"><div><span>Opportunities</span><b>'.esc_html((string)$denominators['opportunities']).'</b></div><div><span>Developed products</span><b>'.esc_html((string)$denominators['developed_products']).'</b></div><div><span>Approved listings</span><b>'.esc_html((string)$denominators['approved_listings']).'</b></div><div><span>Received orders</span><b>'.esc_html((string)$denominators['received_orders']).'</b></div></div><p class="df-muted">AI cost attribution: NOT ESTABLISHED</p></section>';echo '<section class="df-panel"><div class="df-panel-head"><div><h2>Finance / GST exceptions</h2><p>Unreconciled ledger and tax-review evidence only.</p></div><span class="df-status">READ ONLY</span></div><div class="df-signal-grid"><div><span>Ledger exceptions</span><b>'.esc_html(($exceptions['query_state']['finance_ledger']??'UNAVAILABLE')==='AVAILABLE'?(string)count($ledgerExceptions):'UNAVAILABLE').'</b></div><div><span>Tax/GST review exceptions</span><b>'.esc_html(($exceptions['query_state']['tax']??'UNAVAILABLE')==='AVAILABLE'?(string)count($taxExceptions):'UNAVAILABLE').'</b></div><div><span>Money movement authority</span><b>NO</b></div><div><span>Tax filing authority</span><b>NO</b></div></div><p class="df-muted">Exception evidence never files GST/tax, changes tax classification, issues refunds, or moves money.</p>';if($ledgerExceptions!==[]||$taxExceptions!==[]){echo '<div class="df-table-wrap" role="region" tabindex="0" aria-label="Scrollable evidence table"><table class="df-table"><thead><tr><th>Type</th><th>ID</th><th>Source</th><th>Status</th><th>Audit identity</th><th>Workflow</th></tr></thead><tbody>';foreach(array_slice($ledgerExceptions,0,25) as $row){echo '<tr><td>Ledger</td><td>#'.esc_html((string)$row['id']).'</td><td>'.esc_html((string)$row['source_type']).' #'.esc_html((string)$row['source_id']).'</td><td>'.esc_html((string)$row['reconciliation_state']).'</td><td><code>finance_ledger:'.esc_html((string)$row['id']).'</code></td><td><a class="df-button df-button-compact" href="'.esc_url($this->url('audit')).'">Open audit/reconciliation</a></td></tr>';}foreach(array_slice($taxExceptions,0,25) as $row){echo '<tr><td>Tax/GST</td><td>#'.esc_html((string)$row['id']).'</td><td>'.esc_html((string)$row['source_type']).' #'.esc_html((string)$row['source_id']).'</td><td>'.esc_html((string)$row['classification']).' / '.esc_html((string)$row['review_status']).'</td><td><code>tax_classification:'.esc_html((string)$row['id']).'</code></td><td><a class="df-button df-button-compact" href="'.esc_url($this->url('audit')).'">Open audit/reconciliation</a></td></tr>';}echo '</tbody></table></div>';}echo '</section>';
        foreach($this->tables('finance') as $label=>$table)$this->panelTable($label,$table);
    }

    private function businessOperations(): void
    {
        $shop=ShopOperationsReadModel::normalize(isset($_GET['df_shop'])?sanitize_key(wp_unslash($_GET['df_shop'])):ShopOperationsReadModel::ALL);
        $ops=(new ShopOperationsReadModel())->snapshot($shop);
        echo '<section class="df-panel"><div class="df-panel-head"><div><h2>Businesses / Brands / Shops</h2><p>Shop-scoped operating context. AI policy and usage evidence do not grant external execution authority.</p></div><span class="df-status">READ ONLY</span></div><div class="df-signal-grid"><div><span>Selected scope</span><b>'.esc_html((string)$ops['shop_label']).'</b></div><div><span>AI policies</span><b>'.esc_html(($ops['query_state']['ai_policies']??'UNAVAILABLE')==='AVAILABLE'?(string)count((array)$ops['ai_policies']):'UNAVAILABLE').'</b></div><div><span>AI usage groups</span><b>'.esc_html(($ops['query_state']['ai_usage']??'UNAVAILABLE')==='AVAILABLE'?(string)count((array)$ops['ai_usage']):'UNAVAILABLE').'</b></div><div><span>External execution</span><b>NO</b></div></div>'.(in_array('UNAVAILABLE',(array)($ops['query_state']??[]),true)?'<div class="df-notice df-notice-error">Shop operating evidence unavailable. Database read failed; zero AI policy or usage evidence is not inferred.</div>':'').'</section>';
        $ownership=(new BusinessOwnershipReadModel())->snapshot($shop);
        echo '<section class="df-panel"><div class="df-panel-head"><h2>Stored business and provider ownership</h2><span class="df-status">READ ONLY</span></div>';
        if($ownership['query_state']!=='AVAILABLE')echo '<div class="df-notice df-notice-error">Business/provider ownership evidence unavailable. No empty registry or approved provider connection is inferred.</div>';
        elseif($ownership['items']===[])echo '<div class="df-empty">No linked ownership evidence in this selected shop window. This does not certify that a shop or provider is configured.</div>';
        else{echo '<div class="df-table-wrap" role="region" tabindex="0" aria-label="Scrollable business ownership evidence"><table class="df-table"><thead><tr><th>Business</th><th>Store / channel</th><th>Program</th><th>Product version</th><th>Provider / environment</th><th>Mapping / product / variant</th><th>Evidence</th><th>Inspect</th></tr></thead><tbody>';foreach($ownership['items'] as $row){echo '<tr><td>'.esc_html((string)$row['business_name'].' ['.(string)$row['business_key'].']').'</td><td>'.esc_html((string)$row['store_name'].' ['.(string)$row['store_key'].'] / '.(string)$row['channel']).'</td><td>'.esc_html((string)$row['program_key']).'</td><td>#'.esc_html((string)$row['product_version_id']).'</td><td>'.esc_html((string)$row['provider'].' / '.(string)$row['environment']).'</td><td>#'.esc_html((string)$row['provider_mapping_id'].' / '.(string)$row['provider_product_key'].' / '.(string)$row['provider_variant_key'].' @ '.(string)$row['mapping_version']).'</td><td>'.esc_html((string)$row['evidence_state']).'</td><td><a class="df-button df-button-compact" href="'.esc_url($this->url('orders')).'">Review ownership</a> <a class="df-button df-button-compact" href="'.esc_url($this->url('fulfillment')).'">Inspect provider plans</a></td></tr>';}echo '</tbody></table></div>';}
        echo '<p class="df-muted">Ownership is shown only when business, store, program and provider mapping share stored ancestry. Inactive or unreviewed records remain visible as evidence. A provider mapping does not establish a connector or provider-shop binding. This view cannot activate shops, approve production or call providers.</p></section>';
    }

    private function analyticsOperations(): void
    {
        $shop=ShopOperationsReadModel::normalize(isset($_GET['df_shop'])?sanitize_key(wp_unslash($_GET['df_shop'])):ShopOperationsReadModel::ALL);
        $kpi=(new CostKpiReadModel())->snapshot($shop);$denominators=(new LifecycleDenominatorReadModel())->snapshot();$financeOps=(new FinanceOperationsReadModel())->snapshot(25);$analytics=(array)$financeOps['analytics'];$kpiAvailable=($kpi['query_state']??'UNAVAILABLE')==='AVAILABLE';$denominatorAvailable=($denominators['query_state']??'UNAVAILABLE')==='AVAILABLE';
        echo '<section class="df-panel"><div class="df-panel-head"><div><h2>Analytics</h2><p>Read-only operational measurement, separated from finance ledger administration.</p></div><span class="df-status">READ ONLY</span></div>';
        if(!$kpiAvailable||!$denominatorAvailable){echo '<div class="df-notice df-notice-error">Operational analytics evidence unavailable. DigiForge does not infer zero cost, zero units, zero products or zero orders from a failed read.</div>';}
        echo '<div class="df-signal-grid"><div><span>Attributed units</span><b>'.esc_html($kpiAvailable?(string)$kpi['quantity']:'UNAVAILABLE').'</b></div><div><span>Actual AI cost</span><b>'.esc_html($kpiAvailable?number_format((float)$kpi['actual_cost'],4):'UNAVAILABLE').'</b></div><div><span>Developed products</span><b>'.esc_html($denominatorAvailable?(string)$denominators['developed_products']:'UNAVAILABLE').'</b></div><div><span>Received orders</span><b>'.esc_html($denominatorAvailable?(string)$denominators['received_orders']:'UNAVAILABLE').'</b></div></div><p class="df-muted">Analytics is evidence only and does not infer publish, production, refund, tax or money-movement authority.</p></section>';
        CommercialKpiView::render((array)($kpi['commercial_kpis']??[]));
        echo '<section class="df-panel"><div class="df-panel-head"><div><h2>Analytics snapshots</h2><p>Bounded immutable snapshots are shown only when their persisted metrics hash verifies.</p></div><span class="df-status">READ ONLY</span></div>';
        if(($financeOps['query_state']['analytics']??'UNAVAILABLE')!=='AVAILABLE'){echo '<div class="df-notice df-notice-error">Analytics snapshot evidence unavailable. No empty history is inferred.</div>';}elseif($analytics===[]){echo '<div class="df-empty">No analytics snapshots in the bounded view.</div>';}else{echo '<div class="df-table-wrap" role="region" tabindex="0" aria-label="Scrollable evidence table"><table class="df-table"><thead><tr><th>Dimension</th><th>Period</th><th>Environment</th><th>Evidence</th></tr></thead><tbody>';foreach($analytics as $row){echo '<tr><td>'.esc_html((string)$row['dimension_type'].' #'.(string)$row['dimension_id']).'</td><td>'.esc_html((string)$row['period_start'].' — '.(string)$row['period_end']).'</td><td>'.esc_html((string)$row['environment']).'</td><td>'.esc_html(!empty($row['metrics_valid'])?'HASH VERIFIED':'BLOCKED').'</td></tr>';}echo '</tbody></table></div>';}echo '<p class="df-muted">Analytics snapshots cannot authorize external execution or financial actions.</p></section>';
    }

    private function automationOperations(): void
    {
        $scheduled=(new \DigiForge\Queue\ScheduledTaskReadModel())->snapshot();
        echo '<section class="df-panel"><div class="df-panel-head"><h2>Product Factory scheduled tasks</h2><span class="df-status">READ ONLY</span></div><p class="df-muted">System-wide bounded scheduler metadata only. Viewing tasks cannot run, retry or dispatch them; task arguments and run keys are hidden.</p><div class="df-signal-grid"><div><span>Action Scheduler evidence</span><b>'.esc_html($scheduled['query_state']['action_scheduler']).'</b></div><div><span>WP cron evidence</span><b>'.esc_html($scheduled['query_state']['wp_cron']).'</b></div></div>';
        if($scheduled['query_state']['action_scheduler']!=='AVAILABLE')echo '<p class="df-muted">Action Scheduler is unavailable or not installed; an empty scheduler is not inferred.</p>';
        if($scheduled['query_state']['wp_cron']!=='AVAILABLE')echo '<div class="df-notice df-notice-error">WP cron evidence unavailable. Missing evidence grants no recovery authority.</div>';
        if($scheduled['items']!==[]){echo '<div class="df-table-wrap" role="region" tabindex="0" aria-label="Scrollable scheduled task evidence"><table class="df-table"><thead><tr><th>Scheduler</th><th>Candidate</th><th>Shop</th><th>Stage</th><th>State</th><th>Retry / execution</th></tr></thead><tbody>';foreach($scheduled['items']as $task)echo '<tr><td>'.esc_html($task['scheduler']).'</td><td>#'.esc_html((string)$task['candidate_id']).'</td><td>'.esc_html($task['shop']).'</td><td>'.esc_html($task['stage']).'</td><td>'.esc_html($task['state']).'</td><td>NO</td></tr>';echo '</tbody></table></div>';}
        if($scheduled['truncated'])echo '<p class="df-muted">The bounded task window may omit older evidence.</p>';
        echo '</section>';
        $health=(new \DigiForge\Observability\HealthMonitor())->snapshot();$q=(array)($health['queue']??[]);$queue=(array)($q['recovery']??[]);$states=(array)($queue['states']??[]);$queueRead=new OperatorQueueReadModel();$attentionState=null;$idempotencyState=null;$items=$queueRead->recentAttention(50,$attentionState);$diagnostics=$queueRead->diagnostics();$recoveryEvidence=$queueRead->recoveryEvidence(25);$recoveryClass=$queueRead->recoveryClassification();$idempotency=$queueRead->unresolvedIdempotency(25,$idempotencyState);
        $jobFocus=isset($_GET['df_job_id'])?absint(wp_unslash($_GET['df_job_id'])):0;
        $focusedJob=$jobFocus>0?$queueRead->attentionById($jobFocus):null;
        if($focusedJob!==null&&!in_array($jobFocus,array_map('intval',array_column($items,'id')),true))array_unshift($items,$focusedJob);
        echo '<section class="df-panel"><div class="df-panel-head"><div><h2>Automation / Queues</h2><p>Read-only queue and recovery visibility. Viewing this page never runs, retries or replays a job.</p></div><span class="df-status">READ ONLY</span></div><div class="df-signal-grid"><div><span>Queue query</span><b>'.(!empty($q['query_ok'])?'VERIFIED':'FAILED').'</b></div><div><span>Expired leases</span><b>'.esc_html((string)($q['expired_leases']??0)).'</b></div><div><span>FAILED</span><b>'.esc_html((string)($states['FAILED']??0)).'</b></div><div><span>BLOCKED</span><b>'.esc_html((string)($states['BLOCKED']??0)).'</b></div><div><span>HUMAN_REVIEW</span><b>'.esc_html((string)($states['HUMAN_REVIEW']??0)).'</b></div><div><span>DEAD_LETTER</span><b>'.esc_html((string)($states['DEAD_LETTER']??0)).'</b></div><div><span>Execution authority</span><b>NO</b></div><div><span>Automatic retry from view</span><b>NO</b></div></div>';
        echo '<div class="df-signal-grid"><div><span>Orphaned expired leases</span><b>'.esc_html($diagnostics['orphaned_expired_leases']===null?'UNAVAILABLE':(string)$diagnostics['orphaned_expired_leases']).'</b></div><div><span>Rate-limit evidence</span><b>'.esc_html($diagnostics['rate_limit_evidence']===null?'UNAVAILABLE':(string)$diagnostics['rate_limit_evidence']).'</b></div><div><span>Unresolved idempotency</span><b>'.esc_html($diagnostics['unresolved_idempotency_evidence']===null?'UNAVAILABLE':(string)$diagnostics['unresolved_idempotency_evidence']).'</b></div><div><span>Replay permitted</span><b>NO</b></div></div>';
        if($idempotencyState!=='AVAILABLE'){echo '<div class="df-notice df-notice-error">Unresolved idempotency evidence unavailable. No empty set or safe replay state is inferred.</div>';}elseif($idempotency!==[]){echo '<p class="df-muted">Bounded unresolved idempotency drill-down: '.esc_html((string)count($idempotency)).' records. Operation keys are redacted to SHA-256 identities; replay and retry remain NO.</p>';}echo '<p class="df-muted">Recovery classifications: '.esc_html($recoveryClass['classifications']===[]?'NONE':implode(', ',$recoveryClass['classifications'])).'. Classification is evidence only; automatic recovery, replay and retry remain NO.</p>'; 
        echo '<p class="df-muted"><strong>Recovery guidance:</strong> UNKNOWN external outcomes must be reconciled before any retry. FAILED/BLOCKED/HUMAN_REVIEW/DEAD_LETTER are evidence for operator inspection, not retry permission. Use Attention & Recovery for cross-channel reconciliation evidence.</p><p class="df-muted">Correlated recovery evidence: '.esc_html($recoveryEvidence['correlation']['attention_count']===null?'UNAVAILABLE':(string)$recoveryEvidence['correlation']['attention_count']).' attention records / '.esc_html($recoveryEvidence['correlation']['idempotency_count']===null?'UNAVAILABLE':(string)$recoveryEvidence['correlation']['idempotency_count']).' unresolved idempotency records. Automatic recovery authority: NO.</p>';
        if($jobFocus>0&&$focusedJob===null)echo '<div class="df-notice df-notice-error">Requested queue attention record is unavailable or no longer needs review. No retry or replay is inferred.</div>';
        if($attentionState!=='AVAILABLE'){echo '<div class="df-notice df-notice-error">Queue attention evidence unavailable. Database read failed; no empty attention queue is inferred.</div>';}elseif($items===[]){echo '<div class="df-empty">No queue items currently require operator attention.</div>';}else{echo '<div class="df-table-wrap" role="region" tabindex="0" aria-label="Scrollable evidence table"><table class="df-table"><thead><tr><th>ID</th><th>Type</th><th>State</th><th>Attempts</th><th>Last error</th><th>Updated</th><th>Retry</th><th>Authority</th></tr></thead><tbody>';foreach($items as $item){$id=(int)$item['id'];echo '<tr'.($id===$jobFocus?' id="df-job-'.esc_attr((string)$id).'"':'').'><td><a href="'.esc_url(add_query_arg(['df_job_id'=>$id],$this->url('automation')).'#df-job-'.$id).'">#'.esc_html((string)$id).'</a></td><td>'.esc_html((string)$item['job_type']).'</td><td>'.esc_html((string)$item['state']).'</td><td>'.esc_html((string)$item['attempts']).'/'.esc_html((string)$item['max_attempts']).'</td><td>'.esc_html((string)($item['last_error']??'')).'</td><td>'.esc_html((string)$item['updated_at']).'</td><td>NO</td><td>NO</td></tr>';}echo '</tbody></table></div>';}echo '</section>';
    }

    private function settingsOperations(): void
    {
        $readiness=(new Readiness())->report();$switches=(array)($readiness['effective_switches']??[]);$policyRepo=new ScopedCapabilityPolicyRepository();$policyCurrentState=null;$policyHistoryState=null;$policyCurrent=$policyRepo->current(100,$policyCurrentState);$policyHistory=$policyRepo->recent('', '',50,$policyHistoryState);
        echo '<section class="df-panel"><div class="df-panel-head"><div><h2>Settings</h2><p>Operational configuration summary. High-risk execution controls remain in System & Controls with their existing authorization gates.</p></div><span class="df-status">READ ONLY</span></div><div class="df-signal-grid"><div><span>Version</span><b>'.esc_html(DIGIFORGE_VERSION).'</b></div><div><span>Schema</span><b>'.esc_html((string)($readiness['schema']['current']??'UNKNOWN')).'</b></div><div><span>Externally locked</span><b>'.(!empty($readiness['externally_locked'])?'YES':'NO').'</b></div><div><span>Readiness</span><b>'.esc_html((string)($readiness['status']??'REVIEW_REQUIRED')).'</b></div><div><span>Effective switches ON</span><b>'.esc_html((string)count(array_filter($switches))).'</b></div><div><span>Recovery drill</span><b>'.(!empty($readiness['checks']['recovery_drill_passed'])?'PASS':'NOT PASSED').'</b></div></div><p class="df-muted">This page is observational. It cannot enable research, AI, Etsy, POD, order automation, GST automation or external execution.</p><div class="df-signal-grid"><div><span>Current scoped policies</span><b>'.esc_html($policyCurrentState==='AVAILABLE'?(string)count($policyCurrent):'UNAVAILABLE').'</b></div><div><span>Recent policy evidence</span><b>'.esc_html($policyHistoryState==='AVAILABLE'?(string)count($policyHistory):'UNAVAILABLE').'</b></div><div><span>Policy execution authority</span><b>NO</b></div></div><p class="df-muted">Scoped policy history is append-only evidence. A policy row cannot override STOP ALL or grant external execution authority.</p>'.(($policyCurrentState!=='AVAILABLE'||$policyHistoryState!=='AVAILABLE')?'<div class="df-notice df-notice-error">Scoped policy evidence unavailable. Database read failed; zero current policies or empty policy history is not inferred.</div>':'').'</section>';
        $showSuccess=(new NotificationPreferences())->current();
        echo '<section class="df-panel"><div class="df-panel-head"><h2>Your portal notifications</h2></div><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="'.esc_attr(self::NOTIFICATION_PREFERENCES_ACTION).'">';wp_nonce_field(self::NOTIFICATION_PREFERENCES_ACTION);echo '<label><input type="checkbox" name="show_success_notifications" value="1"'.($showSuccess?' checked':'').'> Show routine success notifications</label><p class="df-muted">This preference applies to your operator account. Errors, STOP ALL, blockers and evidence warnings always remain visible.</p><button class="df-button" type="submit">Save notification preference</button></form></section>';
    }

    private function personalizedPodSummary(): void
    {
        $m=PersonalizedCatalogReference::metadata();
        $v2Ingestion=(new MasterCatalogV2IngestionReadModel())->snapshot();
        $ops=(new ShopOperationsReadModel())->snapshot('personalized_pod');$catalog=(array)($ops['catalog']??[]);
        $phase1=(new PersonalizedPodOperationsReadModel())->snapshot(50);$phaseCounts=(array)($phase1['counts']??[]);
        $renders=(new RenderEvidenceOperationsReadModel())->snapshot(50);$renderCounts=(array)($renders['counts']??[]);
        echo '<section class="df-panel"><div class="df-panel-head"><div><h2>Buyer-specific artwork & mockup review</h2><p>Certified render evidence binds the approved personalization, provider mapping and production template. Human visual review is required before an authorization package can be created.</p></div><span class="df-status">HUMAN GATE</span></div>';
        if(($renders['query_state']??'UNAVAILABLE')!=='AVAILABLE'){echo '<div class="df-notice df-notice-error">Render evidence is unavailable. DigiForge does not infer that artwork or mockups are approved.</div>';}else{echo '<div class="df-signal-grid"><div><span>Render evidence</span><b>'.esc_html((string)($renderCounts['renders']??0)).'</b></div><div><span>Awaiting visual review</span><b>'.esc_html((string)($renderCounts['unreviewed']??0)).'</b></div><div><span>Human approved</span><b>'.esc_html((string)($renderCounts['approved']??0)).'</b></div><div><span>Provider execution authority</span><b>NO</b></div></div>';$renderItems=(array)($renders['items']??[]);if($renderItems===[]){echo '<div class="df-empty">No buyer-specific render evidence in the bounded view.</div>';}else{echo '<div class="df-table-wrap" role="region" tabindex="0" aria-label="Scrollable evidence table"><table class="df-table"><thead><tr><th>Render</th><th>Order</th><th>Template</th><th>Mode</th><th>Review</th><th>Action</th></tr></thead><tbody>';foreach($renderItems as $item){echo '<tr><td>#'.esc_html((string)$item['render_evidence_id']).'</td><td>#'.esc_html((string)$item['order_id']).'</td><td>'.esc_html((string)$item['template_key'].' @ '.(string)$item['template_version']).'</td><td>'.esc_html((string)$item['render_mode']).'</td><td>'.esc_html((string)$item['review_status']).'</td><td>';echo '<a class="df-button" href="'.esc_url(add_query_arg(['df_shop'=>'personalized_pod','df_render_id'=>(int)$item['render_evidence_id']],$this->url('pod_personalized'))).'">Inspect stored artifacts</a> ';if(($item['review_status']??'')==='UNREVIEWED'){echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="df-inline-form"><input type="hidden" name="action" value="'.esc_attr(self::POD_RENDER_REVIEW_ACTION).'"><input type="hidden" name="render_evidence_id" value="'.esc_attr((string)$item['render_evidence_id']).'">';wp_nonce_field(self::POD_RENDER_REVIEW_ACTION);$this->confirmationFields(self::POD_RENDER_REVIEW_ACTION,(int)$item['render_evidence_id']);echo '<button class="button" type="submit">Approve visual render</button></form>';}else echo 'Reviewed';echo '</td></tr>';}echo '</tbody></table></div>';}}echo '<p class="df-muted">Visual approval records evidence only. It cannot create a production permit or submit a provider order.</p></section>';
        echo '<section class="df-panel"><div class="df-panel-head"><div><h2>Phase-1 Personalized POD operations</h2><p>Human-approved authorization packages and current Printify preflight evidence. This view is operational evidence only: it cannot issue a permit, submit production, retry a provider call or infer approval.</p></div><span class="df-status">PHASE 1 · EXTERNALLY LOCKED</span></div>';
        if(($phase1['query_state']??'UNAVAILABLE')!=='AVAILABLE'){echo '<div class="df-notice df-notice-error">Personalized POD operating evidence is unavailable. Database read failed; an empty or safe queue is not inferred.</div>';}
        else{
            echo '<div class="df-signal-grid"><div><span>Packages in bounded view</span><b>'.esc_html((string)($phaseCounts['packages']??0)).'</b></div><div><span>Human review required</span><b>'.esc_html((string)($phaseCounts['human_review_required']??0)).'</b></div><div><span>Preflight current</span><b>'.esc_html((string)($phaseCounts['preflight_current']??0)).'</b></div><div><span>Revalidation required</span><b>'.esc_html((string)($phaseCounts['revalidation_required']??0)).'</b></div><div><span>Evaluation errors</span><b>'.esc_html((string)($phaseCounts['evaluation_errors']??0)).'</b></div><div><span>Provider execution authority</span><b>NO</b></div></div>';
            $phaseItems=(array)($phase1['items']??[]);
            if($phaseItems===[]){echo '<div class="df-empty">No Personalized POD authorization packages in the bounded operating view.</div>';}
            else{echo '<div class="df-table-wrap" role="region" tabindex="0" aria-label="Scrollable evidence table"><table class="df-table"><thead><tr><th>Package</th><th>Order</th><th>Package state</th><th>Operator state</th><th>Provider mapping</th><th>Blockers</th><th>Retry</th><th>Execution</th></tr></thead><tbody>';foreach($phaseItems as $item){$blockers=(array)($item['blockers']??[]);echo '<tr><td>#'.esc_html((string)$item['package_id']).'</td><td>#'.esc_html((string)$item['order_id']).'</td><td>'.esc_html((string)$item['package_state']).'</td><td>'.esc_html((string)$item['operator_state']).'</td><td>#'.esc_html((string)$item['provider_mapping_id']).'</td><td>'.esc_html($blockers===[]?'NONE':implode(', ',$blockers)).'</td><td>NO</td><td>LOCKED';if(($item['package_state']??'')==='REVIEW_REQUIRED'){echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="df-inline-form"><input type="hidden" name="action" value="'.esc_attr(self::POD_PACKAGE_REVIEW_ACTION).'"><input type="hidden" name="package_id" value="'.esc_attr((string)$item['package_id']).'">';wp_nonce_field(self::POD_PACKAGE_REVIEW_ACTION);$this->confirmationFields(self::POD_PACKAGE_REVIEW_ACTION,(int)$item['package_id']);echo '<button class="button" type="submit">Record human package review</button></form>';}echo '</td></tr>';}echo '</tbody></table></div>';}
        }
        echo '<p class="df-muted">PREFLIGHT_CURRENT means the already human-approved package still matches current order readiness, approved Printify mapping/geometry, validated production template and active business ownership. It does not authorize production. STOP ALL and the external safety lock continue to outrank this evidence.</p></section>';
        echo '<section class="df-panel"><div class="df-panel-head"><div><h2>Master 500 v2 ingestion readiness</h2><p>Preflight evidence for a separately validated migration-candidate ingestion. This panel does not upload, import, promote or publish anything.</p></div><span class="df-status">'.esc_html((string)($v2Ingestion['ingestion_readiness']??'BLOCKED')).'</span></div>';
        if(($v2Ingestion['query_state']??'UNAVAILABLE')!=='AVAILABLE'){echo '<div class="df-notice df-notice-error">Exact immutable v1 parent evidence is unavailable. V2 ingestion readiness is BLOCKED and is not inferred.</div>';}else{echo '<div class="df-signal-grid"><div><span>V1 parent</span><b>#'.esc_html((string)($v2Ingestion['parent_version_id']??0)).'</b></div><div><span>Expected v2 rows</span><b>'.esc_html((string)($v2Ingestion['expected_rows']??0)).'</b></div><div><span>Production authority</span><b>NO</b></div><div><span>Promotion authorized</span><b>NO</b></div></div><p class="df-muted">Governed v2 source SHA256: '.esc_html((string)($v2Ingestion['v2_source_sha256']??'')).'. READY_FOR_VALIDATED_INPUT means exact parent evidence is present and no persisted v2 candidate was found. ALREADY_PERSISTED means the stored v2 source, parent, row count, fingerprint and migration-lineage evidence match the governed candidate. BLOCKED with EXISTING_V2_EVIDENCE_CONFLICT requires reconciliation before any ingestion retry.</p>';}echo '<p class="df-muted">No raw upload or automatic ingestion action is exposed here. A migration candidate never replaces v1 automatically and never grants Etsy publish or POD production authority.</p>';if(($v2Ingestion['query_state']??'UNAVAILABLE')==='AVAILABLE'&&($v2Ingestion['ingestion_readiness']??'')==='ALREADY_PERSISTED'&&(int)($v2Ingestion['v2_version_id']??0)>0){$acceptance=(new MasterCatalogV2AcceptanceRepository())->current((int)$v2Ingestion['v2_version_id']);if(is_wp_error($acceptance)){echo '<div class="df-notice df-notice-error">Immutable Master 500 acceptance evidence is unavailable or inconsistent. Review actions are blocked until reconciliation.</div>';}elseif(($acceptance['decision_state']??'PENDING')==='RECORDED'){echo '<div class="df-signal-grid"><div><span>Human migration decision</span><b>'.esc_html((string)$acceptance['decision']).'</b></div><div><span>Reviewer</span><b>#'.esc_html((string)$acceptance['reviewer_id']).'</b></div><div><span>Reviewed</span><b>'.esc_html((string)$acceptance['reviewed_at']).'</b></div><div><span>Promotion authority</span><b>NO</b></div></div><p class="df-muted">Immutable acknowledgement: '.esc_html((string)$acceptance['acknowledgement_hash']).'. The decision is already recorded, so conflicting ACCEPT/REJECT controls are suppressed. It does not promote the candidate or grant production/external authority.</p>';}else{echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="df-inline-form"><input type="hidden" name="action" value="'.esc_attr(self::MASTER500_ACCEPTANCE_ACTION).'"><input type="hidden" name="catalog_version_id" value="'.esc_attr((string)$v2Ingestion['v2_version_id']).'">';wp_nonce_field(self::MASTER500_ACCEPTANCE_ACTION);$this->confirmationFields(self::MASTER500_ACCEPTANCE_ACTION,(int)$v2Ingestion['v2_version_id']);echo '<button class="df-button" name="decision" value="ACCEPT" type="submit">Accept exact migration evidence</button> <button class="df-button" name="decision" value="REJECT" type="submit">Reject exact migration evidence</button></form><p class="df-muted">This records immutable local human review only. ACCEPT does not promote the candidate or grant production/external authority.</p>';}}echo '</section>';
        echo '<section class="df-panel"><div class="df-panel-head"><div><h2>Master 500 governed source</h2><p>Immutable Personalized POD reference. Catalog data is evidence, not production authority.</p></div><span class="df-status">'.esc_html((string)$m['source_state']).'</span></div>';
        echo '<div class="df-signal-grid"><div><span>Catalog</span><b>'.esc_html((string)$m['catalog_key']).'</b></div><div><span>Concepts</span><b>'.esc_html((string)$m['listing_count']).'</b></div><div><span>Engines</span><b>'.esc_html((string)$m['personalization_engine_count']).'</b></div><div><span>Production authority</span><b>NO</b></div></div></section>';
        if(($ops['query_state']['catalog']??'UNAVAILABLE')!=='AVAILABLE')echo '<section class="df-panel"><div class="df-notice df-notice-error">Governed catalog evidence unavailable. Database read failed; missing catalog evidence is not inferred.</div></section>';elseif($catalog!==[])echo '<section class="df-panel"><div class="df-panel-head"><div><h2>Governed catalog version</h2><p>Persisted provenance and migration lineage. Visibility does not grant production authority.</p></div><span class="df-status">'.esc_html((string)($catalog['source_state']??'UNKNOWN')).'</span></div><div class="df-signal-grid"><div><span>Version</span><b>'.esc_html((string)($catalog['version_label']??'' )).'</b></div><div><span>Rows</span><b>'.esc_html((string)($catalog['row_count']??0)).'</b></div><div><span>Parent version</span><b>'.esc_html((string)($catalog['parent_version_id']??0)).'</b></div><div><span>Authority</span><b>NO</b></div><div><span>Migration candidate</span><b>'.(!empty($catalog['is_migration_candidate'])?'YES':'NO').'</b></div><div><span>Promotion authorized</span><b>NO</b></div><div><span>Lineage evidence</span><b>'.(!empty($catalog['migration_metadata_valid'])?'VERIFIED FORMAT':'UNAVAILABLE').'</b></div></div><p class="df-muted">Source SHA256: '.esc_html((string)($catalog['source_sha256']??'')).' · Fingerprint: '.esc_html((string)($catalog['fingerprint']??'')).'</p><p class="df-muted">A persisted migration candidate never replaces the immutable v1 baseline automatically and never grants Etsy publication or POD provider execution authority.</p></section>';
    }

    private function topbar(string $view): void
    {
        $readiness = $this->allShopsAuthorized?(new Readiness())->report():['status'=>'SHOP SCOPE'];
        $stopAll = Settings::get('stop_all', true) === true;
        $shop=ShopOperationsReadModel::normalize(isset($_GET['df_shop'])?sanitize_key(wp_unslash($_GET['df_shop'])):ShopOperationsReadModel::ALL);
        ?>
        <header class="df-topbar">
            <div>
                <p class="df-eyebrow">DigiCraftify automation platform</p>
                <h1><?php echo esc_html(self::NAV[$view]['label']); ?></h1>
            </div>
            <div class="df-topbar-status">
                <form method="get" class="df-shop-selector"><input type="hidden" name="df_view" value="<?php echo esc_attr($view); ?>"><label>Shop <select name="df_shop" onchange="this.form.submit()"><?php foreach(ShopOperationsReadModel::shops() as $key=>$label): if(($key==='all'&&!$this->allShopsAuthorized)||($key!=='all'&&!in_array($key,$this->authorizedShops,true)))continue; ?><option value="<?php echo esc_attr($key); ?>" <?php selected($shop,$key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label></form>
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
        $attentionSummary=(new AttentionReadModel())->summary();
        $attentionUnavailable=($attentionSummary['query_state']??'PARTIAL_UNAVAILABLE')!=='AVAILABLE';
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
        echo '<section class="df-panel"><div class="df-panel-head"><div><h2>Current shop scope</h2><p>Dashboard counts remain authoritative global counts unless explicitly labeled shop-scoped.</p></div><span class="df-status">READ ONLY</span></div><div class="df-signal-grid"><div><span>Scope</span><b>'.esc_html((string)$shopOps['shop_label']).'</b></div><div><span>AI policies in scope</span><b>'.esc_html(($shopOps['query_state']['ai_policies']??'UNAVAILABLE')==='AVAILABLE'?(string)count((array)$shopOps['ai_policies']):'UNAVAILABLE').'</b></div><div><span>AI usage groups in scope</span><b>'.esc_html(($shopOps['query_state']['ai_usage']??'UNAVAILABLE')==='AVAILABLE'?(string)count((array)$shopOps['ai_usage']):'UNAVAILABLE').'</b></div><div><span>External execution</span><b>NO</b></div></div></section>';
        echo '<section class="df-panel"><div class="df-panel-head"><div><h2>Stage-F operator pulse</h2><p>Action-oriented acceptance summary across human gates and operational exceptions. UNKNOWN or unavailable evidence is never treated as clear.</p></div><span class="df-status">READ ONLY</span></div><div class="df-signal-grid"><div><span>Attention evidence</span><b>'.esc_html($attentionUnavailable?'UNAVAILABLE':'AVAILABLE').'</b></div><div><span>Orders needing reconciliation</span><b>'.esc_html($attentionSummary['orders_needing_reconciliation']===null?'UNAVAILABLE':(string)$attentionSummary['orders_needing_reconciliation']).'</b></div><div><span>Fulfillment decisions</span><b>'.esc_html($attentionSummary['fulfillment_decisions']===null?'UNAVAILABLE':(string)$attentionSummary['fulfillment_decisions']).'</b></div><div><span>Open alerts</span><b>'.esc_html($attentionSummary['open_operational_alerts']===null?'UNAVAILABLE':(string)$attentionSummary['open_operational_alerts']).'</b></div><div><span>External execution authority</span><b>NO</b></div></div>'.($attentionUnavailable?'<div class="df-notice df-notice-error">One or more operator evidence sources are unavailable. Stage-F operational readiness is not inferred.</div>':'').'<div class="df-actions"><a class="df-button" href="'.esc_url($this->url('approvals')).'">Open approvals</a><a class="df-button" href="'.esc_url($this->url('attention')).'">Open attention</a><a class="df-button" href="'.esc_url($this->url('audit')).'">Open reconciliation</a></div></section>';
        echo '<section class="df-card-grid">';
        foreach ($cards as $label => $value) {
            echo '<article class="df-stat-card"><span>' . esc_html($label) . '</span><strong>'
                . esc_html($value === null ? 'UNAVAILABLE' : (string) $value) . '</strong></article>';
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
        $this->globalQueueHealth();
    }

    /** Blueprint table22/r2: global read-only queue evidence, never a retry permit. */
    private function globalQueueHealth(): void
    {
        $queue=(array)((new \DigiForge\Observability\HealthMonitor())->snapshot()['queue']??[]);
        $recovery=(array)($queue['recovery']??[]);
        $available=!empty($queue['query_ok'])&&!empty($recovery['query_ok']);
        $attention=(int)($recovery['attention_total']??0);
        $expired=(int)($queue['expired_leases']??0);
        $state=!$available?'UNAVAILABLE':(($attention>0||$expired>0)?'ATTENTION REQUIRED':'NO CURRENT ATTENTION');
        echo '<section class="df-panel"><div class="df-panel-head"><div><h2>Global queue health</h2><p>Global evidence across all four shops. Read-only visibility requires explicit assignments for every shop.</p></div><span class="df-status">READ ONLY</span></div><div class="df-signal-grid">';
        $metrics=['Queue state'=>$state,'Attention records'=>$available?(string)$attention:'UNAVAILABLE','Expired leases'=>$available?(string)$expired:'UNAVAILABLE','Dead letters'=>$available?(string)($queue['dead_letters']??0):'UNAVAILABLE'];
        foreach($metrics as $label=>$value)echo '<div><span>'.esc_html($label).'</span><b>'.esc_html($value).'</b></div>';
        echo '</div><a class="df-button" href="'.esc_url($this->url('automation')).'">Inspect queue evidence</a><p class="df-muted">Retry, replay, recovery execution and external authority remain NO.</p></section>';
    }

    private function attention(): void
    {
        global $wpdb;
        $attention=(new AttentionReadModel())->summary();
        $preflight = (new ResearchActivationPreflight())->report();$health=(new \DigiForge\Observability\HealthMonitor())->snapshot();$queueRecovery=(array)($health['queue']['recovery']??[]);$readiness=(new Readiness())->report();
        $blockers = array_values(array_filter((array) ($preflight['blockers'] ?? []), 'is_scalar'));
        $wpdb->last_error='';
        $alerts = $wpdb->get_results(
            'SELECT id,environment,alert_type,source_type,source_id,severity,state,created_at,updated_at '
            . 'FROM ' . Tables::operational_alerts()
            . " WHERE state NOT IN ('RESOLVED','DISMISSED','SUPERSEDED','CLOSED') ORDER BY FIELD(severity,'CRITICAL','ERROR','WARNING','INFO'), id DESC LIMIT 50",
            ARRAY_A
        );
        $alertsReadFailed=!is_array($alerts)||!empty($wpdb->last_error);
        $alertFocus=isset($_GET['df_alert_id'])?absint(wp_unslash($_GET['df_alert_id'])):0;
        if($alertFocus>0)$wpdb->last_error='';
        $focusedAlert=$alertFocus>0?$wpdb->get_row($wpdb->prepare('SELECT id,environment,alert_type,source_type,source_id,severity,state,created_at,updated_at FROM '.Tables::operational_alerts()." WHERE id=%d AND state NOT IN ('RESOLVED','DISMISSED','SUPERSEDED','CLOSED')",$alertFocus),ARRAY_A):null;
        $focusedAlertReadFailed=$alertFocus>0&&!empty($wpdb->last_error);
        if(!$alertsReadFailed&&!$focusedAlertReadFailed&&is_array($focusedAlert)&&!in_array($alertFocus,array_map('intval',array_column(is_array($alerts)?$alerts:[],'id')),true))$alerts=array_merge([$focusedAlert],is_array($alerts)?$alerts:[]);
        echo '<section class="df-card-grid"><article class="df-stat-card"><span>Observed attention signals</span><strong>'.esc_html($attention['total_attention']===null?'UNAVAILABLE':(string)$attention['total_attention']).'</strong></article><article class="df-stat-card"><span>Personalization reviews</span><strong>'.esc_html($attention['personalization_reviews']===null?'UNAVAILABLE':(string)$attention['personalization_reviews']).'</strong></article><article class="df-stat-card"><span>Fulfillment decisions</span><strong>'.esc_html($attention['fulfillment_decisions']===null?'UNAVAILABLE':(string)$attention['fulfillment_decisions']).'</strong></article><article class="df-stat-card"><span>Open alerts</span><strong>'.esc_html($attention['open_operational_alerts']===null?'UNAVAILABLE':(string)$attention['open_operational_alerts']).'</strong></article><article class="df-stat-card"><span>Orders needing reconciliation</span><strong>'.esc_html($attention['orders_needing_reconciliation']===null?'UNAVAILABLE':(string)$attention['orders_needing_reconciliation']).'</strong></article></section>';
        if(($attention['query_state']??'PARTIAL_UNAVAILABLE')!=='AVAILABLE')echo '<div class="df-notice df-notice-error">Attention totals are unavailable because one or more source reads failed. Review the individual evidence sources; no empty queue is inferred.</div>';
        if(current_user_can('manage_digiforge_research')&&current_user_can('manage_digiforge_products'))echo '<p class="df-muted">Review recorded decisions: <a href="'.esc_url($this->url('approvals').'#df-approval-personalization').'">Personalization approval evidence</a> · <a href="'.esc_url($this->url('approvals').'#df-approval-fulfillment').'">Fulfillment approval evidence</a>. Links do not make a decision or authorize external action.</p>';
        echo '<p class="df-muted">The attention sum includes a bounded integrity evidence window. It is not an exhaustive count of all provenance anomalies; a zero in that window does not prove that none exist. No retry or external execution authority follows from these counts.</p>';
        $integrity=(array)($attention['production_provenance_integrity_projection']??[]);$persistence=(array)($attention['production_permit_persistence_drilldown']??[]);
        $recoveryEvidence=RecoveryEvidence::snapshot();$dbEvidence=(array)($recoveryEvidence['database_backup']??[]);$pkgEvidence=(array)($recoveryEvidence['plugin_package']??[]);$backupHistory=(array)($recoveryEvidence['database_backup_history']??[]);$drillEvidence=(array)($readiness['recovery_drill_evidence']??[]);
        $orchestration=RecoveryOrchestrator::snapshot();
        echo '<section class="df-panel"><div class="df-panel-head"><div><h2>Governed recovery orchestration</h2><p>Create an isolated staging recovery plan from the current verified artifacts. Planning never executes a restore.</p></div><span class="df-status">'.esc_html((string)$orchestration['state']).'</span></div><div class="df-signal-grid"><div><span>Provider capability</span><b>'.(!empty($orchestration['provider_capability_available'])?'AVAILABLE':'REQUIRED').'</b></div><div><span>Target</span><b>'.esc_html((string)($orchestration['target_site_url']?:'NOT PLANNED')).'</b></div><div><span>Database artifact</span><b>'.esc_html((string)($orchestration['database_backup_identifier']?:'NOT BOUND')).'</b></div><div><span>Plugin artifact</span><b>'.esc_html((string)($orchestration['plugin_package_identifier']?:'NOT BOUND')).'</b></div><div><span>Commerce authority</span><b>NO</b></div><div><span>External execution authority</span><b>NO</b></div></div><form class="df-review-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="'.esc_attr(self::RECOVERY_PLAN_ACTION).'">'; wp_nonce_field(self::RECOVERY_PLAN_ACTION); echo '<label>Isolated staging URL <input type="url" name="target_site_url" required placeholder="https://staging.example.com/" value="'.esc_attr((string)$orchestration['target_site_url']).'"></label><label>Recovery operation key <input type="text" name="operation_key" required maxlength="128" value=""></label><label>Backup identity operation key <input type="text" name="backup_identity_operation_key" required maxlength="128" value=""></label><button class="df-button df-button-primary" type="submit">Create recovery plan</button></form><p class="df-muted">The current DigiForge site is rejected as a target. A plan is bound to the current verified database backup and certified plugin package. Execution remains unavailable until a governed hosting-provider adapter is registered, and verified drill evidence is always a separate gate.</p></section>';
        $recoveryOperationKey=(string)($orchestration['operation_key']??'');
        $verificationReceipt=$recoveryOperationKey!==''?RecoveryVerificationEvidence::read($recoveryOperationKey):[];
        $reviewCandidate=$recoveryOperationKey!==''?RecoveryDrillReviewCandidate::build($recoveryOperationKey):[];
        echo '<section class="df-panel"><div class="df-panel-head"><div><h2>Recovery verification & human acceptance</h2><p>Review immutable staging verification evidence. Acceptance records a human recovery-drill decision only; it cannot execute or retry recovery.</p></div><span class="df-status">HUMAN CONTROLLED</span></div>';
        if($recoveryOperationKey===''){echo '<div class="df-empty">Create a governed recovery plan before verification evidence can be reviewed.</div>';}
        elseif(is_wp_error($verificationReceipt)||$verificationReceipt===[]){echo '<div class="df-notice df-notice-error">Immutable staging verification evidence is not available for the current recovery operation. Acceptance is withheld.</div>';}
        elseif(is_wp_error($reviewCandidate)){echo '<div class="df-notice df-notice-error">'.esc_html($reviewCandidate->get_error_message()).' Acceptance is withheld.</div>';}
        else{
            $checks=(array)($verificationReceipt['checks']??[]);
            $blocked=($reviewCandidate['acceptance_blocked']??true)!==false;
            echo '<div class="df-signal-grid"><div><span>Operation</span><b><code>'.esc_html(substr($recoveryOperationKey,0,20)).'…</code></b></div><div><span>Verification evidence</span><b><code>'.esc_html(substr((string)($reviewCandidate['verification_evidence_hash']??''),0,16)).'…</code></b></div><div><span>Database identity</span><b>'.(!empty($checks['database_identity_verified'])?'VERIFIED':'BLOCKED').'</b></div><div><span>Schema</span><b>'.(!empty($checks['schema_current'])?'VERIFIED':'BLOCKED').'</b></div><div><span>Application health</span><b>'.(!empty($checks['health_ok'])?'VERIFIED':'BLOCKED').'</b></div><div><span>Acceptance</span><b>'.($blocked?'WITHHELD':'READY FOR HUMAN REVIEW').'</b></div></div>';
            if($blocked){echo '<div class="df-notice df-notice-error">Acceptance blocked: '.esc_html((string)($reviewCandidate['acceptance_blocker']??'VERIFICATION_INCOMPLETE')).'. No drill PASS can be recorded.</div>';}
            else{echo '<form class="df-review-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="'.esc_attr(self::RECOVERY_ACCEPT_ACTION).'"><input type="hidden" name="operation_key" value="'.esc_attr($recoveryOperationKey).'"><input type="hidden" name="verification_evidence_hash" value="'.esc_attr((string)$reviewCandidate['verification_evidence_hash']).'">';wp_nonce_field(self::RECOVERY_ACCEPT_ACTION);echo '<button class="df-button df-button-primary" type="submit">Accept verified recovery drill</button></form><p class="df-muted">This explicit human action is bound to the exact immutable verification receipt. It records drill evidence only and grants no restore, retry, Etsy, POD, order, tax or other commerce authority.</p>';}
        }
        echo '</section>';
        echo '<section class="df-panel"><div class="df-panel-head"><div><h2>Queue recovery & release safety</h2><p>Queue failures, concrete recovery evidence and release locks are operator-visible. Visibility does not execute recovery.</p></div><span class="df-status">READ ONLY</span></div><div class="df-signal-grid"><div><span>Queue query</span><b>'.(!empty($queueRecovery['query_ok'])?'VERIFIED':'FAILED').'</b></div><div><span>Queue attention</span><b>'.esc_html((string)($queueRecovery['attention_total']??0)).'</b></div><div><span>Recovery drill</span><b>'.esc_html((string)($readiness['recovery']['status']??'UNKNOWN')).'</b></div><div><span>Drill evidence</span><b>'.(!empty($drillEvidence['passed'])?'VERIFIED / FRESH':'MISSING / STALE / MISMATCH').'</b></div><div><span>DB backup evidence</span><b>'.esc_html((string)($recoveryEvidence['database_backup_status']??'MISSING')).'</b></div><div><span>Rollback package evidence</span><b>'.(!empty($recoveryEvidence['plugin_package_retrievable'])&&!empty($recoveryEvidence['plugin_package_identity_recorded'])&&!empty($recoveryEvidence['checksum_verified'])?'VERIFIED':'MISSING / UNPROVEN').'</b></div><div><span>Externally locked</span><b>'.(!empty($readiness['externally_locked'])?'YES':'NO').'</b></div></div><div class="df-table-wrap" role="region" tabindex="0" aria-label="Scrollable evidence table"><table class="df-table"><thead><tr><th>Evidence</th><th>Identifier</th><th>Captured/version</th><th>Source/checksum</th><th>Location</th><th>Retrievable</th></tr></thead><tbody><tr><td>Database backup</td><td>'.esc_html((string)($dbEvidence['identifier']??'NOT RECORDED')).'</td><td>'.esc_html((string)($dbEvidence['captured_at']??'—')).'</td><td>—</td><td>'.esc_html((string)($dbEvidence['location']??'—')).'</td><td>'.(!empty($recoveryEvidence['database_backup_retrievable'])?'VERIFIED / FRESH '.esc_html((string)($dbEvidence['verified_at']??'')):(!empty($dbEvidence['verified_at'])?'STALE / REVERIFY':'NO / UNKNOWN')).'</td></tr><tr><td>Rollback plugin</td><td>'.esc_html((string)($pkgEvidence['identifier']??'NOT RECORDED')).'</td><td>'.esc_html((string)($pkgEvidence['version']??'—')).'</td><td><code>'.esc_html(isset($pkgEvidence['source_commit'])?substr((string)$pkgEvidence['source_commit'],0,12).'…':'—').'</code> / '.(!empty($pkgEvidence['checksum_verified'])?'SHA VERIFIED':'UNVERIFIED').'</td><td>'.esc_html((string)($pkgEvidence['location']??'—')).'</td><td>'.(!empty($pkgEvidence['retrievable'])?'YES':'NO / UNKNOWN').'</td></tr></tbody></table></div><p class="df-muted"><strong>Host backup evidence handoff:</strong> after a real backup is created and independently verified outside DigiForge, registration requires identifier, captured_at, location, verification_method, verified_at, verified_by and retrievable=true. DigiForge records this provenance only; it does not create, retrieve or verify the host backup.</p><p class="df-muted"><strong>Recovery drill evidence handoff:</strong> after a real restore drill is performed, registration requires drill_id, performed_at, environment, database_backup_identifier, plugin_package_identifier, performed_by, plus restore_verified=true, schema_verified=true and application_health_verified=true. The artifact identifiers must exactly match the current verified recovery evidence.</p><p class="df-muted"><strong>Recovery drill evidence:</strong> '.esc_html((string)($drillEvidence['drill_id']??'NOT RECORDED')).'; performed '.esc_html((string)($drillEvidence['performed_at']??'—')).'; environment '.esc_html((string)($drillEvidence['environment']??'—')).'; backup binding '.esc_html((string)($drillEvidence['database_backup_identifier']??'—')).'; plugin binding '.esc_html((string)($drillEvidence['plugin_package_identifier']??'—')).'. Restore/schema/application-health verification must all be true, fresh and bound to the current artifacts. This display cannot perform a drill or authorize external execution.</p><p class="df-muted">Database backup verification expires after 24 hours. STALE / REVERIFY means independently confirm that the same backup is still retrievable and submit fresh verification provenance; do not merely refresh the timestamp. Missing or UNKNOWN artifact evidence is fail-closed. Evidence never grants retry, activation, Etsy publish, POD production or other external execution authority.</p><p><strong>Backup verification history:</strong> '.esc_html((string)count($backupHistory)).' most recent evidence record(s) available read-only; history never grants retry or execution authority.</p></section>';
        echo '<section class="df-panel" id="df-integrity-evidence"><div class="df-panel-head"><div><h2>Production integrity & persistence evidence</h2><p>Audit and reconciliation evidence only. These records never permit retry or external execution.</p></div><span class="df-status">READ ONLY</span></div><div class="df-signal-grid"><div><span>Open integrity in window</span><b>'.esc_html((string)($integrity['open_count']??0)).'</b></div><div><span>Historical integrity in window</span><b>'.esc_html((string)($integrity['historical_count']??0)).'</b></div><div><span>Acknowledged integrity in window</span><b>'.esc_html((string)($integrity['acknowledged_count']??0)).'</b></div><div><span>Persistence UNKNOWN</span><b>'.esc_html((string)($attention['production_permit_persistence_unknown']??0)).'</b></div></div>';
        echo '<p class="df-muted">Integrity categories reflect up to '.esc_html((string)($integrity['window_limit']??200)).' selected evidence records; they are not exhaustive totals. Persistence UNKNOWN is counted independently across recorded observations.</p>';
        $coverage=(array)($attention['production_provenance_integrity_coverage']??[]);
        echo '<p class="df-muted">Integrity candidate coverage: '.esc_html((string)($coverage['coverage_state']??'UNKNOWN')).'. Raw source candidate rows: '.esc_html(isset($coverage['candidate_rows'])?(string)$coverage['candidate_rows']:'UNKNOWN').'; recorded evidence rows: '.esc_html(isset($coverage['recorded_evidence_rows'])?(string)$coverage['recorded_evidence_rows']:'UNKNOWN').'. Candidate rows can overlap and do not equal open anomalies. UNKNOWN and possible truncation require source review; this evidence grants no retry or execution authority.</p>';
        $integrityRows=array_slice((array)($integrity['items']??[]),0,30);
        if($integrityRows!==[]){echo '<p class="df-muted">Up to 30 rows from the bounded integrity window link to exact authorization evidence. This list is not an exhaustive queue.</p><div class="df-table-wrap" role="region" tabindex="0" aria-label="Scrollable evidence table"><table class="df-table"><thead><tr><th>Anomaly</th><th>Authorization evidence</th><th>Window state</th><th>Source</th><th>Retry</th><th>Execution</th></tr></thead><tbody>';foreach($integrityRows as $row){$auth=(string)($row['authorization_hash']??'');$link=$this->integrityEvidenceUrl($auth);$identity='<code>'.esc_html(substr($auth,0,12)).'…</code>';echo '<tr><td>'.esc_html((string)($row['type']??'UNKNOWN')).'</td><td>'.($link!==''?'<a href="'.esc_url($link).'">'.$identity.'</a>':$identity).'</td><td>'.esc_html((string)($row['operator_state']??'UNKNOWN')).'</td><td>'.(!empty($row['historical_evidence'])?'RECORDED':'LIVE LOOKUP').'</td><td>NO</td><td>NO</td></tr>';}echo '</tbody></table></div>';}
        $focusRaw=$_GET['df_integrity_auth']??null;
        $focusHash=is_string($focusRaw)?strtolower(trim(wp_unslash($focusRaw))):'';
        $validFocus=preg_match('/^[a-f0-9]{64}$/',$focusHash)===1;
        echo '<form method="get" action="'.esc_url($this->baseUrl()).'" class="df-review-form"><input type="hidden" name="df_view" value="attention"><label>Inspect live and recorded integrity evidence by exact authorization hash <input type="text" name="df_integrity_auth" maxlength="64" pattern="[a-fA-F0-9]{64}" value="'.esc_attr($validFocus?$focusHash:'').'"></label><button class="df-button df-button-compact" type="submit">Inspect evidence</button></form>';
        if($focusRaw!==null){
            $focus=(new \DigiForge\POD\ProductionProvenanceIntegrityFocusReadModel())->byAuthorizationHash($focusHash);
            echo '<p class="df-muted">Exact live lookup: '.esc_html((string)$focus['lookup_state']).'. Lookup does not acknowledge, retry or execute.</p>';
            if(isset($focus['open_count'],$focus['acknowledged_count']))echo '<p class="df-muted">This authorization only — live open: '.esc_html((string)$focus['open_count']).'; acknowledged: '.esc_html((string)$focus['acknowledged_count']).'. These exact scoped counts do not represent global integrity totals.</p>';
            if(!empty($focus['items'])){echo '<div class="df-table-wrap" role="region" tabindex="0" aria-label="Scrollable evidence table"><table class="df-table"><thead><tr><th>Anomaly</th><th>Authorization</th><th>Correlation</th><th>State</th><th>Retry</th><th>Execution</th></tr></thead><tbody>';foreach($focus['items'] as $row){echo '<tr><td>'.esc_html((string)$row['type']).'</td><td><code>'.esc_html(substr((string)$row['authorization_hash'],0,12)).'…</code></td><td><code>'.esc_html(substr((string)$row['correlation_hash'],0,12)).'…</code></td><td>'.esc_html((string)$row['operator_state']).'</td><td>NO</td><td>NO</td></tr>';}echo '</tbody></table></div>';}
            $liveHashes=in_array($focus['lookup_state'],['LIVE_ANOMALIES','NO_LIVE_ANOMALY'],true)?array_column($focus['items'],'correlation_hash'):null;
            $beforeRaw=$_GET['df_integrity_before']??null;
            $before=0;
            if($beforeRaw!==null){$parsed=is_string($beforeRaw)?filter_var(wp_unslash($beforeRaw),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]):false;$before=$parsed===false?-1:(int)$parsed;}
            $history=(new \DigiForge\POD\ProductionProvenanceIntegrityHistoryReadModel())->byAuthorizationHash($focusHash,$liveHashes,$before);
            echo '<p class="df-muted">Recorded history lookup: '.esc_html((string)$history['lookup_state']).'. Recorded evidence may still be live; use the live lookup above for current state.</p>';
            $turnover=(new \DigiForge\POD\ProductionProvenanceIntegrityTurnoverReadModel())->byAuthorizationHash($focusHash);
            if($turnover['lookup_state']==='RECORDED_SPAN')echo '<p class="df-muted">Recorded observation span for this authorization: '.esc_html((string)$turnover['first_observed_at']).' through '.esc_html((string)$turnover['last_observed_at']).'; '.esc_html((string)$turnover['distinct_anomaly_types']).' distinct recorded anomaly type(s). Observation dates do not establish resolution.</p>';
            elseif($turnover['lookup_state']==='QUERY_UNAVAILABLE')echo '<p class="df-muted">Recorded observation span unavailable; no resolution state is inferred.</p>';
            $typeEvidence=(new \DigiForge\POD\ProductionProvenanceIntegrityTypeReadModel())->byAuthorizationHash($focusHash);
            if(isset($history['recorded_count'],$typeEvidence['recorded_count'])&&$history['recorded_count']!==$typeEvidence['recorded_count'])echo '<p class="df-muted">Recorded evidence changed while this view loaded. Refresh before comparing counts; no resolution or execution authority is inferred.</p>';
            elseif(isset($typeEvidence['type_counts'])){echo '<p class="df-muted">Recorded anomaly types for this authorization only: '.esc_html((string)$typeEvidence['recorded_count']).' exact row(s), including unexpected types in OTHER_RECORDED_TYPE. These counts do not establish live state or resolution.</p><div class="df-table-wrap" role="region" tabindex="0" aria-label="Scrollable evidence table"><table class="df-table"><thead><tr><th>Recorded anomaly type</th><th>Rows</th></tr></thead><tbody>';foreach($typeEvidence['type_counts'] as $type=>$count)echo '<tr><td>'.esc_html((string)$type).'</td><td>'.esc_html((string)$count).'</td></tr>';echo '</tbody></table></div>';}
            elseif(in_array($typeEvidence['lookup_state'],['QUERY_UNAVAILABLE','INCONSISTENT_EVIDENCE'],true))echo '<p class="df-muted">Recorded type breakdown unavailable; no zero count is inferred.</p>';
            if(isset($history['recorded_count']))echo '<p class="df-muted">This authorization only — exact recorded evidence rows: '.esc_html((string)$history['recorded_count']).'. Preview shows up to '.esc_html((string)$history['preview_limit']).' rows per page'.(!empty($history['preview_truncated'])?' (older rows available)':'').'. No global total is implied.</p>';
            if(isset($history['recorded_live_overlap_count'],$history['recorded_without_live_correlation_count']))echo '<p class="df-muted">Recorded correlations also in current live lookup: '.esc_html((string)$history['recorded_live_overlap_count']).'; absent from current live lookup: '.esc_html((string)$history['recorded_without_live_correlation_count']).'. Absence does not prove resolution; these are exact counts for this authorization at lookup time.</p>';
            elseif(isset($history['comparison_state']))echo '<p class="df-muted">Recorded/live comparison unavailable: '.esc_html((string)$history['comparison_state']).'.</p>';
            if(!empty($history['items'])){echo '<div class="df-table-wrap" role="region" tabindex="0" aria-label="Scrollable evidence table"><table class="df-table"><thead><tr><th>Recorded anomaly</th><th>Correlation</th><th>Current live correlation</th><th>First observed</th><th>Last observed</th><th>Retry</th><th>Execution</th></tr></thead><tbody>';foreach($history['items'] as $row){echo '<tr><td>'.esc_html((string)$row['type']).'</td><td><code>'.esc_html(substr((string)$row['correlation_hash'],0,12)).'…</code></td><td>'.esc_html((string)($row['comparison_state']??'UNKNOWN')).'</td><td>'.esc_html((string)$row['first_observed_at']).'</td><td>'.esc_html((string)$row['last_observed_at']).'</td><td>NO</td><td>NO</td></tr>';}echo '</tbody></table></div>';}
            if($before>0)echo '<p><a href="'.esc_url(add_query_arg(['df_integrity_auth'=>$focusHash],$this->url('attention')).'#df-integrity-evidence').'">Newest recorded rows</a></p>';
            if(isset($history['next_cursor'])&&$history['next_cursor']!==null)echo '<p><a href="'.esc_url(add_query_arg(['df_integrity_auth'=>$focusHash,'df_integrity_before'=>(int)$history['next_cursor']],$this->url('attention')).'#df-integrity-evidence').'">Older recorded rows</a></p>';
        }
        if(!empty($persistence['items'])){echo '<div class="df-table-wrap" role="region" tabindex="0" aria-label="Scrollable evidence table"><table class="df-table"><thead><tr><th>Authorization</th><th>Package</th><th>Persistence</th><th>First observed</th><th>Last observed</th><th>Retry</th><th>Execution authority</th></tr></thead><tbody>';foreach((array)$persistence['items'] as $row){$integrityUrl=$this->integrityEvidenceUrl((string)($row['authorization_hash']??''));$identity='<code>'.esc_html(substr((string)$row['authorization_hash'],0,12)).'…</code>';echo '<tr><td>'.($integrityUrl!==''?'<a href="'.esc_url($integrityUrl).'">'.$identity.'</a>':$identity).'</td><td>#'.esc_html((string)$row['package_id']).'</td><td>'.esc_html((string)$row['persistence_state']).'</td><td>'.esc_html((string)$row['first_observed_at']).'</td><td>'.esc_html((string)$row['last_observed_at']).'</td><td>NO</td><td>NO</td></tr>';}echo '</tbody></table></div>';}else{echo '<div class="df-empty">No permit persistence observations.</div>';}echo '</section>';
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
            . '<span>' . esc_html($attention['open_operational_alerts']===null?'UNAVAILABLE':(string)$attention['open_operational_alerts']) . ' open total</span></div>';
        if($alertFocus>0&&!is_array($focusedAlert))echo '<div class="df-notice df-notice-error">Requested operational alert is unavailable or closed. No recovery action is inferred.</div>';
        if ($alertsReadFailed || $focusedAlertReadFailed) {
            echo '<div class="df-notice df-notice-error">Operational alert evidence unavailable. Database read failed; no empty queue is inferred.</div>';
        } elseif ($alerts === []) {
            echo '<div class="df-empty">No open operational alerts.</div>';
        } else {
            echo '<div class="df-table-wrap" role="region" tabindex="0" aria-label="Scrollable evidence table"><table class="df-table"><thead><tr><th>ID</th><th>Environment</th><th>Severity</th><th>Type</th><th>Source evidence</th><th>State</th><th>Created</th></tr></thead><tbody>';
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
        $candidateState=null;$rows = $this->candidates($status, $limit,$candidateState);
        if($candidateState!=='AVAILABLE'){echo '<div class="df-notice df-notice-error">Research candidate evidence unavailable. Database read failed; an empty candidate queue is not inferred.</div>';return;}
        if ($rows === []) {
            echo '<div class="df-empty">No matching research candidates.</div>';
            return;
        }
        foreach ($rows as $row) {
            $signals = $this->decode($row['score_inputs'] ?? '');
            $evidenceState='AVAILABLE';$evidence = $details ? $this->evidence((int) $row['id'],$evidenceState) : [];
            if($details&&$evidenceState!=='AVAILABLE'){echo '<div class="df-notice df-notice-error">Candidate evidence unavailable for #'.esc_html((string)$row['id']).'. No empty evidence set is inferred.</div>';}
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

    private function draftQaReviewForms(?string $shop):void
    {
        global $wpdb;$scope=$shop===null?'':$wpdb->prepare(' AND BINARY l.shop_reference=BINARY %s',$shop);$wpdb->last_error='';$rows=$wpdb->get_results('SELECT op.id FROM '.$wpdb->prefix.'digiforge_etsy_operations op INNER JOIN '.Tables::etsy_draft_packages().' pkg ON pkg.id=op.draft_package_id INNER JOIN '.Tables::listings()." l ON l.id=pkg.listing_id WHERE op.operation_type='CREATE_DRAFT' AND op.state='CONFIRMED_SUCCESS'".$scope.' ORDER BY op.id DESC LIMIT 20',ARRAY_A);
        echo '<section class="df-panel"><h2>Human draft QA before publish authorization</h2><p>These seven assertions record the authenticated reviewer\'s judgment. They do not certify commercial, policy, artwork or legal truth, and never publish a listing.</p>';
        if($this->draftQaPreview!==null){$context=$this->draftQaPreview['context'];$params=$this->draftQaPreview['parameters'];echo '<h3>Confirm the exact draft and chosen assertions</h3><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="'.esc_attr(self::DRAFT_QA_REVIEW_ACTION).'">';wp_nonce_field(self::DRAFT_QA_REVIEW_ACTION);foreach(['create_operation_id','intent_id','draft_package_id','objective_evidence_hash']as $field)echo '<input type="hidden" name="'.esc_attr($field).'" value="'.esc_attr((string)$context[$field]).'">';foreach($params['checks']as $check=>$status)echo '<input type="hidden" name="checks['.esc_attr($check).']" value="'.esc_attr($status).'">';$this->confirmationFields(self::DRAFT_QA_REVIEW_ACTION,$context['create_operation_id'],[$params]);echo '<button class="df-button" type="submit">Record confirmed local draft QA</button></form>';}
        elseif(!is_array($rows)||!empty($wpdb->last_error))echo '<div class="df-notice df-notice-error">Confirmed draft evidence unavailable. No review can be prepared.</div>';
        elseif($rows===[])echo '<div class="df-empty">No confirmed drafts in this selected shop window.</div>';
        else foreach($rows as $row){$context=(new \DigiForge\Listings\DraftQaReviewRepository())->inspect((int)$row['id']);if(is_wp_error($context)){echo '<p class="df-muted">Draft operation #'.esc_html((string)$row['id']).': current objective evidence unavailable or incomplete. Review is blocked.</p>';continue;}echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><h3>Listing #'.esc_html((string)$context['listing_id']).' · '.esc_html($context['listing_title']).'</h3><p>Shop '.esc_html($context['shop_key']).' / '.esc_html($context['environment']).' · Confirmed draft #'.esc_html((string)$context['create_operation_id']).' · Package #'.esc_html((string)$context['draft_package_id']).'</p><input type="hidden" name="action" value="'.esc_attr(self::DRAFT_QA_PREVIEW_ACTION).'"><input type="hidden" name="create_operation_id" value="'.esc_attr((string)$context['create_operation_id']).'"><input type="hidden" name="shop_key" value="'.esc_attr($context['shop_key']).'">';wp_nonce_field(self::DRAFT_QA_PREVIEW_ACTION);foreach(['title','description','seo','media','pricing','policy','personalization']as $check){echo '<label>'.esc_html(ucfirst($check)).' human review <select name="checks['.esc_attr($check).']" required><option value="">Choose assertion</option><option value="PASS">PASS</option><option value="WAIVED">WAIVED</option><option value="FAIL">FAIL</option></select></label> ';}echo '<p><button class="df-button" type="submit">Review exact assertions before recording</button></p></form>';}
        echo '</section>';
    }

    private function requireConfirmation(string $action,int $id,string $view):void
    {
        $result=ReviewConfirmation::accept($action,$id,wp_unslash($_POST));
        if(is_wp_error($result))$this->redirect($view,$result->get_error_message(),true);
    }

    public function confirmationFields(string $action,int $id,?array $parameterSets=null):void
    {
        $definition=ReviewConfirmation::definition($action);if($definition===null)return;
        $selected=ShopOperationsReadModel::normalize(isset($_GET['df_shop'])?sanitize_key(wp_unslash($_GET['df_shop'])):ShopOperationsReadModel::ALL);
        $choices=$definition[5]!==[]?$definition[5]:($action===self::DEVELOP_ACTION?['digital','goods']:['REVIEW']);$contexts=[];$proof=[];
        foreach($choices as $choice){$params=$parameterSets[0]??($definition[5]!==[]?['decision'=>$choice]:($action===self::DEVELOP_ACTION?['destination_shop'=>$choice]:[]));$context=ReviewConfirmation::inspect($action,$id,$selected,$params,$proof);if(!is_wp_error($context)){if($proof===[])$proof=['confirmation_nonce'=>$context['confirmation_nonce'],'issued_at'=>$context['issued_at']];$contexts[$choice]=$context;}}
        if($contexts===[]){echo '<div class="df-notice df-notice-error">Current target, shop or evidence cannot be confirmed. This review is blocked.</div>';return;}
        $context=reset($contexts);$identity='df-confirm-'.sanitize_key($action).'-'.$id;
        echo '<fieldset class="df-confirmation" aria-describedby="'.esc_attr($identity.'-effect').'"><legend>Confirm exact local review</legend><p><strong>'.esc_html($context['target_label']).'</strong><br>Shop: '.esc_html($context['shop']).' · Environment: '.esc_html($context['environment']).'</p><ul>';foreach($context['summary']as $line)echo '<li>'.esc_html($line).'</li>';echo '</ul><p>Current evidence SHA-256 <code>'.esc_html($context['evidence_hash']).'</code></p><p id="'.esc_attr($identity.'-effect').'">'.esc_html($context['effect']).'</p><input type="hidden" name="df_confirmation_shop" value="'.esc_attr($context['shop']).'"><input type="hidden" name="df_confirmation_nonce" value="'.esc_attr($context['confirmation_nonce']).'"><input type="hidden" name="df_confirmation_issued_at" value="'.esc_attr((string)$context['issued_at']).'">';
        foreach($contexts as $choice=>$bound)echo '<input type="hidden" name="df_confirmation_tokens['.esc_attr($choice).']" value="'.esc_attr($bound['token']).'">';
        echo '<label for="'.esc_attr($identity).'"><input type="checkbox" name="df_confirm_review" id="'.esc_attr($identity).'" value="1" required> I reviewed this exact target and current evidence and confirm my selected local decision.</label></fieldset>';
    }

    private function reviewForm(int $id): void
    {
        ?>
        <form class="df-review-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::REVIEW_ACTION); ?>">
            <input type="hidden" name="candidate_id" value="<?php echo esc_attr((string) $id); ?>">
            <?php wp_nonce_field(self::REVIEW_ACTION);$this->confirmationFields(self::REVIEW_ACTION,$id); ?>
            <textarea aria-label="Optional review notes" name="notes" rows="2" placeholder="Optional review notes"></textarea>
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
            <?php wp_nonce_field(self::DEVELOP_ACTION);$this->confirmationFields(self::DEVELOP_ACTION,$id); ?>
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
            . '<div><span>Current personalized lane</span><b>PHASE 1 · OPERATIONAL / EXTERNALLY LOCKED</b></div>'
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
            . '<p>Credentials are never displayed in this portal. Readiness is derived from stored integration evidence only; opening this page never performs a provider connectivity test. A stored successful test does not verify current connectivity or authorize external actions.</p></div><span class="df-status">READ ONLY</span></div>';
        if($integrationReadiness['query_state']==='UNAVAILABLE')echo '<p class="df-muted">Integration evidence query unavailable. Connector counts are UNKNOWN; no readiness is inferred.</p>';
        else echo '<div class="df-signal-grid"><div><span>Total connectors</span><b>'.esc_html((string)$integrationReadiness['counts']['total']).'</b></div><div><span>Enabled</span><b>'.esc_html((string)$integrationReadiness['counts']['enabled']).'</b></div><div><span>Configured status</span><b>'.esc_html((string)$integrationReadiness['counts']['configured']).'</b></div><div><span>Stored successful tests</span><b>'.esc_html((string)$integrationReadiness['counts']['stored_successful_tests']).'</b></div><div><span>Needs attention</span><b>'.esc_html((string)$integrationReadiness['counts']['attention']).'</b></div><div><span>External execution authority</span><b>NO</b></div></div>';
        echo '<div class="df-integration-grid">';
        foreach (is_array($rows) ? $rows : [] as $row) {
            echo '<article class="df-integration-card"><h3>' . esc_html((string) $row['display_name']) . '</h3>'
                . '<dl class="df-kv">'
                . '<div><dt>Status</dt><dd>' . esc_html((string) $row['status']) . '</dd></div>'
                . '<div><dt>Evidence</dt><dd>' . esc_html((string) $row['evidence_state']) . '</dd></div>'
                . '<div><dt>Review reason</dt><dd>' . esc_html((string) $row['attention_reason']) . '</dd></div>'
                . '<div><dt>Last stored successful test</dt><dd>' . esc_html((string) ($row['last_stored_test_at']??'—')) . '</dd></div>'
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
        if(!$this->allShopsAuthorized&&$this->evidenceScopeFilter($table)===''){echo '<section class="df-panel"><h2>'.esc_html($label).'</h2><p>Global evidence requires assignments for all four shops.</p></section>';return;}
        $count=$this->count($table,$this->evidenceScopeFilter($table));
        echo '<section class="df-panel"><div class="df-panel-head"><h2>' . esc_html($label) . '</h2><span>'
            . esc_html($count===null?'UNAVAILABLE':(string)$count.' records') . '</span></div>';
        $this->table($table);
        echo '</section>';
    }

    private function table(string $table): void
    {
        global $wpdb;
        $wpdb->last_error='';
        $scope=$this->evidenceScopeFilter($table);
        $where=$scope===''?'':' WHERE '.$scope;
        $rows = $wpdb->get_results("SELECT * FROM {$table}{$where} ORDER BY id DESC LIMIT 50", ARRAY_A);
        if(!is_array($rows)||$wpdb->last_error!==''){
            echo '<div class="df-notice df-notice-error">Evidence unavailable. Source records could not be read; an empty queue or completed review is not inferred.</div>';
            return;
        }
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
            $wpdb->last_error='';
            $record = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d".($scope===''?'':' AND '.$scope), $focusId), ARRAY_A);
            if($wpdb->last_error!=='')$record=null;
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
        if ($table === Tables::audit_log()) $columns = ['id','event_type','actor_id','object_type','object_id','created_at'];
        if ($table === Tables::digital_download_checks()) $columns[] = 'qa_evidence';
        echo '<div class="df-table-wrap" role="region" tabindex="0" aria-label="Scrollable evidence table"><table class="df-table"><thead><tr>';
        foreach ($columns as $column) {
            echo '<th>' . esc_html(ucwords(str_replace('_', ' ', (string) $column))) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $anchor = $focused && (int) ($row['id'] ?? 0) === $focusId ? ' id="df-evidence-' . esc_attr($focusType . '-' . $focusId) . '"' : '';
            echo '<tr' . $anchor . '>';
            foreach ($columns as $column) {
                $value = $column === 'qa_evidence'
                    ? $this->digitalQaEvidenceSummary($row['details'] ?? null)
                    : $this->cell((string) $column, $row[$column] ?? '');
                echo '<td>' . esc_html($value) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    /** Blueprint p77/table23/r6/c3: bounded recorded QA proof, never arbitrary details or readiness. */
    private function digitalQaEvidenceSummary(mixed $value): string
    {
        $unverified = 'UNVERIFIED: structured QA/provenance evidence is missing or unsupported.';
        if (!is_string($value) || strlen($value) > 65536) return $unverified;
        $details = json_decode($value, true, 12);
        if (!is_array($details)) return $unverified;
        $format = $details['format'] ?? null;
        $hash = $details['checksum_sha256'] ?? null;
        $bytes = $details['byte_size'] ?? null;
        $checks = $details['checks'] ?? null;
        if (!is_string($format) || !in_array($format, ['pdf','svg','html','json','csv','txt','zip','png'], true)
            || !is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1
            || !is_int($bytes) || $bytes < 1 || !is_array($checks) || !array_is_list($checks)
            || count($checks) < 1 || count($checks) > 24) return $unverified;
        $allowed = ['file_exists','file_nonempty','checksum_match','filename_safe','extension_match',
            'format_integrity','credential_leak_scan','pdf_page_count','pdf_media_box',
            'pdf_duplicate_page_scan','pdf_link_qr_map_scan','png_dimensions','svg_dimensions',
            'svg_active_content_scan','html_active_content_scan','zip_safe_members',
            'zip_unique_members','zip_member_inventory'];
        $summary = [];
        $seen = [];
        foreach ($checks as $check) {
            if (!is_array($check) || !is_string($check['name'] ?? null)
                || !in_array($check['name'], $allowed, true) || !is_bool($check['passed'] ?? null)
                || isset($seen[$check['name']])) return $unverified;
            $seen[$check['name']] = true;
            $summary[] = $check['name'].': '.($check['passed'] ? 'PASS' : 'FAIL');
        }
        return 'Recorded '.strtoupper($format).' QA; SHA-256 '.$hash.'; '.$bytes.' bytes; '
            .implode('; ', $summary).'. Readiness not inferred.';
    }

    /** Selected-shop evidence uses stored lineage, including focused records and counts. */
    private function evidenceScopeFilter(string $table): string
    {
        global $wpdb;
        $shop=ShopOperationsReadModel::normalize(isset($_GET['df_shop'])?sanitize_key(wp_unslash($_GET['df_shop'])):ShopOperationsReadModel::ALL);
        if($shop===ShopOperationsReadModel::ALL)return '';
        if($table===Tables::audit_log()){
            $subjects=['research_source'=>Tables::research_sources(),'research_observation'=>Tables::research_observations(),'research_evidence'=>Tables::research_evidence(),'research_candidate'=>Tables::research_candidates(),'opportunity'=>Tables::opportunities(),'product_family'=>Tables::product_families(),'product'=>Tables::products(),'product_version'=>Tables::product_versions(),'digital_product'=>Tables::digital_products(),'digital_file'=>Tables::digital_files(),'asset_spec'=>Tables::asset_specs(),'production_plan'=>Tables::production_plans(),'release_bundle'=>Tables::release_bundles(),'listing'=>Tables::listings(),'order'=>Tables::orders(),'fulfillment_plan'=>Tables::fulfillment_plans(),'personalization_submission'=>Tables::personalization_submissions()];$parts=[];
            foreach($subjects as $type=>$subject){$scope=$this->evidenceScopeFilter($subject);if($scope!=='')$parts[]=$wpdb->prepare('('.$table.'.object_type=%s AND EXISTS (SELECT 1 FROM '.$subject.' WHERE BINARY CAST('.$subject.'.id AS CHAR)=BINARY '.$table.'.object_id AND '.$scope.'))',$type);}
            $ops=$wpdb->prefix.'digiforge_etsy_operations';$parts[]=$wpdb->prepare('('.$table.'.object_type=%s AND EXISTS (SELECT 1 FROM '.$ops.' audit_op INNER JOIN '.Tables::etsy_draft_packages().' audit_pkg ON audit_pkg.id=audit_op.draft_package_id INNER JOIN '.Tables::listings().' audit_listing ON audit_listing.id=audit_pkg.listing_id WHERE BINARY CAST(audit_op.id AS CHAR)=BINARY '.$table.'.object_id AND BINARY audit_listing.shop_reference=BINARY %s AND '.\DigiForge\Listings\EtsyStoredShopScope::matches($wpdb->prepare('%s',$shop),'audit_op.shop_reference','audit_listing.environment').'))','etsy_operation',$shop);
            return '('.implode(' OR ',$parts).')';
        }
        $research=(new ResearchRepository())->portalScopeFilter($table,$shop);
        if($research!==null)return $research;
        $product=ProductEvidenceScope::forTable($table,$shop);
        if($product!==null)return $product;
        if($table===Tables::listings())return $wpdb->prepare('BINARY shop_reference=BINARY %s',$shop);
        if(in_array($table,[Tables::listing_seo(),Tables::listing_media(),Tables::listing_pod_bindings(),Tables::etsy_draft_packages(),Tables::etsy_intents(),Tables::listing_readiness_reviews()],true))return $wpdb->prepare('EXISTS (SELECT 1 FROM '.Tables::listings().' l WHERE l.id='.$table.'.listing_id AND BINARY l.shop_reference=BINARY %s)',$shop);
        $owner=\DigiForge\Listings\EtsyStoredShopScope::orderPredicate($shop,'o');
        if($table===Tables::orders())return \DigiForge\Listings\EtsyStoredShopScope::orderPredicate($shop,$table);
        if(in_array($table,[Tables::order_line_items(),Tables::fulfillment_plans(),Tables::fulfillment_intents(),Tables::fulfillment_readiness_reviews()],true)){
            return 'EXISTS (SELECT 1 FROM '.Tables::orders().' o WHERE o.id='.$table.'.order_id AND '.$owner.')';
        }
        if($table===Tables::personalization_submissions()){
            return 'EXISTS (SELECT 1 FROM '.Tables::order_line_items().' li INNER JOIN '.Tables::orders().' o ON o.id=li.order_id WHERE li.id='.$table.'.order_line_item_id AND '.$owner.')';
        }
        return '';
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
    private function candidates(string $status, int $limit, ?string &$queryState=null): array
    {
        global $wpdb;
        $limit = min(100, max(1, $limit));
        $sql = 'SELECT * FROM ' . Tables::research_candidates();
        if ($status !== '') {
            $sql .= $wpdb->prepare(' WHERE review_status=%s', $status);
        }
        $shop=ShopOperationsReadModel::normalize(isset($_GET['df_shop'])?sanitize_key(wp_unslash($_GET['df_shop'])):ShopOperationsReadModel::ALL);
        if($shop!==ShopOperationsReadModel::ALL){$sql.=($status!==''?' AND ':' WHERE ').$wpdb->prepare('CASE WHEN JSON_VALID(score_inputs) THEN JSON_UNQUOTE(JSON_EXTRACT(score_inputs,%s)) ELSE NULL END=%s','$.shop_key',$shop);}
        $sql .= $wpdb->prepare(' ORDER BY id DESC LIMIT %d', $limit);
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if(!is_array($rows)||!empty($wpdb->last_error)){$queryState='UNAVAILABLE';return [];}$queryState='AVAILABLE';return $rows;
    }

    /** @return array<int,array<string,mixed>> */
    private function evidence(int $candidateId, ?string &$queryState=null): array
    {
        global $wpdb;
        $sql = 'SELECT e.value,e.provenance,o.title AS observation_title,o.provenance AS observation_provenance '
            . 'FROM ' . Tables::research_candidate_evidence() . ' ce '
            . 'INNER JOIN ' . Tables::research_evidence() . ' e ON e.id=ce.evidence_id '
            . 'LEFT JOIN ' . Tables::research_observations() . ' o ON o.id=e.observation_id '
            . 'WHERE ce.candidate_id=%d ORDER BY e.id ASC';
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($wpdb->prepare($sql, $candidateId), ARRAY_A);
        if (! is_array($rows)||!empty($wpdb->last_error)) { $queryState='UNAVAILABLE';return []; }
        $queryState='AVAILABLE';
        foreach ($rows as &$row) {
            $evidence = $this->decode($row['provenance'] ?? '');
            $observation = $this->decode($row['observation_provenance'] ?? '');
            $row['url'] = (string) ($evidence['url'] ?? $observation['url'] ?? '');
            unset($row['provenance'], $row['observation_provenance']);
        }
        unset($row);
        return $rows;
    }

    private function pendingCount(): ?int
    {
        global $wpdb;
        $wpdb->last_error = '';
        $value = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . Tables::research_candidates() . ' WHERE review_status=%s',
                ResearchRepository::REVIEW_PENDING
            )
        );
        return $wpdb->last_error !== '' || !is_numeric($value) ? null : (int) $value;
    }

    private function count(string $table, string $scope = ''): ?int
    {
        global $wpdb;
        $wpdb->last_error = '';
        $where=$scope===''?'':' WHERE '.$scope;
        $value = $wpdb->get_var("SELECT COUNT(*) FROM {$table}{$where}");
        return $wpdb->last_error !== '' || !is_numeric($value) ? null : (int) $value;
    }

    /** @param list<string> $states */
    private function countExcludingStates(string $table, string $column, array $states): ?int
    {
        global $wpdb;
        if (! preg_match('/^[a-z0-9_]+$/i', $column) || $states === []) { return null; }
        $placeholders = implode(',', array_fill(0, count($states), '%s'));
        $sql = "SELECT COUNT(*) FROM {$table} WHERE {$column} NOT IN ({$placeholders})";
        $wpdb->last_error = '';
        $value = $wpdb->get_var($wpdb->prepare($sql, ...$states));
        return $wpdb->last_error !== '' || !is_numeric($value) ? null : (int) $value;
    }

    private function countByState(string $table, string $column, string $state): ?int
    {
        global $wpdb;
        if (! preg_match('/^[a-z0-9_]+$/i', $column)) { return null; }
        $wpdb->last_error = '';
        $value = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column}=%s", $state));
        return $wpdb->last_error !== '' || !is_numeric($value) ? null : (int) $value;
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
        if(!NotificationPreferences::show($error,get_user_meta(get_current_user_id(),NotificationPreferences::META_KEY,true)))return;
        echo $this->notice($message, $error); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    private function notice(string $message, bool $error = false): string
    {
        $class = $error ? 'df-notice-error' : 'df-notice-success';
        return '<div role="' . ($error ? 'alert' : 'status') . '" class="df-notice ' . esc_attr($class) . '">' . esc_html($message) . '</div>';
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
            'df_message' => rawurlencode($message),
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
            'df_message' => rawurlencode($message),
            'df_error' => $error ? 1 : 0,
        ], $target);
        wp_safe_redirect($target);
        exit;
    }

    /** Only persisted, exact authorization hashes can become integrity evidence links. */
    private function integrityEvidenceUrl(string $hash): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $hash)) { return ''; }
        return add_query_arg(['df_integrity_auth'=>$hash], $this->url('attention')) . '#df-integrity-evidence';
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
