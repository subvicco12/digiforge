<?php
declare(strict_types=1);
namespace DigiForge\Core;
final class Admin {
    public function register(): void { add_action('admin_menu', [$this, 'menu']); }
    public function menu(): void { add_menu_page('DigiForge', 'DigiForge', 'manage_digiforge', 'digiforge', [$this, 'render'], 'dashicons-shield', 58); add_submenu_page('digiforge', __('Products', 'digiforge'), __('Products', 'digiforge'), 'manage_digiforge_products', 'digiforge-products', [$this, 'products']); add_submenu_page('digiforge', __('Product Families', 'digiforge'), __('Product Families', 'digiforge'), 'manage_digiforge_products', 'digiforge-product-families', [$this, 'families']); add_submenu_page('digiforge', __('Opportunities', 'digiforge'), __('Opportunities', 'digiforge'), 'manage_digiforge_products', 'digiforge-opportunities', [$this, 'opportunities']); }
    public function render(): void {
        if (! current_user_can('manage_digiforge')) { wp_die(esc_html__('You are not allowed to access DigiForge.', 'digiforge')); }
        $switches = Config::SWITCHES; ?>
        <div class="wrap"><h1>DigiForge</h1><p><?php esc_html_e('System foundation active. All production automation is OFF by default.', 'digiforge'); ?></p>
        <h2><?php esc_html_e('Automation controls', 'digiforge'); ?></h2><table class="widefat"><tbody><tr><th>STOP ALL</th><td><strong><?php echo Settings::get('stop_all', false) ? 'ON' : 'OFF'; ?></strong></td></tr><?php foreach ($switches as $switch) : ?><tr><th><?php echo esc_html(ucwords(str_replace('_', ' ', $switch))); ?></th><td><?php echo Settings::get($switch, false) ? 'ON' : 'OFF'; ?></td></tr><?php endforeach; ?></tbody></table></div><?php
    }
    public function products(): void { $this->factory_page(__('Products', 'digiforge'), 'products'); }
    public function families(): void { $this->factory_page(__('Product Families', 'digiforge'), 'product-families'); }
    public function opportunities(): void { $this->factory_page(__('Opportunities', 'digiforge'), 'opportunities'); }
    private function factory_page(string $title, string $resource): void { if (! current_user_can('manage_digiforge_products')) { wp_die(esc_html__('You are not allowed to access Product Factory.', 'digiforge')); } ?><div class="wrap"><h1><?php echo esc_html($title); ?></h1><p><?php esc_html_e('Product Factory records are managed through the authenticated DigiForge REST API. No external publishing or automation is enabled.', 'digiforge'); ?></p><code><?php echo esc_html(rest_url('digiforge/v1/' . $resource)); ?></code></div><?php }
}
