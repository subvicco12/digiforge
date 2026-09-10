<?php
declare(strict_types=1);
namespace DigiForge\REST;

use DigiForge\Integrations\Repository;

/** Authenticated local-only integration registry API. */
final class IntegrationsController {
    private const NS = 'digiforge/v1';
    public function register(): void {
        add_action('rest_api_init', function (): void {
            register_rest_route(self::NS, '/integrations', [
                ['methods' => 'GET', 'callback' => [$this, 'index'], 'permission_callback' => [$this, 'can_manage']],
                ['methods' => 'POST', 'callback' => [$this, 'create'], 'permission_callback' => [$this, 'can_manage']],
            ]);
            register_rest_route(self::NS, '/integrations/(?P<id>\d+)', [
                ['methods' => 'GET', 'callback' => [$this, 'show'], 'permission_callback' => [$this, 'can_manage']],
                ['methods' => 'PATCH', 'callback' => [$this, 'update'], 'permission_callback' => [$this, 'can_manage']],
            ]);
            register_rest_route(self::NS, '/integrations/(?P<id>\d+)/secrets/(?P<name>[a-zA-Z0-9_-]+)', [
                ['methods' => 'PUT', 'callback' => [$this, 'put_secret'], 'permission_callback' => [$this, 'can_manage']],
            ]);
        });
    }
    public function can_manage(): bool { return current_user_can('manage_digiforge_connections'); }
    public function index(\WP_REST_Request $request): \WP_REST_Response {
        $result = (new Repository())->all(max(1, (int) $request->get_param('page')), min(100, max(1, (int) ($request->get_param('per_page') ?: 50))));
        $response = new \WP_REST_Response($result['items']);
        $response->header('X-WP-Total', (string) $result['pagination']['total']);
        $response->header('X-WP-TotalPages', (string) $result['pagination']['total_pages']);
        return $response;
    }
    public function show(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $row = (new Repository())->find((int) $request['id']);
        return $row ? new \WP_REST_Response($row) : new \WP_Error('integration_not_found', __('Integration not found.', 'digiforge'), ['status' => 404]);
    }
    public function create(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $result = (new Repository())->create((array) $request->get_json_params());
        return is_wp_error($result) ? $result : new \WP_REST_Response($result, 201);
    }
    public function update(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $result = (new Repository())->update((int) $request['id'], (array) $request->get_json_params());
        return is_wp_error($result) ? $result : new \WP_REST_Response($result);
    }
    public function put_secret(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $body = (array) $request->get_json_params(); $value = (string) ($body['value'] ?? '');
        $result = (new Repository())->store_secret((int) $request['id'], (string) $request['name'], $value);
        return is_wp_error($result) ? $result : new \WP_REST_Response(['stored' => true, 'secret_name' => sanitize_key((string) $request['name'])], 200);
    }
}
