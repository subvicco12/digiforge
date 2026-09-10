<?php
declare(strict_types=1);
namespace DigiForge\REST;

use DigiForge\Core\Capabilities;
use DigiForge\ProductFactory\Repository;

/** Capability-gated REST surface for local Product Factory records only. */
final class ProductFactoryController {
    private const ROUTES = ['opportunities' => 'opportunity', 'product-families' => 'product_family', 'products' => 'product', 'product-versions' => 'product_version'];
    private Repository $repository;
    public function __construct(?Repository $repository = null) { $this->repository = $repository ?? new Repository(); }
    public function register(): void { add_action('rest_api_init', [$this, 'routes']); }
    public function routes(): void {
        foreach (self::ROUTES as $route => $type) {
            register_rest_route('digiforge/v1', '/' . $route, [
                ['methods' => 'GET', 'callback' => fn(\WP_REST_Request $request) => $this->index($request, $type), 'permission_callback' => [$this, 'can_view'], 'args' => [
                    'page' => ['default' => 1, 'sanitize_callback' => 'absint', 'validate_callback' => static fn($value) => is_numeric($value) && (int) $value >= 1],
                    'per_page' => ['default' => Repository::DEFAULT_PAGE_SIZE, 'sanitize_callback' => 'absint', 'validate_callback' => static fn($value) => is_numeric($value) && (int) $value >= 1 && (int) $value <= Repository::MAX_PAGE_SIZE],
                ]],
                ['methods' => 'POST', 'callback' => fn(\WP_REST_Request $request) => $this->create($request, $type), 'permission_callback' => [$this, 'can_manage']],
            ]);
            register_rest_route('digiforge/v1', '/' . $route . '/(?P<id>\d+)', ['methods' => 'GET', 'callback' => fn(\WP_REST_Request $request) => $this->show($request, $type), 'permission_callback' => [$this, 'can_view'], 'args' => ['id' => ['sanitize_callback' => 'absint']]]);
            register_rest_route('digiforge/v1', '/' . $route . '/(?P<id>\d+)/state', ['methods' => 'POST', 'callback' => fn(\WP_REST_Request $request) => $this->transition($request, $type), 'permission_callback' => [$this, 'can_manage'], 'args' => ['id' => ['sanitize_callback' => 'absint'], 'state' => ['required' => true, 'sanitize_callback' => 'sanitize_key']]]);
        }
    }
    public function can_view(\WP_REST_Request $request): bool { return Capabilities::can('manage_digiforge') || Capabilities::can('manage_digiforge_products'); }
    public function can_manage(\WP_REST_Request $request): bool { return Capabilities::can('manage_digiforge_products'); }
    private function index(\WP_REST_Request $request, string $type): \WP_REST_Response {
        $result = $this->repository->all($type, absint($request->get_param('page')), absint($request->get_param('per_page')));
        $response = new \WP_REST_Response($result, 200);
        $response->header('X-WP-Total', (string) $result['pagination']['total_items']);
        $response->header('X-WP-TotalPages', (string) $result['pagination']['total_pages']);
        return $response;
    }
    private function show(\WP_REST_Request $request, string $type): \WP_REST_Response|\WP_Error { $item = $this->repository->find($type, absint($request['id'])); return $item === null ? new \WP_Error('digiforge_not_found', __('Entity not found.', 'digiforge'), ['status' => 404]) : new \WP_REST_Response($item, 200); }
    private function create(\WP_REST_Request $request, string $type): \WP_REST_Response|\WP_Error {
        $key = sanitize_text_field((string) ($request->get_header('Idempotency-Key') ?: $request->get_param('idempotency_key')));
        $result = $this->repository->create($type, (array) $request->get_json_params(), $key === '' ? null : $key);
        if (is_wp_error($result)) { return $result; }
        $replay = (bool) ($result['idempotent_replay'] ?? false); unset($result['idempotent_replay']);
        $response = new \WP_REST_Response($result, $replay ? 200 : 201); if ($replay) { $response->header('Idempotent-Replay', 'true'); } return $response;
    }
    private function transition(\WP_REST_Request $request, string $type): \WP_REST_Response|\WP_Error { $result = $this->repository->transition($type, absint($request['id']), (string) $request->get_param('state')); return is_wp_error($result) ? $result : new \WP_REST_Response($result, 200); }
}
