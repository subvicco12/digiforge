<?php
declare(strict_types=1);
namespace DigiForge\REST;
use DigiForge\Core\Capabilities;
use DigiForge\Core\Config;
use DigiForge\Core\Settings;
use DigiForge\Operations\Readiness;
use DigiForge\Security\Logger;
/** Authenticated management endpoints; no endpoint reveals configuration secrets. */
final class Controller {
    public function register(): void { add_action('rest_api_init', [$this, 'routes']); }
    public function routes(): void {
        register_rest_route('digiforge/v1', '/health', ['methods' => 'GET', 'callback' => [$this, 'health'], 'permission_callback' => [$this, 'can_view']]);
        register_rest_route('digiforge/v1', '/readiness', ['methods' => 'GET', 'callback' => [$this, 'readiness'], 'permission_callback' => [$this, 'can_view']]);
        register_rest_route('digiforge/v1', '/controls', ['methods' => 'GET', 'callback' => [$this, 'controls'], 'permission_callback' => [$this, 'can_manage']]);
        register_rest_route('digiforge/v1', '/controls/(?P<key>[a-z_]+)', ['methods' => 'POST', 'callback' => [$this, 'update_control'], 'permission_callback' => [$this, 'can_manage'], 'args' => ['key' => ['sanitize_callback' => 'sanitize_key'], 'enabled' => ['required' => true, 'validate_callback' => static fn($v) => is_bool($v) || in_array($v, [0,1,'0','1'], true)]]]);
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
    public function update_control(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $key = $request->get_param('key'); if ($key !== 'stop_all' && ! Config::allowed_switch($key)) { return new \WP_Error('digiforge_invalid_control', __('Unknown control.', 'digiforge'), ['status' => 400]); }
        $enabled = rest_sanitize_boolean($request->get_param('enabled')); if (! Settings::set($key, $enabled, 'boolean')) { return new \WP_Error('digiforge_control_save_failed', __('Unable to save control.', 'digiforge'), ['status' => 500]); }
        Logger::audit('control_updated', ['control' => $key, 'enabled' => $enabled], 'setting', $key); return new \WP_REST_Response(['key' => $key, 'enabled' => $enabled, 'externally_locked' => Settings::safety_locked()], 200);
    }
}
