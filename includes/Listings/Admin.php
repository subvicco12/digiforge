<?php

declare(strict_types=1);

namespace DigiForge\Listings;

final class Admin
{
    public function register(): void
    {
        add_action('admin_menu', function (): void {
            add_submenu_page('digiforge','Listing & Etsy Drafts','Listing & Etsy Drafts','manage_digiforge_listings','digiforge-listings',[$this,'render']);
        });
    }

    public function render(): void
    {
        if (! current_user_can('manage_digiforge_listings')) { wp_die(esc_html__('You do not have permission to access this page.','digiforge')); }
        echo '<div class="wrap"><h1>'.esc_html__('DigiForge Listing & Etsy Draft Foundation','digiforge').'</h1>';
        echo '<p>'.esc_html__('Local review and preparation only. STOP ALL remains active. No Etsy API call, OAuth exchange, remote draft creation, upload, publishing, inventory mutation, order processing, fulfillment, worker, schedule, or deployment can occur from this surface.','digiforge').'</p>';
        echo '<p>'.esc_html__('Use the capability-gated REST API for deterministic local records and reviews. Human approval is mandatory before local release readiness.','digiforge').'</p></div>';
    }
}
