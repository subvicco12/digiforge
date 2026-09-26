<?php
declare(strict_types=1);
namespace DigiForge\REST;
use DigiForge\Core\Capabilities;
use DigiForge\Core\Config;
use DigiForge\Core\Settings;
use DigiForge\Operations\Readiness;
use DigiForge\Launch\PrintifyActivationPreflight;
use DigiForge\Launch\EtsyDraftActivationPreflight;
use DigiForge\Launch\RemainingActivationPreflight;
use DigiForge\Security\Logger;
/** Authenticated management endpoints; no endpoint reveals configuration secrets. */
final class Controller {
    public function register(): void { add_action('rest_api_init', [$this, 'routes']); }
    public function routes(): void {
        register_rest_route('digiforge/v1', '/health', ['methods' => 'GET', 'callback' => [$this, 'health'], 'permission_callback' => [$this, 'can_view']]);
        register_rest_route('digiforge/v1', '/readiness', ['methods' => 'GET', 'callback' => [$this, 'readiness'], 'permission_callback' => [$this, 'can_view']]);
        register_rest_route('digiforge/v1', '/controls', ['methods' => 'GET', 'callback' => [$this, 'controls'], 'permission_callback' => [$this, 'can_manage']]);
        register_rest_route('digiforge/v1', '/controls/(?P<key>[a-z_]+)', ['methods' => 'POST', 'callback' => [$this, 'update_control'], 'permission_callback' => [$this, 'can_manage'], 'args' => ['key' => ['sanitize_callback' => 'sanitize_key'], 'enabled' => ['required' => true, 'validate_callback' => static fn($v) => is_bool($v) || in_array($v, [0,1,'0','1'], true)]]]);
        register_rest_route('digiforge/v1', '/activations', ['methods' => 'GET', 'callback' => [$this, 'activations'], 'permission_callback' => [$this, 'can_manage']]);
        register_rest_route('digiforge/v1', '/activations/printify', ['methods' => 'POST', 'callback' => [$this, 'activate_printify'], 'permission_callback' => [$this, 'can_manage']]);
        register_rest_route('digiforge/v1', '/activations/etsy-draft', ['methods' => 'POST', 'callback' => [$this, 'activate_etsy_draft'], 'permission_callback' => [$this, 'can_manage']]);
        foreach (['etsy-publish' => 'etsy_publish', 'order-automation' => 'order_automation', 'gst-automation' => 'gst_automation'] as $route => $capability) {
            register_rest_route('digiforge/v1', '/activations/' . $route, ['methods' => 'POST', 'callback' => fn(\WP_REST_Request $request) => $this->activate_remaining($request, $capability), 'permission_callback' => [$this, 'can_manage']]);
        }
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
    public function controls(\WP_REST_Request $request): \WP_REST_Response { $result=[]; foreach (Config::SWITCHES as $key) { $result[$key] = (bool) Settings::get($key, false); } return new \WP_REST_Response(['controls' => $result, 'externally_locked' => Settings::safety_locked()], 200); }
    public function activations(\WP_REST_Request $request): \WP_REST_Response {
        return new \WP_REST_Response([
            'printify' => (new PrintifyActivationPreflight())->report(),
            'etsy_draft' => (new EtsyDraftActivationPreflight())->report(),
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
    public function activate_remaining(\WP_REST_Request $request, string $capability): \WP_REST_Response|\WP_Error {
        $preflight = (new RemainingActivationPreflight())->report($capability);
        if (($preflight['status'] ?? '') !== 'READY_FOR_CONTROLLED_ACTIVATION') {
            return new \WP_Error('digiforge_scoped_activation_blocked', __('Scoped activation preflight is blocked.', 'digiforge'), ['status' => 409, 'blockers' => $preflight['blockers'] ?? []]);
        }
        $activated = match ($capability) {
            'etsy_publish' => Settings::activateEtsyPublish(),
            'order_automation' => Settings::activateOrderAutomation(),
            'gst_automation' => Settings::activateGstAutomation(),
            default => false,
        };
        if (! $activated) {
            return new \WP_Error('digiforge_scoped_activation_failed', __('Scoped activation failed atomically.', 'digiforge'), ['status' => 500]);
        }
        Logger::audit('scoped_activation_authorized', ['capability' => $capability, 'external_actions_performed' => false], 'system', $capability . '_activation');
        return new \WP_REST_Response(['capability' => $capability, 'authorized' => true, 'effective' => Settings::is_enabled($capability), 'external_actions_performed' => false], 200);
    }
    public function update_control(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $key = $request->get_param('key'); if ($key !== 'stop_all' && ! Config::allowed_switch($key)) { return new \WP_Error('digiforge_invalid_control', __('Unknown control.', 'digiforge'), ['status' => 400]); }
        $enabled = rest_sanitize_boolean($request->get_param('enabled')); if ($key !== 'stop_all' && $enabled && Settings::get('activation_authorized', false) !== true) { return new \WP_Error('digiforge_activation_not_authorized', __('External controls cannot be enabled before activation is explicitly authorized.', 'digiforge'), ['status' => 409]); } if ($key !== 'stop_all' && $enabled && Settings::get('automation_armed', false) !== true) { return new \WP_Error('digiforge_automation_not_armed', __('External controls cannot be enabled before automation is explicitly armed.', 'digiforge'), ['status' => 409]); } if ($key === 'stop_all' && ! $enabled && Settings::get('activation_authorized', false) !== true) { return new \WP_Error('digiforge_activation_not_authorized', __('STOP ALL cannot be disabled before activation is explicitly authorized.', 'digiforge'), ['status' => 409]); } if (! Settings::set($key, $enabled, 'boolean')) { return new \WP_Error('digiforge_control_save_failed', __('Unable to save control.', 'digiforge'), ['status' => 500]); }
        Logger::audit('control_updated', ['control' => $key, 'enabled' => $enabled], 'setting', $key); return new \WP_REST_Response(['key' => $key, 'enabled' => $enabled, 'externally_locked' => Settings::safety_locked()], 200);
    }
}
