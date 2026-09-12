<?php
declare(strict_types=1);
namespace DigiForge\Research;

/** Read-only research review surface. Batch 4 does not run collectors or schedules. */
final class Admin {
    public function register(): void { add_action('admin_menu', [$this, 'menu']); }
    public function menu(): void {
        add_submenu_page('digiforge', __('Research', 'digiforge'), __('Research', 'digiforge'), 'manage_digiforge_research', 'digiforge-research', [$this, 'render']);
    }
    public function render(): void {
        if (! current_user_can('manage_digiforge_research')) { wp_die(esc_html__('You are not allowed to manage DigiForge research.', 'digiforge')); }
        $page = max(1, isset($_GET['paged']) ? absint(wp_unslash($_GET['paged'])) : 1);
        $result = (new Repository())->list('candidates', $page, 50);
        $items = $result['items']; $pagination = $result['pagination'];
        ?>
        <div class="wrap"><h1><?php esc_html_e('DigiForge Research Review', 'digiforge'); ?></h1>
        <p><?php esc_html_e('Read-only candidate review queue. Research collection, scraping, AI execution, schedules, marketplace actions and publishing remain disabled.', 'digiforge'); ?></p>
        <table class="widefat striped"><thead><tr><th>ID</th><th><?php esc_html_e('Candidate', 'digiforge'); ?></th><th><?php esc_html_e('Score', 'digiforge'); ?></th><th><?php esc_html_e('Score version', 'digiforge'); ?></th><th><?php esc_html_e('Review', 'digiforge'); ?></th><th><?php esc_html_e('Opportunity', 'digiforge'); ?></th></tr></thead><tbody>
        <?php if ($items === []) : ?><tr><td colspan="6"><?php esc_html_e('No research candidates.', 'digiforge'); ?></td></tr><?php endif; ?>
        <?php foreach ($items as $item) : ?><tr><td><?php echo esc_html((string) $item['id']); ?></td><td><?php echo esc_html((string) $item['title']); ?></td><td><?php echo esc_html((string) $item['score']); ?></td><td><?php echo esc_html((string) $item['score_version']); ?></td><td><?php echo esc_html((string) $item['review_status']); ?></td><td><?php echo esc_html((string) ($item['opportunity_id'] ?? 0)); ?></td></tr><?php endforeach; ?>
        </tbody></table>
        <?php if ($pagination['total_pages'] > 1) { echo wp_kses_post(paginate_links(['total' => $pagination['total_pages'], 'current' => $page, 'base' => add_query_arg('paged', '%#%')])); } ?>
        </div><?php
    }
}
