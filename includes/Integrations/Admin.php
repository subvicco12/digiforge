<?php
declare(strict_types=1);
namespace DigiForge\Integrations;

/** Read-only Connections admin view for the Batch 3 local integration foundation. */
final class Admin {
    public function register(): void { add_action('admin_menu', [$this, 'menu']); }
    public function menu(): void {
        add_submenu_page('digiforge', __('Connections', 'digiforge'), __('Connections', 'digiforge'), 'manage_digiforge_connections', 'digiforge-connections', [$this, 'render']);
    }
    public function render(): void {
        if (! current_user_can('manage_digiforge_connections')) { wp_die(esc_html__('You are not allowed to manage DigiForge connections.', 'digiforge')); }
        $page = max(1, isset($_GET['paged']) ? absint(wp_unslash($_GET['paged'])) : 1);
        $result = (new Repository())->all($page, 50); $items = $result['items']; $pagination = $result['pagination'];
        ?>
        <div class="wrap"><h1><?php esc_html_e('DigiForge Connections', 'digiforge'); ?></h1>
        <p><?php esc_html_e('Integration records and credential metadata only. No provider connection, health request, publishing, fulfillment, or automation is executed in this phase.', 'digiforge'); ?></p>
        <table class="widefat striped"><thead><tr><th>ID</th><th><?php esc_html_e('Provider', 'digiforge'); ?></th><th><?php esc_html_e('Connection', 'digiforge'); ?></th><th><?php esc_html_e('Status', 'digiforge'); ?></th><th><?php esc_html_e('Enabled', 'digiforge'); ?></th><th><?php esc_html_e('Stored credentials', 'digiforge'); ?></th></tr></thead><tbody>
        <?php if ($items === []) : ?><tr><td colspan="6"><?php esc_html_e('No integrations configured.', 'digiforge'); ?></td></tr><?php endif; ?>
        <?php foreach ($items as $item) : ?><tr><td><?php echo esc_html((string) $item['id']); ?></td><td><?php echo esc_html((string) $item['provider']); ?></td><td><?php echo esc_html((string) $item['display_name']); ?></td><td><?php echo esc_html((string) $item['status']); ?></td><td><?php echo $item['enabled'] ? esc_html__('Yes', 'digiforge') : esc_html__('No', 'digiforge'); ?></td><td><?php echo esc_html((string) count($item['secrets'] ?? [])); ?></td></tr><?php endforeach; ?>
        </tbody></table>
        <?php if ($pagination['total_pages'] > 1) { echo wp_kses_post(paginate_links(['total' => $pagination['total_pages'], 'current' => $page, 'base' => add_query_arg('paged', '%#%')])); } ?>
        </div><?php
    }
}
