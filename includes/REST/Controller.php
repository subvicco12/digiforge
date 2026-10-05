<?php
declare(strict_types=1);
namespace DigiForge\REST;
use DigiForge\Core\Capabilities;
use DigiForge\Core\Config;
use DigiForge\Core\Settings;
use DigiForge\Operations\Readiness;
use DigiForge\Operations\IsolatedStagingSmoke;
use DigiForge\Operations\RecoveryEvidence;
use DigiForge\Operations\RecoveryDrillEvidence;
use DigiForge\Operations\RecoveryOrchestrator;
use DigiForge\Operations\RecoveryStagingVerifier;
use DigiForge\Operations\RecoveryVerificationEvidence;
use DigiForge\Operations\RecoveryDrillReviewCandidate;
use DigiForge\Operations\RecoveryDrillAcceptance;
use DigiForge\Operations\RecoveryOperationSupersession;
use DigiForge\Operations\RecoveryBackupIdentityMarker;
use DigiForge\Operations\RecoveryBackupIdentityBinding;
use DigiForge\Operations\RecoveryBackupMarkerAttestation;
use DigiForge\Launch\PrintifyActivationPreflight;
use DigiForge\Launch\EtsyDraftActivationPreflight;
use DigiForge\Launch\RemainingActivationPreflight;
use DigiForge\Security\Logger;
use DigiForge\Integrations\EtsySellerTaxonomyClient;
/** Authenticated management endpoints; no endpoint reveals configuration secrets. */
final class Controller {
    public function register(): void { add_action('rest_api_init', [$this, 'routes']); }
    public function routes(): void {
        register_rest_route('digiforge/v1', '/health', ['methods' => 'GET', 'callback' => [$this, 'health'], 'permission_callback' => [$this, 'can_view']]);
        register_rest_route('digiforge/v1', '/readiness', ['methods' => 'GET', 'callback' => [$this, 'readiness'], 'permission_callback' => [$this, 'can_view']]);
        register_rest_route('digiforge/v1', '/recovery/verification-snapshot', ['methods' => 'GET', 'callback' => [$this, 'recovery_verification_snapshot'], 'permission_callback' => '__return_true']);
        register_rest_route('digiforge/v1', '/recovery/evidence', ['methods' => 'GET', 'callback' => [$this, 'recovery_evidence'], 'permission_callback' => [$this, 'can_manage']]);
        register_rest_route('digiforge/v1', '/recovery/evidence/database-backup', ['methods' => 'POST', 'callback' => [$this, 'record_database_backup_evidence'], 'permission_callback' => [$this, 'can_manage']]);
        register_rest_route('digiforge/v1', '/recovery/evidence/database-backup/prepare', ['methods' => 'POST', 'callback' => [$this, 'prepare_database_backup_identity'], 'permission_callback' => [$this, 'can_manage']]);
        register_rest_route('digiforge/v1', '/recovery/evidence/database-backup/bind', ['methods' => 'POST', 'callback' => [$this, 'bind_database_backup_identity'], 'permission_callback' => [$this, 'can_manage']]);
        register_rest_route('digiforge/v1', '/recovery/evidence/database-backup/marker/(?P<operation_key_hash>[a-f0-9]{64})', ['methods' => 'GET', 'callback' => [$this, 'recovery_database_backup_marker'], 'permission_callback' => '__return_true']);
        register_rest_route('digiforge/v1', '/recovery/evidence/plugin-package', ['methods' => 'POST', 'callback' => [$this, 'record_plugin_package_evidence'], 'permission_callback' => [$this, 'can_manage']]);
        register_rest_route('digiforge/v1', '/recovery/drill-evidence', ['methods' => 'GET', 'callback' => [$this, 'recovery_drill_evidence'], 'permission_callback' => [$this, 'can_manage']]);
        register_rest_route('digiforge/v1', '/recovery/orchestration/drill-accept', ['methods' => 'POST', 'callback' => [$this, 'accept_recovery_drill'], 'permission_callback' => [$this, 'can_manage']]);
        register_rest_route('digiforge/v1', '/recovery/orchestration/supersede-stale', ['methods' => 'POST', 'callback' => [$this, 'supersede_stale_recovery'], 'permission_callback' => [$this, 'can_manage']]);
        register_rest_route('digiforge/v1', '/recovery/orchestration', ['methods' => 'GET', 'callback' => [$this, 'recovery_orchestration'], 'permission_callback' => [$this, 'can_manage']]);
        register_rest_route('digiforge/v1', '/recovery/orchestration/plan', ['methods' => 'POST', 'callback' => [$this, 'plan_recovery_orchestration'], 'permission_callback' => [$this, 'can_manage']]);
        register_rest_route('digiforge/v1', '/recovery/orchestration/execute', ['methods' => 'POST', 'callback' => [$this, 'execute_recovery_orchestration'], 'permission_callback' => [$this, 'can_manage']]);
        register_rest_route('digiforge/v1', '/recovery/orchestration/reconcile-manual', ['methods' => 'POST', 'callback' => [$this, 'reconcile_manual_recovery_orchestration'], 'permission_callback' => [$this, 'can_manage']]);
        register_rest_route('digiforge/v1', '/recovery/orchestration/verify', ['methods' => 'POST', 'callback' => [$this, 'verify_recovery_orchestration'], 'permission_callback' => [$this, 'can_manage']]);
        register_rest_route('digiforge/v1', '/recovery/orchestration/drill-review', ['methods' => 'GET', 'callback' => [$this, 'recovery_drill_review_candidate'], 'permission_callback' => [$this, 'can_manage']]);
        register_rest_route('digiforge/v1', '/internal-smoke/p5', ['methods' => 'POST', 'callback' => [$this, 'p5_smoke'], 'permission_callback' => [$this, 'can_manage']]);
        register_rest_route('digiforge/v1', '/controls', ['methods' => 'GET', 'callback' => [$this, 'controls'], 'permission_callback' => [$this, 'can_manage']]);
        register_rest_route('digiforge/v1', '/protection/restore', ['methods' => 'POST', 'callback' => [$this, 'restore_protection'], 'permission_callback' => [$this, 'can_manage']]);
        register_rest_route('digiforge/v1', '/controls/(?P<key>[a-z_]+)', ['methods' => 'POST', 'callback' => [$this, 'update_control'], 'permission_callback' => [$this, 'can_manage'], 'args' => ['key' => ['sanitize_callback' => 'sanitize_key'], 'enabled' => ['required' => true, 'validate_callback' => static fn($v) => is_bool($v) || in_array($v, [0,1,'0','1'], true)]]]);
        register_rest_route('digiforge/v1', '/etsy/taxonomy-verify', ['methods' => 'GET', 'callback' => [$this, 'etsy_taxonomy_verify'], 'permission_callback' => [$this, 'can_manage'], 'args' => ['integration_id' => ['required' => true, 'sanitize_callback' => 'absint'], 'taxonomy_id' => ['required' => true, 'sanitize_callback' => 'absint']]]);
        register_rest_route('digiforge/v1', '/etsy/taxonomy-discovery', ['methods' => 'GET', 'callback' => [$this, 'etsy_taxonomy_discovery'], 'permission_callback' => [$this, 'can_manage'], 'args' => ['integration_id' => ['required' => true, 'sanitize_callback' => 'absint'], 'q' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'], 'limit' => ['required' => false, 'sanitize_callback' => 'absint']]]);
        register_rest_route('digiforge/v1', '/activations', ['methods' => 'GET', 'callback' => [$this, 'activations'], 'permission_callback' => [$this, 'can_manage']]);
        register_rest_route('digiforge/v1', '/activations/printify', ['methods' => 'POST', 'callback' => [$this, 'activate_printify'], 'permission_callback' => [$this, 'can_manage']]);
        register_rest_route('digiforge/v1', '/activations/etsy-draft', ['methods' => 'POST', 'callback' => [$this, 'activate_etsy_draft'], 'permission_callback' => [$this, 'can_manage']]);
        register_rest_route('digiforge/v1', '/activations/(?P<capability>gelato|etsy-publish|order-automation|gst-automation)', ['methods' => 'POST', 'callback' => [$this, 'activate_remaining'], 'permission_callback' => [$this, 'can_manage']]);
    }
    public function can_view(\WP_REST_Request $request): bool { return Capabilities::can('manage_digiforge') || Capabilities::can('view_digiforge_analytics'); }
    public function can_manage(\WP_REST_Request $request): bool { return Capabilities::can('manage_digiforge_automation'); }
    public function health(\WP_REST_Request $request): \WP_REST_Response {
        $automation_enabled = false;
        foreach (Config::SWITCHES as $switch) {
            if ($switch !== 'stop_all' && Settings::is_enabled($switch)) { $automation_enabled = true; break; }
        }
        return new \WP_REST_Response(['status' => 'ok', 'version' => DIGIFORGE_VERSION, 'automation_enabled' => $automation_enabled, 'stop_all' => (bool) Settings::get('stop_all', true), 'externally_locked' => Settings::safety_locked()], 200);
    }
    public function readiness(\WP_REST_Request $request): \WP_REST_Response { return new \WP_REST_Response((new Readiness())->report(), 200); }
    public function recovery_verification_snapshot(\WP_REST_Request $request): \WP_REST_Response {
        $report=(new Readiness())->report();
        $schema=is_array($report['schema']??null)?$report['schema']:[];
        $checks=is_array($report['checks']??null)?$report['checks']:[];
        return new \WP_REST_Response([
            'status'=>'ok',
            'version'=>DIGIFORGE_VERSION,
            'schema'=>['current'=>(int)($schema['current']??0),'expected'=>(int)($schema['expected']??0)],
            'stop_all'=>(bool)Settings::get('stop_all',true),
            'externally_locked'=>Settings::safety_locked(),
            'automation_enabled'=>array_reduce(Config::SWITCHES,static fn(bool $on,string $switch):bool=>$on||($switch!=='stop_all'&&Settings::is_enabled($switch)),false),
            'activation_not_authorized'=>($checks['activation_not_authorized']??false)===true,
            'automation_unarmed'=>($checks['automation_unarmed']??false)===true,
            'no_effective_feature_switches'=>($checks['no_effective_feature_switches']??false)===true,
            'read_only'=>true,
            'external_actions_performed'=>false,
        ],200);
    }
    public function p5_smoke(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $result=IsolatedStagingSmoke::run(absint($request->get_param('listing_id')),trim(sanitize_text_field((string)$request->get_param('operation_key'))));
        return $result instanceof \WP_Error?$result:new \WP_REST_Response($result,200);
    }
    public function recovery_evidence(\WP_REST_Request $request): \WP_REST_Response { return new \WP_REST_Response(RecoveryEvidence::snapshot() + ['external_actions_performed' => false], 200); }
    public function recovery_database_backup_marker(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $result=RecoveryBackupMarkerAttestation::attest((string)$request->get_param('operation_key_hash'));
        if ($result instanceof \WP_Error) return $result;
        return new \WP_REST_Response($result,200);
    }

    public function bind_database_backup_identity(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $operationKey = trim(sanitize_text_field((string)($request->get_param('operation_key') ?? '')));
        $backupIdentifier = trim(sanitize_text_field((string)($request->get_param('backup_identifier') ?? '')));
        $result = RecoveryBackupIdentityBinding::bind($operationKey, $backupIdentifier);
        if ($result instanceof \WP_Error) return $result;
        if (! Logger::write('recovery_database_backup_identity_bound', ['marker_hash'=>(string)($result['marker_hash']??''),'backup_identifier'=>(string)($result['backup_identifier']??''),'backup_evidence_hash'=>(string)($result['backup_evidence_hash']??''),'binding_hash'=>(string)($result['binding_hash']??''),'external_actions_performed'=>false,'external_execution_authorized'=>false], 'system', 'recovery_evidence')) {
            return new \WP_Error('digiforge_recovery_backup_binding_audit_failed', __('Backup identity binding audit evidence could not be persisted.', 'digiforge'), ['status'=>500]);
        }
        return new \WP_REST_Response($result, 200);
    }

    public function prepare_database_backup_identity(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $operationKey = trim(sanitize_text_field((string)($request->get_param('operation_key') ?? '')));
        $result = RecoveryBackupIdentityMarker::prepare($operationKey);
        if ($result instanceof \WP_Error) return $result;
        if (! Logger::write('recovery_database_backup_identity_prepared', ['operation_key_hash' => (string)($result['operation_key_hash'] ?? ''), 'marker_hash' => (string)($result['marker_hash'] ?? ''), 'backup_certified' => false, 'external_actions_performed' => false, 'external_execution_authorized' => false], 'system', 'recovery_evidence')) {
            return new \WP_Error('digiforge_recovery_backup_marker_audit_failed', __('Backup identity preparation audit evidence could not be persisted.', 'digiforge'), ['status' => 500]);
        }
        return new \WP_REST_Response($result, 200);
    }

    public function record_database_backup_evidence(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $record = (array) $request->get_json_params();
        if (! RecoveryEvidence::storeDatabaseBackup($record)) return new \WP_Error('digiforge_recovery_backup_evidence_invalid', __('Complete, independently verified and retrievable database backup evidence is required.', 'digiforge'), ['status' => 400]);
        Logger::audit('recovery_database_backup_evidence_verified', ['identifier' => sanitize_text_field((string)($record['identifier'] ?? '')), 'verification_method' => sanitize_text_field((string)($record['verification_method'] ?? '')), 'verified_at' => sanitize_text_field((string)($record['verified_at'] ?? '')), 'external_actions_performed' => false, 'retry_permitted' => false, 'external_execution_authorized' => false], 'system', 'recovery_evidence');
        return new \WP_REST_Response(['recorded' => true, 'database_backup' => RecoveryEvidence::snapshot()['database_backup'], 'external_actions_performed' => false], 200);
    }
    public function record_plugin_package_evidence(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $record = (array) $request->get_json_params();
        if (! RecoveryEvidence::storePluginPackage($record)) return new \WP_Error('digiforge_recovery_package_evidence_invalid', __('Complete, valid rollback plugin package evidence is required.', 'digiforge'), ['status' => 400]);
        Logger::audit('recovery_plugin_package_evidence_recorded', ['identifier' => sanitize_text_field((string)($record['identifier'] ?? '')), 'external_actions_performed' => false], 'system', 'recovery_evidence');
        return new \WP_REST_Response(['recorded' => true, 'plugin_package' => RecoveryEvidence::snapshot()['plugin_package'], 'external_actions_performed' => false], 200);
    }
    public function recovery_drill_evidence(\WP_REST_Request $request): \WP_REST_Response { return new \WP_REST_Response(RecoveryDrillEvidence::snapshot(), 200); }
    public function accept_recovery_drill(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $operationKey=trim(sanitize_text_field((string)($request->get_param('operation_key')??'')));
        $evidenceHash=trim(sanitize_text_field((string)($request->get_param('verification_evidence_hash')??'')));
        $user=wp_get_current_user();
        $performedBy=(string)($user->user_email ?: $user->user_login);
        $result=RecoveryDrillAcceptance::accept($operationKey,$evidenceHash,$performedBy);
        if ($result instanceof \WP_Error) return $result;
        return new \WP_REST_Response($result,200);
    }
    public function supersede_stale_recovery(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $operationKey=trim(sanitize_text_field((string)($request->get_param('operation_key')??'')));
        $confirmation=trim(sanitize_text_field((string)($request->get_param('confirmation')??'')));
        $user=wp_get_current_user();
        $performedBy=(string)($user->user_email ?: $user->user_login);
        $result=RecoveryOperationSupersession::supersede($operationKey,$confirmation,$performedBy);
        if ($result instanceof \WP_Error) return $result;
        return new \WP_REST_Response($result,200);
    }
    public function recovery_orchestration(\WP_REST_Request $request): \WP_REST_Response { return new \WP_REST_Response(RecoveryOrchestrator::snapshot(), 200); }
    public function plan_recovery_orchestration(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $result = RecoveryOrchestrator::plan((array) $request->get_json_params());
        if ($result instanceof \WP_Error) return $result;
        if (! Logger::write('recovery_orchestration_planned', ['operation_key' => (string)($result['operation_key'] ?? ''), 'target_environment' => (string)($result['target_environment'] ?? ''), 'database_backup_identifier' => (string)($result['database_backup_identifier'] ?? ''), 'plugin_package_identifier' => (string)($result['plugin_package_identifier'] ?? ''), 'external_actions_performed' => false, 'external_execution_authorized' => false], 'system', 'recovery_orchestration')) {
            return new \WP_Error('digiforge_recovery_plan_audit_failed', __('Recovery plan audit evidence could not be persisted.', 'digiforge'), ['status' => 500]);
        }
        return new \WP_REST_Response($result, 200);
    }
    public function execute_recovery_orchestration(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $operationKey = trim(sanitize_text_field((string)($request->get_param('operation_key') ?? '')));
        $result = RecoveryOrchestrator::execute($operationKey);
        if ($result instanceof \WP_Error) return $result;
        if (! Logger::write('recovery_orchestration_provider_dispatched', ['operation_key' => $operationKey, 'state' => (string)($result['state'] ?? ''), 'provider' => (string)($result['provider'] ?? ''), 'commerce_execution_authorized' => false], 'system', 'recovery_orchestration')) {
            return new \WP_Error('digiforge_recovery_dispatch_audit_failed', __('Recovery dispatch audit evidence could not be persisted.', 'digiforge'), ['status' => 500]);
        }
        return new \WP_REST_Response($result, 200);
    }
    public function reconcile_manual_recovery_orchestration(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $operationKey = trim(sanitize_text_field((string)($request->get_param('operation_key') ?? '')));
        $providerReference = trim(sanitize_text_field((string)($request->get_param('provider_operation_reference') ?? '')));
        $confirmation = trim(sanitize_text_field((string)($request->get_param('confirmation') ?? '')));
        $result = RecoveryOrchestrator::reconcileManualRestore($operationKey, $providerReference, $confirmation);
        if ($result instanceof \WP_Error) return $result;
        if (! Logger::write('recovery_orchestration_manual_restore_reconciled', [
            'operation_key' => $operationKey,
            'state' => (string)($result['state'] ?? ''),
            'provider' => (string)($result['provider'] ?? ''),
            'provider_operation_reference' => (string)($result['provider_operation_reference'] ?? ''),
            'manual_restore_reconciled' => true,
            'external_actions_performed' => true,
            'external_action_performed_outside_digiforge' => true,
            'external_execution_authorized' => false,
            'commerce_execution_authorized' => false,
        ], 'system', 'recovery_orchestration')) {
            return new \WP_Error('digiforge_recovery_manual_reconciliation_audit_failed', __('Manual restore reconciliation audit evidence could not be persisted.', 'digiforge'), ['status' => 500, 'reconciliation_required' => true]);
        }
        return new \WP_REST_Response($result, 200);
    }

    public function recovery_drill_review_candidate(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $operationKey = trim(sanitize_text_field((string)($request->get_param('operation_key') ?? '')));
        $result = RecoveryDrillReviewCandidate::build($operationKey);
        if ($result instanceof \WP_Error) return $result;
        return new \WP_REST_Response($result, 200);
    }

    public function verify_recovery_orchestration(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $operationKey = trim(sanitize_text_field((string)($request->get_param('operation_key') ?? '')));
        $result = RecoveryStagingVerifier::verify($operationKey);
        if ($result instanceof \WP_Error) return $result;
        $evidence = RecoveryVerificationEvidence::record($result);
        if ($evidence instanceof \WP_Error) return $evidence;
        $result['verification_evidence'] = $evidence;
        if (! Logger::write('recovery_orchestration_staging_verified', ['operation_key' => $operationKey, 'verified' => (bool)($result['verified'] ?? false), 'target_site_url' => (string)($result['target_site_url'] ?? ''), 'plugin_package_identifier' => (string)($result['plugin_package_identifier'] ?? ''), 'database_backup_identifier' => (string)($result['database_backup_identifier'] ?? ''), 'external_actions_performed' => false, 'commerce_execution_authorized' => false], 'system', 'recovery_orchestration')) {
            return new \WP_Error('digiforge_recovery_verify_audit_failed', __('Recovery verification audit evidence could not be persisted.', 'digiforge'), ['status' => 500]);
        }
        return new \WP_REST_Response($result, 200);
    }
    public function controls(\WP_REST_Request $request): \WP_REST_Response { $result=[]; foreach (Config::SWITCHES as $key) { $result[$key] = (bool) Settings::get($key, false); } return new \WP_REST_Response(['controls' => $result, 'externally_locked' => Settings::safety_locked()], 200); }
    public function restore_protection(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        if (! Settings::protectProduction()) { Logger::audit('production_protection_failed', ['source' => 'rest'], 'system', 'production_activation'); return new \WP_Error('digiforge_protection_failed', __('Unable to restore protected production posture.', 'digiforge'), ['status' => 500]); }
        Logger::audit('production_protected', ['source' => 'rest', 'external_feature_switches_changed' => false, 'external_actions_performed' => false], 'system', 'production_activation');
        return new \WP_REST_Response(['protected' => true, 'externally_locked' => Settings::safety_locked(), 'stop_all' => (bool) Settings::get('stop_all', true), 'activation_authorized' => (bool) Settings::get('activation_authorized', false), 'automation_armed' => (bool) Settings::get('automation_armed', false), 'external_actions_performed' => false], 200);
    }
    public function etsy_taxonomy_verify(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $integrationId=(int)$request->get_param('integration_id');
        $taxonomyId=(int)$request->get_param('taxonomy_id');
        if($integrationId<1||$taxonomyId<1) return new \WP_Error('digiforge_etsy_taxonomy_verify_input', __('Valid integration and taxonomy identifiers are required.', 'digiforge'), ['status'=>400]);
        $result=(new EtsySellerTaxonomyClient())->fetchVerified($integrationId,$taxonomyId);
        if($result instanceof \WP_Error) return $result;
        return new \WP_REST_Response($result+['external_actions_performed'=>false],200);
    }
    public function etsy_taxonomy_discovery(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $integrationId=(int)$request->get_param('integration_id');
        $query=trim((string)$request->get_param('q'));
        $terms=array_values(array_filter(array_map('trim',preg_split('/[,]+/',$query)?:[])));
        $limit=(int)($request->get_param('limit')?:20);
        if($integrationId<1||$terms===[]) return new \WP_Error('digiforge_etsy_taxonomy_discovery_input', __('Valid integration and search terms are required.', 'digiforge'), ['status'=>400]);
        $result=(new EtsySellerTaxonomyClient())->discover($integrationId,$terms,$limit);
        if($result instanceof \WP_Error) return $result;
        return new \WP_REST_Response($result+['external_actions_performed'=>false],200);
    }
    public function activations(\WP_REST_Request $request): \WP_REST_Response {
        return new \WP_REST_Response([
            'printify' => (new PrintifyActivationPreflight())->report(),
            'etsy_draft' => (new EtsyDraftActivationPreflight())->report(),
            'gelato' => (new RemainingActivationPreflight())->report('gelato'),
            'etsy_publish' => (new RemainingActivationPreflight())->report('etsy_publish'),
            'order_automation' => (new RemainingActivationPreflight())->report('order_automation'),
            'gst_automation' => (new RemainingActivationPreflight())->report('gst_automation'),
            'external_actions_performed' => false,
        ], 200);
    }
    public function activate_printify(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $preflight = (new PrintifyActivationPreflight())->report();
        if (($preflight['status'] ?? '') !== 'READY_FOR_CONTROLLED_PRINTIFY_ACTIVATION') {
            return new \WP_Error('digiforge_printify_activation_blocked', __('Printify activation preflight is blocked.', 'digiforge'), ['status' => 409, 'blockers' => $preflight['blockers'] ?? []]);
        }
        if (! Settings::activatePrintify()) {
            return new \WP_Error('digiforge_printify_activation_failed', __('Printify activation failed atomically.', 'digiforge'), ['status' => 500]);
        }
        if (! Logger::write('printify_activation_authorized', ['capability' => 'printify', 'external_actions_performed' => false], 'system', 'printify_activation')) {
            Settings::revokeScopedAuthorization('printify_activation_authorized');
            return new \WP_Error('digiforge_printify_activation_audit_failed', __('Printify activation was revoked because its audit record could not be persisted.', 'digiforge'), ['status' => 500]);
        }
        return new \WP_REST_Response(['capability' => 'printify', 'authorized' => true, 'effective' => Settings::is_enabled('printify'), 'external_actions_performed' => false], 200);
    }
    public function activate_etsy_draft(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $preflight = (new EtsyDraftActivationPreflight())->report();
        if (($preflight['status'] ?? '') !== 'READY_FOR_CONTROLLED_ETSY_DRAFT_ACTIVATION') {
            return new \WP_Error('digiforge_etsy_draft_activation_blocked', __('Etsy Draft activation preflight is blocked.', 'digiforge'), ['status' => 409, 'blockers' => $preflight['blockers'] ?? []]);
        }
        if (! Settings::activateEtsyDraft()) {
            return new \WP_Error('digiforge_etsy_draft_activation_failed', __('Etsy Draft activation failed atomically.', 'digiforge'), ['status' => 500]);
        }
        if (! Logger::write('etsy_draft_activation_authorized', ['capability' => 'etsy_draft', 'external_actions_performed' => false], 'system', 'etsy_draft_activation')) {
            Settings::revokeScopedAuthorization('etsy_draft_activation_authorized');
            return new \WP_Error('digiforge_etsy_draft_activation_audit_failed', __('Etsy Draft activation was revoked because its audit record could not be persisted.', 'digiforge'), ['status' => 500]);
        }
        return new \WP_REST_Response(['capability' => 'etsy_draft', 'authorized' => true, 'effective' => Settings::is_enabled('etsy_draft'), 'external_actions_performed' => false], 200);
    }
    public function activate_remaining(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $routeCapability=(string)$request['capability'];
        $capability=str_replace('-', '_', $routeCapability);
        $methods=[
            'gelato'=>'activateGelato',
            'etsy_publish'=>'activateEtsyPublish',
            'order_automation'=>'activateOrderAutomation',
            'gst_automation'=>'activateGstAutomation',
        ];
        if(!isset($methods[$capability])) {
            return new \WP_Error('digiforge_activation_capability', __('Unsupported capability activation.', 'digiforge'), ['status'=>400]);
        }
        $preflight=(new RemainingActivationPreflight())->report($capability);
        $expected='READY_FOR_CONTROLLED_'.strtoupper($capability).'_ACTIVATION';
        if(($preflight['status']??'')!==$expected) {
            return new \WP_Error('digiforge_'.$capability.'_activation_blocked', __('Capability activation preflight is blocked.', 'digiforge'), ['status'=>409,'blockers'=>$preflight['blockers']??[]]);
        }
        $method=$methods[$capability];
        if(!Settings::$method()) {
            return new \WP_Error('digiforge_'.$capability.'_activation_failed', __('Capability activation failed atomically.', 'digiforge'), ['status'=>500]);
        }
        if (!Logger::write($capability.'_activation_authorized', ['capability'=>$capability,'external_actions_performed'=>false], 'system', $capability.'_activation')) {
            Settings::revokeScopedAuthorization($capability.'_activation_authorized');
            return new \WP_Error('digiforge_'.$capability.'_activation_audit_failed', __('Capability activation was revoked because its audit record could not be persisted.', 'digiforge'), ['status'=>500]);
        }
        return new \WP_REST_Response(['capability'=>$capability,'authorized'=>true,'effective'=>Settings::is_enabled($capability),'external_actions_performed'=>false],200);
    }
    public function update_control(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $key = $request->get_param('key'); if ($key !== 'stop_all' && ! Config::allowed_switch($key)) { return new \WP_Error('digiforge_invalid_control', __('Unknown control.', 'digiforge'), ['status' => 400]); }
        $enabled = rest_sanitize_boolean($request->get_param('enabled')); if ($key !== 'stop_all' && $enabled && Settings::get('activation_authorized', false) !== true) { return new \WP_Error('digiforge_activation_not_authorized', __('External controls cannot be enabled before activation is explicitly authorized.', 'digiforge'), ['status' => 409]); } if ($key !== 'stop_all' && $enabled && Settings::get('automation_armed', false) !== true) { return new \WP_Error('digiforge_automation_not_armed', __('External controls cannot be enabled before automation is explicitly armed.', 'digiforge'), ['status' => 409]); } if ($key === 'stop_all' && ! $enabled && Settings::get('activation_authorized', false) !== true) { return new \WP_Error('digiforge_activation_not_authorized', __('STOP ALL cannot be disabled before activation is explicitly authorized.', 'digiforge'), ['status' => 409]); } if (! Settings::set($key, $enabled, 'boolean')) { return new \WP_Error('digiforge_control_save_failed', __('Unable to save control.', 'digiforge'), ['status' => 500]); }
        Logger::audit('control_updated', ['control' => $key, 'enabled' => $enabled], 'setting', $key); return new \WP_REST_Response(['key' => $key, 'enabled' => $enabled, 'externally_locked' => Settings::safety_locked()], 200);
    }
}
