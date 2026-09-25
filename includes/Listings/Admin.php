<?php

declare(strict_types=1);

namespace DigiForge\Listings;

use DigiForge\Database\Tables;

final class Admin
{
    private const SAMPLE_LIMIT = 100;

    public function register(): void
    {
        add_action('admin_menu', function (): void {
            add_submenu_page('digiforge','Listing & Etsy Drafts','Listing & Etsy Drafts','manage_digiforge_listings','digiforge-listings',[$this,'render']);
        });
    }

    public function render(): void
    {
        if (! current_user_can('manage_digiforge_listings')) { wp_die(esc_html__('You do not have permission to access this page.','digiforge')); }
        $summary = AdminStateSummary::summarize($this->recentListingStates());

        echo '<div class="wrap"><h1>'.esc_html__('DigiForge Listing & Etsy Draft Foundation','digiforge').'</h1>';
        echo '<p>'.esc_html__('Local review and preparation only. STOP ALL remains active. No Etsy API call, OAuth exchange, remote draft creation, upload, publishing, inventory mutation, order processing, fulfillment, worker, schedule, or deployment can occur from this surface.','digiforge').'</p>';
        echo '<h2>'.esc_html__('Listing state snapshot','digiforge').'</h2>';
        echo '<table class="widefat striped"><tbody>';
        echo '<tr><th>'.esc_html__('Listings sampled','digiforge').'</th><td>'.esc_html((string)$summary['total']).'</td></tr>';
        foreach ($summary['states'] as $state => $count) {
            echo '<tr><th>'.esc_html(sprintf(__('State: %s','digiforge'),$state)).'</th><td>'.esc_html((string)$count).'</td></tr>';
        }
        echo '<tr><th>'.esc_html__('Invalid / unknown states','digiforge').'</th><td>'.esc_html((string)$summary['invalid']).'</td></tr>';
        echo '<tr><th>'.esc_html__('Gate 3 approval required','digiforge').'</th><td><strong>YES</strong></td></tr>';
        echo '<tr><th>'.esc_html__('Etsy API invoked','digiforge').'</th><td><strong>NO</strong></td></tr>';
        echo '<tr><th>'.esc_html__('External actions performed','digiforge').'</th><td><strong>NO</strong></td></tr>';
        echo '</tbody></table>';
        echo '<p>'.esc_html__('Snapshot is read-only and bounded to the most recent 100 local listing records. It does not infer readiness or perform lifecycle transitions.','digiforge').'</p>';
        echo '<p>'.esc_html__('Use the capability-gated REST API for deterministic local records and reviews. Human approval is mandatory before local release readiness.','digiforge').'</p></div>';
    }

    /** @return array<int,array{state:mixed}> */
    private function recentListingStates(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare('SELECT state FROM '.Tables::listings().' ORDER BY id DESC LIMIT %d', self::SAMPLE_LIMIT),
            ARRAY_A
        );
        return is_array($rows) ? $rows : [];
    }
}
