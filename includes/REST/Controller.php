<?php
declare(strict_types=1);
namespace DigiForge\REST;
use DigiForge\Core\Capabilities;
use DigiForge\Core\Config;
use DigiForge\Core\Settings;
use DigiForge\Operations\Readiness;
use DigiForge\Operations\RecoveryEvidence;
use DigiForge\Operations\RecoveryDrillEvidence;
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
        register_rest_route('digiforge/v1', '/recovery/evidence', ['methods' => 'GET', 'callback' => [$this, 'recovery_evidence'], 'permission_callback' => [$this, 'can_manage']]);
        register_rest_route('digiforge/v1', '/recovery/evidence/database-backup', ['methods' => 'POST', 'callback' => [$this, 'record_database_backup_evidence'], 'permission_callback' => [$this, 'can_manage']]);
        register_rest_route('digiforge/v1', '/recovery/evidence/plugin-package', ['methods' => 'POST', 'callback' => [$this, 'record_plugin_package_evidence'], 'permission_callback' => [$this, 'can_manage']]);
        register_rest_route('digiforge/v1', '/recovery/drill-evidence', ['methods' => 'GET', 'callback' => [$this, 'recovery_drill_evidence'], 'permission_callback' => [$this, 'can_manage']]);
        register_rest_route('digiforge/v1', '/recovery/drill-evidence', ['methods' => 'POST', 'callback' => [$this, 'record_recovery_drill_evidence'], 'permission_callback' => [$this, 'can_manage']]);
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
    public function recovery_evidence(\WP_REST_Request $request): \WP_REST_Response { return new \WP_REST_Response(RecoveryEvidence::snapshot() + ['external_actions_performed' => false], 200); }
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
    public function record_recovery_drill_evidence(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $record = (array) $request->get_json_params();
        if (! RecoveryDrillEvidence::store($record)) return new \WP_Error('digiforge_recovery_drill_evidence_invalid', __('Fresh recovery drill evidence bound to the current verified backup and rollback package is required.', 'digiforge'), ['status' => 400]);
        Logger::audit('recovery_drill_evidence_verified', ['drill_id' => sanitize_text_field((string)($record['drill_id'] ?? '')), 'external_actions_performed' => false, 'retry_permitted' => false, 'external_execution_authorized' => false], 'system', 'recovery_evidence');
        return new \WP_REST_Response(['recorded' => true, 'recovery_drill' => RecoveryDrillEvidence::snapshot(), 'external_actions_performed' => false], 200);
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
        Logger::audit('printify_activation_authorized', ['capability' => 'printify', 'external_actions_performed' => false], 'system', 'printify_activation');
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
        Logger::audit('etsy_draft_activation_authorized', ['capability' => 'etsy_draft', 'external_actions_performed' => false], 'system', 'etsy_draft_activation');
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
