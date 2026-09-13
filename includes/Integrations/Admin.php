<?php
declare(strict_types=1);
namespace DigiForge\Integrations;

/** Secure Integration Control Center for provider setup, credential rotation and explicit validation. */
final class Admin {
    public function register(): void {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_digiforge_integration_create', [$this, 'handleCreate']);
        add_action('admin_post_digiforge_integration_secret', [$this, 'handleSecret']);
        add_action('admin_post_digiforge_integration_update', [$this, 'handleUpdate']);
        add_action('admin_post_digiforge_integration_test', [$this, 'handleTest']);
        add_action('admin_post_digiforge_integration_toggle', [$this, 'handleToggle']);
        add_action('admin_post_digiforge_integration_delete', [$this, 'handleDelete']);
    }

    public function menu(): void {
        add_submenu_page('digiforge', __('Connections', 'digiforge'), __('Connections', 'digiforge'), 'manage_digiforge_connections', 'digiforge-connections', [$this, 'render']);
    }

    private function authorize(string $nonceAction): void {
        if (! current_user_can('manage_digiforge_connections')) { wp_die(esc_html__('You are not allowed to manage DigiForge connections.', 'digiforge')); }
        check_admin_referer($nonceAction);
    }

    private function redirect(string $notice, bool $error = false): never {
        wp_safe_redirect(add_query_arg(['page' => 'digiforge-connections', 'df_notice' => $notice, 'df_error' => $error ? '1' : '0'], admin_url('admin.php')));
        exit;
    }

    public function handleCreate(): void {
        $this->authorize('digiforge_integration_create');
        $preset = sanitize_key((string) ($_POST['provider_preset'] ?? ''));
        $custom = sanitize_key((string) ($_POST['provider_custom'] ?? ''));
        $provider = $preset === 'custom' ? $custom : $preset;
        $result = (new Repository())->create([
            'provider' => $provider,
            'environment' => sanitize_key((string) ($_POST['environment'] ?? 'production')),
            'connection_key' => sanitize_key((string) ($_POST['connection_key'] ?? '')),
            'display_name' => sanitize_text_field((string) ($_POST['display_name'] ?? '')),
            'status' => 'DISCONNECTED',
            'enabled' => false,
            'config' => [],
        ]);
        if (is_wp_error($result)) { $this->redirect($result->get_error_message(), true); }
        $this->redirect(__('Connector created. Add credentials, test it, then enable it when ready.', 'digiforge'));
    }

    public function handleSecret(): void {
        $this->authorize('digiforge_integration_secret');
        $id = absint($_POST['integration_id'] ?? 0);
        $name = sanitize_key((string) ($_POST['secret_name'] ?? ''));
        $value = isset($_POST['secret_value']) ? (string) wp_unslash($_POST['secret_value']) : '';
        $result = (new Repository())->storeSecret($id, $name, $value);
        if (is_wp_error($result)) { $this->redirect($result->get_error_message(), true); }
        $this->redirect(__('Credential stored securely. Saved values are never displayed again.', 'digiforge'));
    }

    public function handleUpdate(): void {
        $this->authorize('digiforge_integration_update');
        $id = absint($_POST['integration_id'] ?? 0);
        $configRaw = trim((string) wp_unslash($_POST['config'] ?? '{}'));
        $config = json_decode($configRaw, true);
        if (! is_array($config)) { $this->redirect(__('Configuration must be valid JSON.', 'digiforge'), true); }
        $result = (new Repository())->update($id, [
            'display_name' => sanitize_text_field((string) ($_POST['display_name'] ?? '')),
            'config' => $config,
        ]);
        if (is_wp_error($result)) { $this->redirect($result->get_error_message(), true); }
        $this->redirect(__('Connector settings updated.', 'digiforge'));
    }

    public function handleTest(): void {
        $this->authorize('digiforge_integration_test');
        $id = absint($_POST['integration_id'] ?? 0);
        $result = (new ConnectionTester())->test($id);
        if (is_wp_error($result)) { $this->redirect($result->get_error_message(), true); }
        $this->redirect(sanitize_text_field((string) ($result['message'] ?? __('Connection test completed successfully.', 'digiforge'))));
    }

    public function handleToggle(): void {
        $this->authorize('digiforge_integration_toggle');
        $id = absint($_POST['integration_id'] ?? 0);
        $enable = ! empty($_POST['enable']);
        $result = (new Repository())->setEnabled($id, $enable);
        if (is_wp_error($result)) { $this->redirect($result->get_error_message(), true); }
        $this->redirect($enable ? __('Connector enabled.', 'digiforge') : __('Connector disabled.', 'digiforge'));
    }

