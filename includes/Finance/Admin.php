<?php

declare(strict_types=1);

namespace DigiForge\Finance;

final class Admin
{
    public function register(): void
    {
        add_action('admin_menu', function (): void {
            add_submenu_page(
                'digiforge',
                __('DigiForge Finance', 'digiforge'),
                __('Finance & Analytics', 'digiforge'),
                'manage_digiforge_finance',
                'digiforge-finance',
                [$this, 'render']
            );
        });
    }

    public function render(): void
    {
        if (! current_user_can('manage_digiforge_finance')) {
            wp_die(esc_html__('You do not have permission to manage DigiForge finance.', 'digiforge'));
        }
        echo '<div class="wrap"><h1>' . esc_html__('DigiForge Finance & Analytics', 'digiforge') . '</h1>';
        echo '<p><strong>' . esc_html__('STOP ALL remains active.', 'digiforge') . '</strong> ';
        echo esc_html__('This area prepares local finance, profitability, tax-readiness, analytics and operational data only. It cannot move money, file tax/GST, issue refunds, send payouts, change ad spend, or sync to external accounting systems.', 'digiforge') . '</p>';
        echo '<p>' . esc_html__('Use the authenticated DigiForge REST endpoints or future reviewed admin workflows to create local ledger entries, FX snapshots, tax classifications, finance periods and inert finance intents.', 'digiforge') . '</p></div>';
    }
}
