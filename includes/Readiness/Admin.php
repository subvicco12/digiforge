<?php

declare(strict_types=1);

namespace DigiForge\Readiness;

final class Admin
{
    public function register(): void
    {
        add_action('admin_menu', function (): void {
            add_submenu_page(
                'digiforge',
                __('DigiForge Readiness', 'digiforge'),
                __('Release Readiness', 'digiforge'),
                'manage_digiforge',
                'digiforge-readiness',
                [$this, 'render']
            );
        });
    }

    public function render(): void
    {
        if (! current_user_can('manage_digiforge')) {
            wp_die(esc_html__('You do not have permission to view DigiForge readiness.', 'digiforge'));
        }

        $service = new ReleaseReadiness();
        $report = $service->snapshot();
        $canary = $service->canaryDryRun();
        $drill = $service->emergencyStopDrill();

        echo '<div class="wrap"><h1>' . esc_html__('DigiForge Release Readiness', 'digiforge') . '</h1>';
        echo '<p><strong>' . esc_html__('Live external activation remains disabled.', 'digiforge') . '</strong> ';
        echo esc_html__('This screen is read-only and cannot arm automation, disable STOP ALL, call providers, enqueue jobs, publish listings, fulfill orders, move money, or file tax/GST.', 'digiforge') . '</p>';
        echo '<h2>' . esc_html__('Readiness status', 'digiforge') . '</h2>';
        echo '<p><code>' . esc_html((string) ($report['status'] ?? 'REVIEW_REQUIRED')) . '</code></p>';
        echo '<h2>' . esc_html__('Canary dry run', 'digiforge') . '</h2>';
        echo '<p><code>' . esc_html((string) ($canary['mode'] ?? 'DRY_RUN_ONLY')) . '</code> — ';
        echo esc_html__('zero provider calls and zero queued jobs.', 'digiforge') . '</p>';
        echo '<h2>' . esc_html__('Emergency-stop drill', 'digiforge') . '</h2>';
        echo '<p><code>' . esc_html((string) ($drill['status'] ?? 'REVIEW_REQUIRED')) . '</code></p>';
        echo '<p>' . esc_html__('Explicit owner approval for live external activation is not granted in this release.', 'digiforge') . '</p></div>';
    }
}
