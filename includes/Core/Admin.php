<?php
declare(strict_types=1);
namespace DigiForge\Core;
final class Admin {
    public function register(): void { add_action('admin_menu', [$this, 'menu']); }
    public function menu(): void { add_menu_page('DigiForge', 'DigiForge', 'manage_digiforge', 'digiforge', [$this, 'render'], 'dashicons-shield', 58); }
    public function render(): void {
        if (! current_user_can('manage_digiforge')) { wp_die(esc_html__('You are not allowed to access DigiForge.', 'digiforge')); }
        $switches = Config::SWITCHES; ?>
        <div class="wrap"><h1>DigiForge</h1><p><?php esc_html_e('System foundation active. All production automation is OFF by default.', 'digiforge'); ?></p>
        <h2><?php esc_html_e('Automation controls', 'digiforge'); ?></h2><table class="widefat"><tbody><tr><th>STOP ALL</th><td><strong><?php echo Settings::get('stop_all', false) ? 'ON' : 'OFF'; ?></strong></td></tr><?php foreach ($switches as $switch) : ?><tr><th><?php echo esc_html(ucwords(str_replace('_', ' ', $switch))); ?></th><td><?php echo Settings::get($switch, false) ? 'ON' : 'OFF'; ?></td></tr><?php endforeach; ?></tbody></table></div><?php
    }
}