    public function handleDelete(): void {
        $this->authorize('digiforge_integration_delete');
        $id = absint($_POST['integration_id'] ?? 0);
        $result = (new Repository())->delete($id);
        if (is_wp_error($result)) { $this->redirect($result->get_error_message(), true); }
        $this->redirect(__('Connector and its encrypted credentials were deleted.', 'digiforge'));
    }

    private function statusClass(string $status): string {
        return match ($status) {
            'CONFIGURED' => 'df-status-ok',
            'ERROR' => 'df-status-error',
            'PAUSED' => 'df-status-warn',
            default => 'df-status-muted',
        };
    }

    public function render(): void {
        if (! current_user_can('manage_digiforge_connections')) { wp_die(esc_html__('You are not allowed to manage DigiForge connections.', 'digiforge')); }
        $page = max(1, isset($_GET['paged']) ? absint(wp_unslash($_GET['paged'])) : 1);
        $result = (new Repository())->all($page, 50); $items = $result['items']; $pagination = $result['pagination'];
        $providers = ProviderCatalog::providers();
        $notice = isset($_GET['df_notice']) ? sanitize_text_field(wp_unslash($_GET['df_notice'])) : '';
        $isError = ! empty($_GET['df_error']);
        ?>
        <div class="wrap df-connections">
            <style>
                .df-connections{max-width:1220px}.df-hero{display:flex;justify-content:space-between;gap:20px;align-items:flex-start;margin:18px 0 22px}.df-hero h1{font-size:28px;margin:0 0 6px}.df-sub{color:#646970;margin:0;max-width:760px}.df-summary{display:flex;gap:8px;flex-wrap:wrap}.df-pill,.df-status{display:inline-flex;align-items:center;border-radius:999px;padding:4px 10px;font-size:12px;font-weight:600}.df-pill{background:#eef2f6;color:#1d2327}.df-status-ok{background:#dff5e5;color:#116329}.df-status-error{background:#fce3e3;color:#8a1f1f}.df-status-warn{background:#fff3cd;color:#755b00}.df-status-muted{background:#eef0f2;color:#50575e}.df-panel{background:#fff;border:1px solid #dcdcde;border-radius:10px;box-shadow:0 1px 2px rgba(0,0,0,.04);padding:18px;margin:0 0 20px}.df-panel h2{margin-top:0}.df-create-grid{display:grid;grid-template-columns:repeat(5,minmax(140px,1fr));gap:12px;align-items:end}.df-field label{display:block;font-weight:600;margin-bottom:5px}.df-field input,.df-field select{width:100%;min-height:40px}.df-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(420px,1fr));gap:16px}.df-card{background:#fff;border:1px solid #dcdcde;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.05);overflow:hidden}.df-card-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;padding:17px 18px;border-bottom:1px solid #eef0f2}.df-card-title{font-size:17px;font-weight:700;margin:0 0 4px}.df-meta{color:#646970;font-size:12px}.df-card-body{padding:16px 18px}.df-kpis{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}.df-credentials{background:#f6f7f7;border-radius:8px;padding:12px;margin:0 0 14px}.df-credentials code{display:inline-block;margin:3px 5px 3px 0}.df-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:12px 0}.df-actions form{margin:0}.df-danger{color:#b32d2e!important;border-color:#d63638!important}.df-danger:hover{background:#d63638!important;color:#fff!important}.df-enable{background:#2271b1!important;border-color:#2271b1!important;color:#fff!important}.df-enable:hover{background:#135e96!important}.df-secret-form{display:grid;grid-template-columns:minmax(170px,220px) 1fr auto;gap:8px;align-items:center;margin-top:10px}.df-secret-form select,.df-secret-form input{min-height:38px}.df-test-note{font-size:12px;color:#646970;margin:6px 0 0}.df-advanced{margin-top:14px;border-top:1px solid #eef0f2;padding-top:12px}.df-advanced summary{cursor:pointer;font-weight:600}.df-advanced textarea{width:100%;font-family:monospace}.df-warning{background:#fff8e5;border-left:4px solid #dba617;padding:9px 11px;margin:10px 0}.df-lasttest{font-size:12px;color:#50575e;margin:6px 0 0}.df-empty{padding:30px;text-align:center;color:#646970}@media(max-width:900px){.df-create-grid{grid-template-columns:1fr 1fr}.df-cards{grid-template-columns:1fr}}@media(max-width:600px){.df-create-grid,.df-secret-form{grid-template-columns:1fr}.df-hero{display:block}.df-summary{margin-top:12px}}
            </style>
            <div class="df-hero">
                <div><h1><?php esc_html_e('DigiForge Connections', 'digiforge'); ?></h1><p class="df-sub"><?php esc_html_e('Manage provider credentials, run safe live connection tests, and control activation from one place. Credentials stay encrypted and connection tests never publish or place orders.', 'digiforge'); ?></p></div>
                <div class="df-summary"><span class="df-pill"><?php echo esc_html(sprintf(__('%d connectors', 'digiforge'), count($items))); ?></span><span class="df-pill"><?php echo esc_html(sprintf(__('%d enabled', 'digiforge'), count(array_filter($items, static fn(array $i): bool => ! empty($i['enabled']))))); ?></span></div>
            </div>
            <?php if ($notice !== '') : ?><div class="notice <?php echo $isError ? 'notice-error' : 'notice-success'; ?> is-dismissible"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>

            <div class="df-panel">
                <h2><?php esc_html_e('Add connector', 'digiforge'); ?></h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="digiforge_integration_create"><?php wp_nonce_field('digiforge_integration_create'); ?>
                    <div class="df-create-grid">
                        <div class="df-field"><label for="provider_preset"><?php esc_html_e('Provider', 'digiforge'); ?></label><select id="provider_preset" name="provider_preset"><?php foreach ($providers as $slug => $meta) : ?><option value="<?php echo esc_attr($slug); ?>"><?php echo esc_html($meta['label']); ?></option><?php endforeach; ?><option value="custom"><?php esc_html_e('Custom / future API', 'digiforge'); ?></option></select></div>
                        <div class="df-field"><label for="connection_key"><?php esc_html_e('Connection key', 'digiforge'); ?></label><input id="connection_key" name="connection_key" required placeholder="primary_store"></div>
                        <div class="df-field"><label for="display_name"><?php esc_html_e('Display name', 'digiforge'); ?></label><input id="display_name" name="display_name" placeholder="Provider — Store"></div>
                        <div class="df-field"><label for="environment"><?php esc_html_e('Environment', 'digiforge'); ?></label><select id="environment" name="environment"><option value="production">Production</option><option value="sandbox">Sandbox</option><option value="test">Test</option></select></div>
                        <div class="df-field"><button class="button button-primary" type="submit" style="min-height:40px;width:100%">+ <?php esc_html_e('Add connector', 'digiforge'); ?></button></div>
                    </div>
                    <p style="margin-bottom:0"><label><?php esc_html_e('Custom provider slug', 'digiforge'); ?> <input name="provider_custom" placeholder="shipstation"></label></p>
                </form>
            </div>

            <h2><?php esc_html_e('Configured connectors', 'digiforge'); ?></h2>
            <div class="df-cards">
            <?php if ($items === []) : ?><div class="df-panel df-empty"><?php esc_html_e('No connectors configured yet.', 'digiforge'); ?></div><?php endif; ?>
            <?php foreach ($items as $item) :
                $providerLabel = (string) ($item['provider_label'] ?? $item['provider']);
                $status = (string) $item['status'];
                $testMeta = is_array($item['config']['_connection_test'] ?? null) ? $item['config']['_connection_test'] : null;
                $legacyGelato = ($item['provider'] ?? '') === 'gelato' && in_array('personal_access_token', array_column($item['secrets'] ?? [], 'secret_name'), true) && ! in_array('api_key', array_column($item['secrets'] ?? [], 'secret_name'), true);
            ?>
                <section class="df-card">
                    <div class="df-card-head"><div><div class="df-card-title"><?php echo esc_html((string) $item['display_name']); ?></div><div class="df-meta"><?php echo esc_html($providerLabel . ' · ' . strtoupper((string) $item['environment']) . ' · ' . (string) $item['connection_key']); ?></div></div><span class="df-status <?php echo esc_attr($this->statusClass($status)); ?>"><?php echo esc_html($status); ?></span></div>
                    <div class="df-card-body">
                        <div class="df-kpis"><span class="df-pill"><?php echo $item['enabled'] ? esc_html__('● Enabled', 'digiforge') : esc_html__('○ Disabled', 'digiforge'); ?></span><span class="df-pill"><?php echo esc_html(sprintf(__('%d credential(s)', 'digiforge'), count($item['secrets'] ?? []))); ?></span></div>
                        <div class="df-credentials"><strong><?php esc_html_e('Credential vault', 'digiforge'); ?></strong><br><?php if (($item['secrets'] ?? []) === []) : ?><span class="df-meta"><?php esc_html_e('No credentials stored.', 'digiforge'); ?></span><?php else : foreach ($item['secrets'] as $secret) : ?><code><?php echo esc_html((string) $secret['secret_name']); ?> · <?php echo esc_html((string) $secret['fingerprint']); ?></code><?php endforeach; endif; ?></div>
                        <?php if ($legacyGelato) : ?><div class="df-warning"><strong><?php esc_html_e('Gelato credential detected under an old name.', 'digiforge'); ?></strong> <?php esc_html_e('The next Test Connection will securely normalize it to api_key automatically—no need to paste the key again.', 'digiforge'); ?></div><?php endif; ?>
                        <form class="df-secret-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="digiforge_integration_secret"><input type="hidden" name="integration_id" value="<?php echo esc_attr((string) $item['id']); ?>"><?php wp_nonce_field('digiforge_integration_secret'); ?>
                            <select name="secret_name" required><option value=""><?php esc_html_e('Choose credential…', 'digiforge'); ?></option><?php foreach (($item['suggested_secrets'] ?? []) as $name => $label) : ?><option value="<?php echo esc_attr((string) $name); ?>"><?php echo esc_html((string) $label . ' (' . (string) $name . ')'); ?></option><?php endforeach; ?></select>
                            <input name="secret_value" type="password" required autocomplete="new-password" placeholder="Paste credential value">
                            <button class="button" type="submit"><?php esc_html_e('Save', 'digiforge'); ?></button>
                        </form>
                        <div class="df-actions">
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="digiforge_integration_test"><input type="hidden" name="integration_id" value="<?php echo esc_attr((string) $item['id']); ?>"><?php wp_nonce_field('digiforge_integration_test'); ?><button class="button button-primary" type="submit">✓ <?php echo esc_html(sprintf(__('Test %s', 'digiforge'), $providerLabel)); ?></button></form>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js($item['enabled'] ? __('Disable this connector?', 'digiforge') : __('Enable this connector? It must already have a successful connection test.', 'digiforge')); ?>');"><input type="hidden" name="action" value="digiforge_integration_toggle"><input type="hidden" name="integration_id" value="<?php echo esc_attr((string) $item['id']); ?>"><input type="hidden" name="enable" value="<?php echo $item['enabled'] ? '0' : '1'; ?>"><?php wp_nonce_field('digiforge_integration_toggle'); ?><button class="button <?php echo $item['enabled'] ? '' : 'df-enable'; ?>" type="submit"><?php echo $item['enabled'] ? esc_html__('Disable', 'digiforge') : esc_html__('Enable', 'digiforge'); ?></button></form>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('Delete this connector and all of its stored encrypted credentials? This cannot be undone.', 'digiforge')); ?>');"><input type="hidden" name="action" value="digiforge_integration_delete"><input type="hidden" name="integration_id" value="<?php echo esc_attr((string) $item['id']); ?>"><?php wp_nonce_field('digiforge_integration_delete'); ?><button class="button df-danger" type="submit"><?php esc_html_e('Delete', 'digiforge'); ?></button></form>
                        </div>
                        <p class="df-test-note"><?php esc_html_e('Connection tests are provider-specific, read-only, and never enable automation.', 'digiforge'); ?></p>
                        <?php if ($testMeta) : ?><p class="df-lasttest"><strong><?php esc_html_e('Last test:', 'digiforge'); ?></strong> <?php echo ! empty($testMeta['ok']) ? esc_html__('Passed', 'digiforge') : esc_html__('Failed', 'digiforge'); ?> · <?php echo esc_html((string) ($testMeta['checked_at'] ?? '')); ?></p><?php endif; ?>
                        <details class="df-advanced"><summary><?php esc_html_e('Advanced settings', 'digiforge'); ?></summary><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="digiforge_integration_update"><input type="hidden" name="integration_id" value="<?php echo esc_attr((string) $item['id']); ?>"><?php wp_nonce_field('digiforge_integration_update'); ?><p><label><strong><?php esc_html_e('Display name', 'digiforge'); ?></strong><br><input name="display_name" class="regular-text" value="<?php echo esc_attr((string) $item['display_name']); ?>"></label></p><p><label><strong><?php esc_html_e('Non-secret configuration JSON', 'digiforge'); ?></strong><br><textarea name="config" rows="7"><?php echo esc_textarea((string) wp_json_encode($item['config'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></textarea></label></p><button class="button" type="submit"><?php esc_html_e('Save advanced settings', 'digiforge'); ?></button></form></details>
                    </div>
                </section>
            <?php endforeach; ?>
            </div>
            <?php if ($pagination['total_pages'] > 1) { echo wp_kses_post(paginate_links(['total' => $pagination['total_pages'], 'current' => $page, 'base' => add_query_arg('paged', '%#%')])); } ?>
        </div>
        <?php
    }
}
