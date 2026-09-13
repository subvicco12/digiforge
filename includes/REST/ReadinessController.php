<?php

declare(strict_types=1);

namespace DigiForge\REST;

use DigiForge\Core\Capabilities;
use DigiForge\Readiness\ReleaseReadiness;

/** Read-only release-readiness endpoints. No endpoint can activate automation. */
final class ReadinessController
{
    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
    }

    public function routes(): void
    {
        register_rest_route('digiforge/v1', '/readiness', [
            'methods' => 'GET',
            'callback' => [$this, 'readiness'],
            'permission_callback' => [$this, 'canView'],
        ]);
        register_rest_route('digiforge/v1', '/readiness/canary', [
            'methods' => 'GET',
            'callback' => [$this, 'canary'],
            'permission_callback' => [$this, 'canView'],
        ]);
        register_rest_route('digiforge/v1', '/readiness/emergency-stop-drill', [
            'methods' => 'GET',
            'callback' => [$this, 'emergencyStopDrill'],
            'permission_callback' => [$this, 'canView'],
        ]);
    }

    public function canView(\WP_REST_Request $request): bool
    {
        return Capabilities::can('manage_digiforge') || Capabilities::can('view_digiforge_analytics');
    }

    public function readiness(\WP_REST_Request $request): \WP_REST_Response
    {
        return new \WP_REST_Response((new ReleaseReadiness())->snapshot(), 200);
    }

    public function canary(\WP_REST_Request $request): \WP_REST_Response
    {
        return new \WP_REST_Response((new ReleaseReadiness())->canaryDryRun(), 200);
    }

    public function emergencyStopDrill(\WP_REST_Request $request): \WP_REST_Response
    {
        return new \WP_REST_Response((new ReleaseReadiness())->emergencyStopDrill(), 200);
    }
}
