<?php

declare(strict_types=1);

namespace DigiForge\REST;

use DigiForge\Core\Capabilities;
use DigiForge\Launch\ExecutionEngine;
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

    public function status(): \WP_REST_Response
    {
        return new \WP_REST_Response([
            'engine' => 'launch-execution-v1',
            'research_endpoint' => '/digiforge/v1/launch/research',
            'development_endpoint' => '/digiforge/v1/launch/candidates/{id}/develop',
            'approval_gate' => 'research candidate must be APPROVED before development',
            'etsy_publish_direct' => false,
        ]);
    }

    public function research(\WP_REST_Request $request): mixed
    {
        return $this->mutate(
            $request,
            'launch_research',
            fn(string $key) => (new ExecutionEngine())->research((array) $request->get_json_params(), $key),
            201
        );
    }

    public function develop(\WP_REST_Request $request): mixed
    {
        return $this->mutate(
            $request,
            'launch_develop_' . (int) $request['id'],
            fn(string $key) => (new ExecutionEngine())->develop((int) $request['id'], (array) $request->get_json_params(), $key),
            201
        );
    }

    private function mutate(\WP_REST_Request $request, string $operation, callable $callback, int $successStatus): mixed
    {
        $header = trim((string) $request->get_header('Idempotency-Key'));
        if ($header === '') {
            return new \WP_Error('missing_idempotency_key', __('Idempotency-Key header is required.', 'digiforge'), ['status' => 400]);
        }
        $storageKey = hash('sha256', $operation . '|' . $header);
        $idempotency = new Idempotency();
        if (! $idempotency->reserve($storageKey, $operation)) {
            return new \WP_Error('idempotency_conflict', __('This launch mutation has already been submitted.', 'digiforge'), ['status' => 409]);
        }
        try {
            $result = $callback($header);
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
