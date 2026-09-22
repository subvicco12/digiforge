<?php

declare(strict_types=1);

namespace DigiForge\REST;

use DigiForge\Core\Capabilities;
use DigiForge\Launch\ExecutionEngine;
use DigiForge\ProductFactory\Orchestrator;
use DigiForge\ProductFactory\ProductReview;
use DigiForge\Queue\Idempotency;

final class LaunchController
{
    private const NS = 'digiforge/v1';

    public function register(): void
    {
        add_action('rest_api_init', function (): void {
            register_rest_route(self::NS, '/launch/status', [
                'methods' => 'GET',
                'permission_callback' => [$this, 'canManage'],
                'callback' => [$this, 'status'],
            ]);
            register_rest_route(self::NS, '/launch/research', [
                'methods' => 'POST',
                'permission_callback' => [$this, 'canResearch'],
                'callback' => [$this, 'research'],
            ]);
            register_rest_route(self::NS, '/launch/candidates/(?P<id>\d+)/develop', [
                'methods' => 'POST',
                'permission_callback' => [$this, 'canDevelop'],
                'callback' => [$this, 'develop'],
            ]);
            register_rest_route(self::NS, '/launch/candidates/(?P<id>\d+)/build-product', [
                'methods' => 'POST',
                'permission_callback' => [$this, 'canBuildProduct'],
                'callback' => [$this, 'buildProduct'],
            ]);
            register_rest_route(self::NS, '/launch/product-versions/(?P<id>\d+)/review', [
                'methods' => 'POST',
                'permission_callback' => [$this, 'canReviewProduct'],
                'callback' => [$this, 'reviewProduct'],
            ]);
        });
    }

    public function canManage(): bool
    {
        return Capabilities::can('manage_digiforge');
    }

    public function canResearch(): bool
    {
        return Capabilities::can('manage_digiforge_research') && Capabilities::can('manage_digiforge_ai');
    }

    public function canDevelop(): bool
    {
        return Capabilities::can('manage_digiforge_products') && Capabilities::can('manage_digiforge_ai');
    }

    public function canBuildProduct(): bool
    {
        return $this->canDevelop() && Capabilities::can('manage_digiforge_production');
    }

    public function canReviewProduct(): bool
    {
        return Capabilities::can('manage_digiforge_products') && Capabilities::can('manage_digiforge_production');
    }

    public function status(): \WP_REST_Response
    {
        return new \WP_REST_Response([
            'engine' => 'launch-execution-u3',
            'research_endpoint' => '/digiforge/v1/launch/research',
            'development_endpoint' => '/digiforge/v1/launch/candidates/{id}/develop',
            'product_build_endpoint' => '/digiforge/v1/launch/candidates/{id}/build-product',
            'product_review_endpoint' => '/digiforge/v1/launch/product-versions/{id}/review',
            'listing_factory_endpoint' => '/digiforge/v1/launch/product-versions/{id}/prepare-listing',
            'approval_gates' => [
                'opportunity' => 'research candidate must be APPROVED before development or production',
                'product' => 'generated assets and QA stop at PRODUCT_REVIEW_REQUIRED until explicit review',
                'listing_publish' => 'listing preparation stops at LISTING_REVIEW_REQUIRED until explicit Gate 3 review',
            ],
            'asset_separation' => 'product assets and marketing/listing assets are stored as distinct asset types',
            'idempotency' => 'Idempotency-Key header preferred; authenticated connector clients may send _idempotency_key in JSON body.',
            'etsy_publish_direct' => false,
            'printify_execution' => false,
            'gelato_execution' => false,
            'order_execution' => false,
            'gst_execution' => false,
        ]);
    }

    public function research(\WP_REST_Request $request): mixed
    {
        $payload = (array) $request->get_json_params();
        unset($payload['_idempotency_key']);
        return $this->mutate($request, 'launch_research', fn(string $key) => (new ExecutionEngine())->research($payload, $key), 201);
    }

    public function develop(\WP_REST_Request $request): mixed
    {
        $payload = (array) $request->get_json_params();
        unset($payload['_idempotency_key']);
        return $this->mutate(
            $request,
            'launch_develop_' . (int) $request['id'],
            fn(string $key) => (new ExecutionEngine())->develop((int) $request['id'], $payload, $key),
            201
        );
    }

    public function buildProduct(\WP_REST_Request $request): mixed
    {
        $payload = (array) $request->get_json_params();
        unset($payload['_idempotency_key']);
        return $this->mutate(
            $request,
            'u3_build_product_' . (int) $request['id'],
            fn(string $key) => (new Orchestrator())->build((int) $request['id'], $payload, $key),
            201
        );
    }

    public function reviewProduct(\WP_REST_Request $request): mixed
    {
        $payload = (array) $request->get_json_params();
        $decision = isset($payload['decision']) ? (string) $payload['decision'] : '';
        $notes = isset($payload['notes']) ? (string) $payload['notes'] : '';
        unset($payload['_idempotency_key']);
        return $this->mutate(
            $request,
            'u3_product_review_' . (int) $request['id'],
            fn(string $key) => (new ProductReview())->decide((int) $request['id'], $decision, $notes),
            200
        );
    }

    private function mutate(\WP_REST_Request $request, string $operation, callable $callback, int $successStatus): mixed
    {
        $idempotencyKey = trim((string) $request->get_header('Idempotency-Key'));
        if ($idempotencyKey === '') {
            $params = (array) $request->get_json_params();
            $bodyKeyPresent = array_key_exists('_idempotency_key', $params);
            $bodyKey = $bodyKeyPresent ? $params['_idempotency_key'] : null;
            if ($bodyKeyPresent && ! is_string($bodyKey)) {
                return new \WP_Error('invalid_idempotency_key', __('The _idempotency_key JSON field must be a string.', 'digiforge'), ['status' => 400]);
            }
            $idempotencyKey = is_string($bodyKey) ? trim($bodyKey) : '';
        }
        if ($idempotencyKey === '') {
            return new \WP_Error('missing_idempotency_key', __('Idempotency-Key header or _idempotency_key JSON field is required.', 'digiforge'), ['status' => 400]);
        }
        if (strlen($idempotencyKey) > 191) {
            return new \WP_Error('invalid_idempotency_key', __('Idempotency key is too long.', 'digiforge'), ['status' => 400]);
        }

        $storageKey = hash('sha256', $operation . '|' . $idempotencyKey);
        $idempotency = new Idempotency();
        if (! $idempotency->reserve($storageKey, $operation)) {
            return new \WP_Error('idempotency_conflict', __('This launch mutation has already been submitted.', 'digiforge'), ['status' => 409]);
        }
        try {
            $result = $callback($idempotencyKey);
        } catch (\Throwable $e) {
            $idempotency->release($storageKey);
            return new \WP_Error('digiforge_launch_failed', __('Launch execution failed.', 'digiforge'), ['status' => 500]);
        }
        if (is_wp_error($result)) {
            $idempotency->release($storageKey);
            return $result;
        }
        $encoded = wp_json_encode($result);
        if (! $idempotency->complete($storageKey, is_string($encoded) ? $encoded : '')) {
            return new \WP_Error('idempotency_finalize_failed', __('Launch execution completed but could not finalize idempotency state.', 'digiforge'), ['status' => 500]);
        }
        return new \WP_REST_Response($result, $successStatus);
    }
}
