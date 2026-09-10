<?php
declare(strict_types=1);
namespace DigiForge\REST;
use DigiForge\Core\Capabilities;
use DigiForge\Core\Config;
use DigiForge\Core\Settings;
use DigiForge\Security\Logger;
use DigiForge\ProductFactory\Repository;
use DigiForge\Queue\Idempotency;
/** Authenticated management endpoints; no endpoint reveals configuration secrets. */
final class Controller {
    public function register(): void { add_action('rest_api_init', [$this, 'routes']); }
    public function routes(): void {
        register_rest_route('digiforge/v1', '/health', ['methods' => 'GET', 'callback' => [$this, 'health'], 'permission_callback' => [$this, 'can_view']]);
        register_rest_route('digiforge/v1', '/controls', ['methods' => 'GET', 'callback' => [$this, 'controls'], 'permission_callback' => [$this, 'can_manage']]);
        register_rest_route('digiforge/v1', '/controls/(?P<key>[a-z_]+)', ['methods' => 'POST', 'callback' => [$this, 'update_control'], 'permission_callback' => [$this, 'can_manage'], 'args' => ['key' => ['sanitize_callback' => 'sanitize_key'], 'enabled' => ['required' => true, 'validate_callback' => static fn($v) => is_bool($v) || in_array($v, [0,1,'0','1'], true)]]]);
        foreach (['opportunity' => 'opportunities', 'product_family' => 'product-families', 'product' => 'products', 'product_version' => 'product-versions'] as $type => $route) {
            register_rest_route('digiforge/v1', '/' . $route, ['methods' => 'GET', 'callback' => fn(\WP_REST_Request $request) => $this->entities($type, $request), 'permission_callback' => [$this, 'can_products']]);
            register_rest_route('digiforge/v1', '/' . $route, ['methods' => 'POST', 'callback' => fn(\WP_REST_Request $request) => $this->create_entity($type, $request), 'permission_callback' => [$this, 'can_products']]);
            register_rest_route('digiforge/v1', '/' . $route . '/(?P<id>\\d+)', ['methods' => 'GET', 'callback' => fn(\WP_REST_Request $request) => $this->entity($type, $request), 'permission_callback' => [$this, 'can_products']]);
            register_rest_route('digiforge/v1', '/' . $route . '/(?P<id>\\d+)/state', ['methods' => 'POST', 'callback' => fn(\WP_REST_Request $request) => $this->transition_entity($type, $request), 'permission_callback' => [$this, 'can_products'], 'args' => ['status' => ['required' => true, 'sanitize_callback' => 'sanitize_key']]]);
        }
    }
    public function can_view(\WP_REST_Request $request): bool { return Capabilities::can('manage_digiforge') || Capabilities::can('view_digiforge_analytics'); }
    public function can_manage(\WP_REST_Request $request): bool { return Capabilities::can('manage_digiforge_automation'); }
    public function can_products(\WP_REST_Request $request): bool { return Capabilities::can('manage_digiforge_products'); }
    public function health(\WP_REST_Request $request): \WP_REST_Response {
        $automation_enabled = false;
        foreach (Config::SWITCHES as $switch) {
            if ($switch !== 'stop_all' && Settings::is_enabled($switch)) { $automation_enabled = true; break; }
        }
        return new \WP_REST_Response(['status' => 'ok', 'version' => DIGIFORGE_VERSION, 'automation_enabled' => $automation_enabled, 'stop_all' => (bool) Settings::get('stop_all', false)], 200);
    }
    public function controls(\WP_REST_Request $request): \WP_REST_Response { $result=[]; foreach (Config::SWITCHES as $key) { $result[$key] = (bool) Settings::get($key, false); } return new \WP_REST_Response(['controls' => $result], 200); }
    public function update_control(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $key = $request->get_param('key'); if ($key !== 'stop_all' && ! Config::allowed_switch($key)) { return new \WP_Error('digiforge_invalid_control', __('Unknown control.', 'digiforge'), ['status' => 400]); }
        $enabled = rest_sanitize_boolean($request->get_param('enabled')); if (! Settings::set($key, $enabled, 'boolean')) { return new \WP_Error('digiforge_control_save_failed', __('Unable to save control.', 'digiforge'), ['status' => 500]); }
        Logger::audit('control_updated', ['control' => $key, 'enabled' => $enabled], 'setting', $key); return new \WP_REST_Response(['key' => $key, 'enabled' => $enabled], 200);
    }
    public function entities(string $type, \WP_REST_Request $request): \WP_REST_Response { $parent = absint($request->get_param('parent_id')); return new \WP_REST_Response(['items' => (new Repository())->list($type, $parent)], 200); }
    public function entity(string $type, \WP_REST_Request $request): \WP_REST_Response|\WP_Error { $entity = (new Repository())->find($type, absint($request['id'])); return $entity === null ? new \WP_Error('digiforge_not_found', __('Entity was not found.', 'digiforge'), ['status' => 404]) : new \WP_REST_Response($entity, 200); }
    public function create_entity(string $type, \WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $repository = new Repository();
        $validation = $repository->validate($type, $request->get_params());
        if (is_wp_error($validation)) { return $validation; }
        $key = sanitize_text_field((string) $request->get_header('Idempotency-Key'));
        if ($key !== '' && ! (new Idempotency())->reserve('product-factory-' . $type . '-' . $key, $type . '_create')) { return new \WP_Error('digiforge_duplicate_request', __('This request has already been processed.', 'digiforge'), ['status' => 409]); }
        $result = $repository->create($type, $request->get_params());
        if (is_wp_error($result)) { return $result; }
        if ($key !== '') { (new Idempotency())->complete('product-factory-' . $type . '-' . $key, (string) $result); }
        return new \WP_REST_Response(['id' => $result, 'type' => $type], 201);
    }
    public function transition_entity(string $type, \WP_REST_Request $request): \WP_REST_Response|\WP_Error { $result = (new Repository())->transition($type, absint($request['id']), (string) $request->get_param('status')); if (is_wp_error($result)) { return $result; } return new \WP_REST_Response(['id' => absint($request['id']), 'status' => strtoupper(sanitize_key((string) $request->get_param('status')))], 200); }
}
