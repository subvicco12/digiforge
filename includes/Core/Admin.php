<?php
declare(strict_types=1);
namespace DigiForge\Core;
use DigiForge\ProductFactory\Repository;
final class Admin {
    public function register(): void { add_action('admin_menu', [$this, 'menu']); }
    public function menu(): void {
        add_menu_page('DigiForge', 'DigiForge', 'manage_digiforge', 'digiforge', [$this, 'render'], 'dashicons-shield', 58);
        add_submenu_page('digiforge', __('Product Factory', 'digiforge'), __('Product Factory', 'digiforge'), 'manage_digiforge_products', 'digiforge-product-factory', [$this, 'render_product_factory']);
        foreach (['opportunity' => 'Opportunities', 'product_family' => 'Product Families', 'product' => 'Products', 'product_version' => 'Product Versions'] as $type => $label) {
            add_submenu_page('digiforge', __($label, 'digiforge'), __($label, 'digiforge'), 'manage_digiforge_products', 'digiforge-' . str_replace('_', '-', $type), fn() => $this->render_entities($type, $label));
        }
    }
    public function render(): void {
        if (! current_user_can('manage_digiforge')) { wp_die(esc_html__('You are not allowed to access DigiForge.', 'digiforge')); }
        $switches = Config::SWITCHES; ?>
        <div class="wrap"><h1>DigiForge</h1><p><?php esc_html_e('System foundation active. All production automation is OFF by default.', 'digiforge'); ?></p>
        <h2><?php esc_html_e('Automation controls', 'digiforge'); ?></h2><table class="widefat"><tbody><tr><th>STOP ALL</th><td><strong><?php echo Settings::get('stop_all', false) ? 'ON' : 'OFF'; ?></strong></td></tr><?php foreach ($switches as $switch) : ?><tr><th><?php echo esc_html(ucwords(str_replace('_', ' ', $switch))); ?></th><td><?php echo Settings::get($switch, false) ? 'ON' : 'OFF'; ?></td></tr><?php endforeach; ?></tbody></table></div><?php
    }
    public function render_product_factory(): void {
        if (! current_user_can('manage_digiforge_products')) { wp_die(esc_html__('You are not allowed to manage products.', 'digiforge')); }
        ?>
        <div class="wrap"><h1><?php esc_html_e('Product Factory', 'digiforge'); ?></h1>
        <p><?php esc_html_e('Manage opportunities, product families, products, and product versions through the DigiForge REST API. Automation and external side effects remain disabled.', 'digiforge'); ?></p>
        <table class="widefat striped"><thead><tr><th><?php esc_html_e('Entity', 'digiforge'); ?></th><th><?php esc_html_e('Initial state', 'digiforge'); ?></th></tr></thead><tbody>
        <tr><td><?php esc_html_e('Opportunities', 'digiforge'); ?></td><td>NEW</td></tr><tr><td><?php esc_html_e('Product Families', 'digiforge'); ?></td><td>DRAFT</td></tr><tr><td><?php esc_html_e('Products', 'digiforge'); ?></td><td>DRAFT</td></tr><tr><td><?php esc_html_e('Product Versions', 'digiforge'); ?></td><td>DRAFT</td></tr>
        </tbody></table></div><?php
    }
    private function render_entities(string $type, string $label): void {
        if (! current_user_can('manage_digiforge_products')) { wp_die(esc_html__('You are not allowed to manage products.', 'digiforge')); }
        $items = (new Repository())->all($type)['items']; ?>
        <div class="wrap"><h1><?php echo esc_html($label); ?></h1><p><?php esc_html_e('Records are created and transitioned through the authenticated Product Factory REST API.', 'digiforge'); ?></p>
        <table class="widefat striped"><thead><tr><th><?php esc_html_e('ID', 'digiforge'); ?></th><th><?php esc_html_e('Name / version', 'digiforge'); ?></th><th><?php esc_html_e('State', 'digiforge'); ?></th><th><?php esc_html_e('Updated (UTC)', 'digiforge'); ?></th></tr></thead><tbody>
        <?php if ($items === []) : ?><tr><td colspan="4"><?php esc_html_e('No records found.', 'digiforge'); ?></td></tr><?php endif; ?>
        <?php foreach ($items as $item) : ?><tr><td><?php echo esc_html((string) $item['id']); ?></td><td><?php echo esc_html((string) ($item['title'] ?? $item['name'] ?? $item['version_label'] ?? '')); ?></td><td><?php echo esc_html((string) $item['state']); ?></td><td><?php echo esc_html((string) $item['updated_at']); ?></td></tr><?php endforeach; ?>
        </tbody></table></div><?php
    }
}
