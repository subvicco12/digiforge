<?php
declare(strict_types=1);

namespace DigiForge\Core;

use DigiForge\Observability\HealthMonitor;
use DigiForge\Operations\Readiness;
use DigiForge\ProductFactory\Repository;

final class Admin
{
    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
    }

    public function menu(): void
    {
        add_menu_page(
            'DigiForge',
            'DigiForge',
            'manage_digiforge',
            'digiforge',
            [$this, 'render'],
            'dashicons-shield',
            58
        );

        add_submenu_page(
            'digiforge',
            __('System Status', 'digiforge'),
            __('System Status', 'digiforge'),
            'manage_digiforge',
            'digiforge-system-status',
            [$this, 'render_system_status']
        );
        add_submenu_page(
            'digiforge',
            __('Readiness', 'digiforge'),
            __('Readiness', 'digiforge'),
            'manage_digiforge',
            'digiforge-readiness',
            [$this, 'render_readiness']
        );
        add_submenu_page(
            'digiforge',
            __('Safety & Controls', 'digiforge'),
            __('Safety & Controls', 'digiforge'),
            'manage_digiforge',
            'digiforge-safety-controls',
            [$this, 'render_safety_controls']
        );

        add_submenu_page(
            'digiforge',
            __('Product Factory', 'digiforge'),
            __('Product Factory', 'digiforge'),
            'manage_digiforge_products',
            'digiforge-product-factory',
            [$this, 'render_product_factory']
        );

        foreach (
            [
                'opportunity' => 'Opportunities',
                'product_family' => 'Product Families',
                'product' => 'Products',
                'product_version' => 'Product Versions',
            ] as $type => $label
        ) {
            add_submenu_page(
                'digiforge',
                __($label, 'digiforge'),
                __($label, 'digiforge'),
                'manage_digiforge_products',
                'digiforge-' . str_replace('_', '-', $type),
                fn() => $this->render_entities($type, $label)
            );
        }
    }

    public function render(): void
    {
        $this->guard('manage_digiforge');
        $readiness = (new Readiness())->report();
        $health = (new HealthMonitor())->snapshot();
        $modules = $this->module_links();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('DigiForge Control Center', 'digiforge'); ?></h1>
            <p><?php esc_html_e('Central operational view. External automation remains fail-closed and is not activated from this screen.', 'digiforge'); ?></p>

            <h2><?php esc_html_e('Executive status', 'digiforge'); ?></h2>
            <table class="widefat striped"><tbody>
                <tr><th><?php esc_html_e('Release', 'digiforge'); ?></th><td><?php echo esc_html(DIGIFORGE_VERSION); ?></td></tr>
                <tr><th><?php esc_html_e('Schema', 'digiforge'); ?></th><td><?php echo esc_html((string) ($readiness['schema']['current'] ?? 'unknown')); ?> / <?php echo esc_html((string) ($readiness['schema']['expected'] ?? 'unknown')); ?></td></tr>
                <tr><th><?php esc_html_e('Readiness', 'digiforge'); ?></th><td><strong><?php echo esc_html((string) ($readiness['status'] ?? 'REVIEW_REQUIRED')); ?></strong></td></tr>
                <tr><th><?php esc_html_e('System health', 'digiforge'); ?></th><td><strong><?php echo esc_html((string) ($health['status'] ?? 'UNKNOWN')); ?></strong></td></tr>
                <tr><th><?php esc_html_e('External execution lock', 'digiforge'); ?></th><td><strong><?php echo ! empty($readiness['externally_locked']) ? 'LOCKED' : 'UNLOCKED'; ?></strong></td></tr>
                <tr><th><?php esc_html_e('STOP ALL', 'digiforge'); ?></th><td><strong><?php echo Settings::get('stop_all', true) ? 'ON' : 'OFF'; ?></strong></td></tr>
            </tbody></table>

            <h2><?php esc_html_e('Operational modules', 'digiforge'); ?></h2>
            <table class="widefat striped"><thead><tr><th><?php esc_html_e('Area', 'digiforge'); ?></th><th><?php esc_html_e('Purpose', 'digiforge'); ?></th><th><?php esc_html_e('Open', 'digiforge'); ?></th></tr></thead><tbody>
            <?php foreach ($modules as $module) : ?>
                <tr>
                    <td><?php echo esc_html($module['label']); ?></td>
                    <td><?php echo esc_html($module['description']); ?></td>
                    <td><a class="button button-secondary" href="<?php echo esc_url(admin_url('admin.php?page=' . $module['slug'])); ?>"><?php esc_html_e('Open', 'digiforge'); ?></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table>

            <p><a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=digiforge-readiness')); ?>"><?php esc_html_e('View readiness evidence', 'digiforge'); ?></a>
            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=digiforge-system-status')); ?>"><?php esc_html_e('View system status', 'digiforge'); ?></a>
            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=digiforge-safety-controls')); ?>"><?php esc_html_e('View safety controls', 'digiforge'); ?></a></p>
        </div>
        <?php
    }

    public function render_system_status(): void
    {
        $this->guard('manage_digiforge');
        $health = (new HealthMonitor())->snapshot();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('DigiForge System Status', 'digiforge'); ?></h1>
            <p><?php esc_html_e('Read-only operational health snapshot.', 'digiforge'); ?></p>
            <table class="widefat striped"><tbody>
                <tr><th><?php esc_html_e('Overall status', 'digiforge'); ?></th><td><strong><?php echo esc_html((string) ($health['status'] ?? 'UNKNOWN')); ?></strong></td></tr>
                <tr><th><?php esc_html_e('Automation locked', 'digiforge'); ?></th><td><?php echo ! empty($health['automation_locked']) ? 'YES' : 'NO'; ?></td></tr>
                <tr><th><?php esc_html_e('Schema current', 'digiforge'); ?></th><td><?php echo esc_html((string) ($health['schema']['current'] ?? 'unknown')); ?></td></tr>
                <tr><th><?php esc_html_e('Schema expected', 'digiforge'); ?></th><td><?php echo esc_html((string) ($health['schema']['expected'] ?? 'unknown')); ?></td></tr>
                <tr><th><?php esc_html_e('Audit status', 'digiforge'); ?></th><td><?php echo esc_html((string) ($health['audit']['status'] ?? 'UNKNOWN')); ?></td></tr>
                <tr><th><?php esc_html_e('Queue status', 'digiforge'); ?></th><td><?php echo esc_html((string) ($health['queue']['status'] ?? 'UNKNOWN')); ?></td></tr>
                <tr><th><?php esc_html_e('Queue query OK', 'digiforge'); ?></th><td><?php echo ! empty($health['queue']['query_ok']) ? 'YES' : 'NO'; ?></td></tr>
                <tr><th><?php esc_html_e('Expired leases', 'digiforge'); ?></th><td><?php echo esc_html((string) ($health['queue']['expired_leases'] ?? 0)); ?></td></tr>
                <tr><th><?php esc_html_e('Dead letters', 'digiforge'); ?></th><td><?php echo esc_html((string) ($health['queue']['dead_letters'] ?? 0)); ?></td></tr>
            </tbody></table>
        </div>
        <?php
    }

    public function render_readiness(): void
    {
        $this->guard('manage_digiforge');
        $readiness = (new Readiness())->report();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('DigiForge Readiness', 'digiforge'); ?></h1>
            <p><?php esc_html_e('Read-only production-readiness and recovery evidence. Evidence flags must correspond to real evidence.', 'digiforge'); ?></p>

            <table class="widefat striped"><tbody>
                <tr><th><?php esc_html_e('Readiness status', 'digiforge'); ?></th><td><strong><?php echo esc_html((string) ($readiness['status'] ?? 'REVIEW_REQUIRED')); ?></strong></td></tr>
                <tr><th><?php esc_html_e('Evidence hash', 'digiforge'); ?></th><td><code><?php echo esc_html((string) ($readiness['evidence_hash'] ?? '')); ?></code></td></tr>
                <tr><th><?php esc_html_e('External actions performed', 'digiforge'); ?></th><td><?php echo ! empty($readiness['external_actions_performed']) ? 'YES' : 'NO'; ?></td></tr>
            </tbody></table>

            <h2><?php esc_html_e('Readiness checks', 'digiforge'); ?></h2>
            <?php $this->render_boolean_table($readiness['checks'] ?? []); ?>

            <h2><?php esc_html_e('Recovery evidence', 'digiforge'); ?></h2>
            <?php $this->render_boolean_table($readiness['recovery']['checks'] ?? []); ?>
            <p><strong><?php esc_html_e('Recovery status:', 'digiforge'); ?></strong> <?php echo esc_html((string) ($readiness['recovery']['status'] ?? 'REVIEW_REQUIRED')); ?></p>
            <p><strong><?php esc_html_e('Recovery evidence hash:', 'digiforge'); ?></strong> <code><?php echo esc_html((string) ($readiness['recovery']['evidence_hash'] ?? '')); ?></code></p>
        </div>
        <?php
    }

    public function render_safety_controls(): void
    {
        $this->guard('manage_digiforge');
        $readiness = (new Readiness())->report();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('DigiForge Safety & Controls', 'digiforge'); ?></h1>
            <p><?php esc_html_e('Read-only safety state. This screen intentionally provides no activation controls.', 'digiforge'); ?></p>
            <table class="widefat striped"><tbody>
                <tr><th>STOP ALL</th><td><strong><?php echo Settings::get('stop_all', true) ? 'ON' : 'OFF'; ?></strong></td></tr>
                <tr><th><?php esc_html_e('Activation authorized', 'digiforge'); ?></th><td><strong><?php echo Settings::get('activation_authorized', false) ? 'YES' : 'NO'; ?></strong></td></tr>
                <tr><th><?php esc_html_e('Automation armed', 'digiforge'); ?></th><td><strong><?php echo Settings::get('automation_armed', false) ? 'YES' : 'NO'; ?></strong></td></tr>
                <tr><th><?php esc_html_e('Externally locked', 'digiforge'); ?></th><td><strong><?php echo ! empty($readiness['externally_locked']) ? 'YES' : 'NO'; ?></strong></td></tr>
                <tr><th><?php esc_html_e('External actions performed', 'digiforge'); ?></th><td><strong><?php echo ! empty($readiness['external_actions_performed']) ? 'YES' : 'NO'; ?></strong></td></tr>
            </tbody></table>

            <h2><?php esc_html_e('Effective feature switches', 'digiforge'); ?></h2>
            <table class="widefat striped"><tbody>
            <?php foreach (($readiness['effective_switches'] ?? []) as $switch => $enabled) : ?>
                <tr><th><?php echo esc_html(ucwords(str_replace('_', ' ', (string) $switch))); ?></th><td><?php echo $enabled ? 'ON' : 'OFF'; ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
        </div>
        <?php
    }

    public function render_product_factory(): void
    {
        $this->guard('manage_digiforge_products');
        $versions = (new Repository())->all('product_version', 1, Repository::MAX_PAGE_SIZE);
        $stateCounts = [];
        foreach (($versions['items'] ?? []) as $version) {
            if (! is_array($version)) { continue; }
            $state = strtoupper((string) ($version['state'] ?? 'UNKNOWN'));
            $stateCounts[$state] = ($stateCounts[$state] ?? 0) + 1;
        }
        ksort($stateCounts);
        ?>
        <div class="wrap"><h1><?php esc_html_e('Product Factory', 'digiforge'); ?></h1>
        <p><?php esc_html_e('Read-only portfolio visibility. Automation and external side effects remain disabled; approvals continue through the authenticated workflow.', 'digiforge'); ?></p>
        <h2><?php esc_html_e('Portfolio snapshot', 'digiforge'); ?></h2>
        <table class="widefat striped"><tbody>
        <tr><th><?php esc_html_e('Product versions sampled', 'digiforge'); ?></th><td><?php echo esc_html((string) count($versions['items'] ?? [])); ?></td></tr>
        <?php foreach ($stateCounts as $state => $count) : ?>
        <tr><th><?php echo esc_html(sprintf(__('State: %s', 'digiforge'), $state)); ?></th><td><?php echo esc_html((string) $count); ?></td></tr>
        <?php endforeach; ?>
        <tr><th><?php esc_html_e('External actions performed', 'digiforge'); ?></th><td><strong>NO</strong></td></tr>
        </tbody></table>
        <p><?php esc_html_e('Snapshot reports persisted product-version states only, bounded to the most recent 100 versions. It does not infer workflow readiness, schedule, approve, publish, or call external providers.', 'digiforge'); ?></p>
        <h2><?php esc_html_e('Entity foundations', 'digiforge'); ?></h2>
        <table class="widefat striped"><thead><tr><th><?php esc_html_e('Entity', 'digiforge'); ?></th><th><?php esc_html_e('Initial state', 'digiforge'); ?></th></tr></thead><tbody>
        <tr><td><?php esc_html_e('Opportunities', 'digiforge'); ?></td><td>NEW</td></tr><tr><td><?php esc_html_e('Product Families', 'digiforge'); ?></td><td>DRAFT</td></tr><tr><td><?php esc_html_e('Products', 'digiforge'); ?></td><td>DRAFT</td></tr><tr><td><?php esc_html_e('Product Versions', 'digiforge'); ?></td><td>DRAFT</td></tr>
        </tbody></table></div>
        <?php
    }

    private function render_entities(string $type, string $label): void
    {
        $this->guard('manage_digiforge_products');
        $items = (new Repository())->all($type)['items'];
        ?>
        <div class="wrap"><h1><?php echo esc_html($label); ?></h1><p><?php esc_html_e('Records are created and transitioned through the authenticated Product Factory REST API.', 'digiforge'); ?></p>
        <table class="widefat striped"><thead><tr><th><?php esc_html_e('ID', 'digiforge'); ?></th><th><?php esc_html_e('Name / version', 'digiforge'); ?></th><th><?php esc_html_e('State', 'digiforge'); ?></th><th><?php esc_html_e('Updated (UTC)', 'digiforge'); ?></th></tr></thead><tbody>
        <?php if ($items === []) : ?><tr><td colspan="4"><?php esc_html_e('No records found.', 'digiforge'); ?></td></tr><?php endif; ?>
        <?php foreach ($items as $item) : ?><tr><td><?php echo esc_html((string) $item['id']); ?></td><td><?php echo esc_html((string) ($item['title'] ?? $item['name'] ?? $item['version_label'] ?? '')); ?></td><td><?php echo esc_html((string) $item['state']); ?></td><td><?php echo esc_html((string) $item['updated_at']); ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <?php
    }

    /** @param array<string, mixed> $checks */
    private function render_boolean_table(array $checks): void
    {
        ?>
        <table class="widefat striped"><tbody>
        <?php foreach ($checks as $check => $passed) : ?>
            <tr><th><?php echo esc_html(ucwords(str_replace('_', ' ', (string) $check))); ?></th><td><?php echo $passed ? 'PASS' : 'REVIEW'; ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
        <?php
    }

    /** @return array<int, array{label:string,slug:string,description:string}> */
    private function module_links(): array
    {
        return [
            ['label' => 'Research Intelligence', 'slug' => 'digiforge-research', 'description' => 'Research evidence and opportunity review.'],
            ['label' => 'AI Governance', 'slug' => 'digiforge-ai-governance', 'description' => 'AI usage, review and governance records.'],
            ['label' => 'Product Factory', 'slug' => 'digiforge-product-factory', 'description' => 'Opportunities, product families, products and versions.'],
            ['label' => 'Production', 'slug' => 'digiforge-production', 'description' => 'Internal production planning and records.'],
            ['label' => 'Digital Products', 'slug' => 'digiforge-digital-product', 'description' => 'Digital product, package, file and QA records.'],
            ['label' => 'POD & Personalization', 'slug' => 'digiforge-pod', 'description' => 'POD providers, templates and personalization foundations.'],
            ['label' => 'Listings & Etsy Drafts', 'slug' => 'digiforge-listings', 'description' => 'Listing preparation and Etsy draft packages.'],
            ['label' => 'Orders', 'slug' => 'digiforge-orders', 'description' => 'Local order and fulfillment planning records.'],
            ['label' => 'Finance & Analytics', 'slug' => 'digiforge-finance', 'description' => 'Ledger, FX, tax classification and analytics foundations.'],
            ['label' => 'Connections', 'slug' => 'digiforge-connections', 'description' => 'Integration registry and credential-state review.'],
        ];
    }

    private function guard(string $capability): void
    {
        if (! current_user_can($capability)) {
            wp_die(esc_html__('You are not allowed to access this DigiForge area.', 'digiforge'));
        }
    }
}
