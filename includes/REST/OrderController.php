<?php

declare(strict_types=1);

namespace DigiForge\REST;

use DigiForge\Orders\Repository;
use DigiForge\Orders\Validator;
use DigiForge\Queue\Idempotency;

final class OrderController
{
    private const NS = 'digiforge/v1';
    private const MAX_BODY_BYTES = 65536;

    public function register(): void
    {
        add_action('rest_api_init', function (): void {
            register_rest_route(self::NS, '/orders/(?P<entity>orders|items|personalizations|plans|intents|reviews)', ['methods' => 'GET', 'permission_callback' => [$this, 'canManage'], 'callback' => [$this, 'list']]);
            register_rest_route(self::NS, '/orders', ['methods' => 'POST', 'permission_callback' => [$this, 'canManage'], 'callback' => [$this, 'createOrder']]);
            register_rest_route(self::NS, '/orders/items', ['methods' => 'POST', 'permission_callback' => [$this, 'canManage'], 'callback' => [$this, 'createItem']]);
            register_rest_route(self::NS, '/orders/personalizations', ['methods' => 'POST', 'permission_callback' => [$this, 'canManage'], 'callback' => [$this, 'createPersonalization']]);
            register_rest_route(self::NS, '/orders/plans', ['methods' => 'POST', 'permission_callback' => [$this, 'canManage'], 'callback' => [$this, 'createPlan']]);
            register_rest_route(self::NS, '/orders/intents', ['methods' => 'POST', 'permission_callback' => [$this, 'canManage'], 'callback' => [$this, 'createIntent']]);
            register_rest_route(self::NS, '/orders/(?P<entity>order|plan|intent)/(?P<id>\d+)/state', ['methods' => 'POST', 'permission_callback' => [$this, 'canManage'], 'callback' => [$this, 'transition']]);
            register_rest_route(self::NS, '/orders/(?P<id>\d+)/readiness', ['methods' => 'GET', 'permission_callback' => [$this, 'canManage'], 'callback' => [$this, 'readiness']]);
        });
    }

    public function canManage(): bool
    {
        return current_user_can('manage_digiforge_orders');
    }

    public function list(\WP_REST_Request $request): \WP_REST_Response
    {
        $result = (new Repository())->list((string)$request['entity'], max(1, (int)($request->get_param('page') ?: 1)), min(100, max(1, (int)($request->get_param('per_page') ?: 20))));
        $response = new \WP_REST_Response($result['items']);
        $response->header('X-WP-Total', (string)$result['pagination']['total_items']);
        $response->header('X-WP-TotalPages', (string)$result['pagination']['total_pages']);
        return $response;
    }

    public function createOrder(\WP_REST_Request $request): mixed { return $this->mutate($request, 'order_create', fn() => (new Repository())->createOrder((array)$request->get_json_params(), $this->rawKey($request)), 201); }
    public function createItem(\WP_REST_Request $request): mixed { return $this->mutate($request, 'order_item_create', fn() => (new Repository())->addLineItem((array)$request->get_json_params(), $this->rawKey($request)), 201); }
    public function createPersonalization(\WP_REST_Request $request): mixed { return $this->mutate($request, 'order_personalization_create', fn() => (new Repository())->createPersonalization((array)$request->get_json_params(), $this->rawKey($request)), 201); }
    public function createPlan(\WP_REST_Request $request): mixed { return $this->mutate($request, 'fulfillment_plan_create', fn() => (new Repository())->createPlan((array)$request->get_json_params(), $this->rawKey($request)), 201); }
    public function createIntent(\WP_REST_Request $request): mixed { return $this->mutate($request, 'fulfillment_intent_create', fn() => (new Repository())->createIntent((array)$request->get_json_params(), $this->rawKey($request)), 201); }

    public function transition(\WP_REST_Request $request): mixed
    {
        $params = (array)$request->get_json_params();
        $state = (string)($params['state'] ?? '');
        return $this->mutate($request, 'order_state_' . sanitize_key((string)$request['entity']) . '_' . (int)$request['id'] . '_' . sanitize_key($state), fn() => (new Repository())->transition((string)$request['entity'], (int)$request['id'], $state));
    }

    public function readiness(\WP_REST_Request $request): mixed
    {
        $result = (new Repository())->readiness((int)$request['id']);
        return is_wp_error($result) ? $result : new \WP_REST_Response($result, 200);
    }

    private function rawKey(\WP_REST_Request $request): ?string
    {
        $key = trim((string)$request->get_header('Idempotency-Key'));
        return $key === '' ? null : $key;
    }

    private function mutate(\WP_REST_Request $request, string $operation, callable $callback, int $success = 200): mixed
    {
        if (strlen((string)$request->get_body()) > self::MAX_BODY_BYTES) {
            return new \WP_Error('payload_too_large', __('JSON body exceeds 64 KiB.', 'digiforge'), ['status' => 413]);
        }
        $params = (array)$request->get_json_params();
        try {
            Validator::structured($params);
        } catch (\InvalidArgumentException $e) {
            return new \WP_Error('validation', __($e->getMessage(), 'digiforge'), ['status' => 400]);
        }
        $header = trim((string)$request->get_header('Idempotency-Key'));
        if ($header === '') {
            return new \WP_Error('missing_idempotency_key', __('Idempotency-Key header is required.', 'digiforge'), ['status' => 400]);
        }
        $storage = hash('sha256', $operation . '|' . $header);
        $guard = new Idempotency();
        if (! $guard->reserve($storage, $operation)) {
            return new \WP_Error('idempotency_conflict', __('This order mutation has already been submitted.', 'digiforge'), ['status' => 409]);
        }
        try {
            $result = $callback();
        } catch (\Throwable) {
            $guard->release($storage);
            return new \WP_Error('order_mutation_failed', __('Order mutation failed.', 'digiforge'), ['status' => 500]);
        }
        if (is_wp_error($result)) {
            $guard->release($storage);
            return $result;
        }
        $encoded = wp_json_encode($result);
        if (! $guard->complete($storage, is_string($encoded) ? $encoded : '')) {
            return new \WP_Error('idempotency_finalize_failed', __('Mutation completed but idempotency state could not be finalized.', 'digiforge'), ['status' => 500]);
        }
        return new \WP_REST_Response($result, $success);
    }
}
