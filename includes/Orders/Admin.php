<?php

declare(strict_types=1);

namespace DigiForge\Orders;

final class Admin
{
    public function register(): void
    {
        add_action('admin_menu', function (): void {
            add_submenu_page(
                'digiforge',
                __('Orders & Fulfillment', 'digiforge'),
                __('Orders & Fulfillment', 'digiforge'),
                'manage_digiforge_orders',
                'digiforge-orders',
                [$this, 'render']
            );
        });
    }

    public function render(): void
    {
        if (! current_user_can('manage_digiforge_orders')) {
            wp_die(esc_html__('You do not have permission to manage DigiForge orders.', 'digiforge'));
        }
        echo '<div class="wrap"><h1>' . esc_html__('DigiForge Orders & Controlled Fulfillment', 'digiforge') . '</h1>';
        echo '<p><strong>' . esc_html__('STOP ALL remains active.', 'digiforge') . '</strong> ';
        echo esc_html__('This screen manages local order snapshots, reviews, fulfillment plans and inert intents only. No Etsy or POD provider request is sent from Batch 9.', 'digiforge') . '</p>';
        echo '<p>' . esc_html__('Personalized-order auto-approval, provider auto-approval, webhook processing, fulfillment submission, refunds, cancellations and shipping mutations remain disabled.', 'digiforge') . '</p></div>';
    }
}
