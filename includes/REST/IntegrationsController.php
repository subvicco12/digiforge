<?php
declare(strict_types=1);
namespace DigiForge\REST;

use DigiForge\Integrations\Repository;
use DigiForge\Queue\Idempotency;

/** Authenticated local-only integration registry API. */
final class IntegrationsController {
    private const NS = 'digiforge/v1';
    private const ALLOW_BATCH = ['v1' => true];

    public function register(): void {
        add_action('rest_api_init', function (): void {
            register_rest_route(self::NS, '/integrations', [
                ['methods' => 'GET', 'callback' => [$this, 'index'], 'permission_callback' => [$this, 'canManage']],
                ['methods' => 'POST', 'callback' => [$this, 'create'], 'permission_callback' => [$this, 'canManage']],
                'allow_batch' => self::ALLOW_BATCH,
            ]);
            register_rest_route(self::NS, '/integrations/(?P<id>\d+)', [
                ['methods' => 'GET', 'callback' => [$this, 'show'], 'permission_callback' => [$this, 'canManage']],
                ['methods' => 'PATCH', 'callback' => [$this, 'update'], 'permission_callback' => [$this, 'canManage']],
                'allow_batch' => self::ALLOW_BATCH,
            ]);
            register_rest_route(self::NS, '/integrations/(?P<id>\d+)/secrets/(?P<name>[a-zA-Z0-9_-]+)', [
                ['methods' => 'PUT', 'callback' => [$this, 'putSecret'], 'permission_callback' => [$this, 'canManage']],
                'allow_batch' => self::ALLOW_BATCH,
            ]);
        });
    }
    public function canManage(): bool { return current_user_can('manage_digiforge_connections'); }
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
        return $this->mutate($request, 'integration_create', function () use ($request): array|\WP_Error {
            return (new Repository())->create((array) $request->get_json_params());
        }, 201);
    }
    public function update(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        return $this->mutate($request, 'integration_update_' . (int) $request['id'], function () use ($request): array|\WP_Error {
            return (new Repository())->update((int) $request['id'], (array) $request->get_json_params());
        });
    }
    public function putSecret(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        return $this->mutate($request, 'integration_secret_' . (int) $request['id'] . '_' . sanitize_key((string) $request['name']), function () use ($request): array|\WP_Error {
            $body = (array) $request->get_json_params(); $value = (string) ($body['value'] ?? '');
            $result = (new Repository())->storeSecret((int) $request['id'], (string) $request['name'], $value);
            return is_wp_error($result) ? $result : ['stored' => true, 'secret_name' => sanitize_key((string) $request['name'])];
        });
    }
    private function mutate(\WP_REST_Request $request, string $operation, callable $callback, int $successStatus = 200): \WP_REST_Response|\WP_Error {
        $header = trim((string) $request->get_header('Idempotency-Key'));
        if ($header === '') { return new \WP_Error('missing_idempotency_key', __('Idempotency-Key header is required.', 'digiforge'), ['status' => 400]); }
        $storageKey = hash('sha256', $operation . '|' . $header);
        $idempotency = new Idempotency();
        if (! $idempotency->reserve($storageKey, $operation)) { return new \WP_Error('idempotency_conflict', __('This mutation has already been submitted.', 'digiforge'), ['status' => 409]); }
        try { $result = $callback(); }
        catch (\Throwable $e) { $idempotency->release($storageKey); return new \WP_Error('integration_mutation_failed', __('Integration mutation failed.', 'digiforge'), ['status' => 500]); }
        if (is_wp_error($result)) { $idempotency->release($storageKey); return $result; }
        $encoded = wp_json_encode($result);
        if (! $idempotency->complete($storageKey, is_string($encoded) ? $encoded : '')) { return new \WP_Error('idempotency_finalize_failed', __('Mutation completed but idempotency state could not be finalized.', 'digiforge'), ['status' => 500]); }
        return new \WP_REST_Response($result, $successStatus);
    }
}
