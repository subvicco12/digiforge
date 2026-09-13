<?php
declare(strict_types=1);
namespace DigiForge\Integrations;

/** Secure Integration Control Center for provider setup and credential rotation. */
final class Admin {
    public function register(): void {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_digiforge_integration_create', [$this, 'handleCreate']);
        add_action('admin_post_digiforge_integration_secret', [$this, 'handleSecret']);
        add_action('admin_post_digiforge_integration_update', [$this, 'handleUpdate']);
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
        $providerPreset = sanitize_key((string) ($_POST['provider_preset'] ?? ''));
        $providerCustom = sanitize_key((string) ($_POST['provider_custom'] ?? ''));
        $provider = $providerPreset === 'custom' ? $providerCustom : $providerPreset;
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
        $this->redirect(__('Integration created. Add credentials, configuration, and validate before enabling.', 'digiforge'));
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
            'status' => sanitize_key((string) ($_POST['status'] ?? 'DISCONNECTED')),
            'enabled' => ! empty($_POST['enabled']),
            'config' => $config,
        ]);
        if (is_wp_error($result)) { $this->redirect($result->get_error_message(), true); }
        $this->redirect(__('Integration updated.', 'digiforge'));
    }
    public function render(): void {
        if (! current_user_can('manage_digiforge_connections')) { wp_die(esc_html__('You are not allowed to manage DigiForge connections.', 'digiforge')); }
        $page = max(1, isset($_GET['paged']) ? absint(wp_unslash($_GET['paged'])) : 1);
        $result = (new Repository())->all($page, 50); $items = $result['items']; $pagination = $result['pagination'];
        $providers = ProviderCatalog::providers();
        $notice = isset($_GET['df_notice']) ? sanitize_text_field(wp_unslash($_GET['df_notice'])) : '';
        $isError = ! empty($_GET['df_error']);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('DigiForge Integration Control Center', 'digiforge'); ?></h1>
            <p><?php esc_html_e('Configure provider connections and encrypted credentials here. Saving credentials does not itself authorize external automation.', 'digiforge'); ?></p>
            <?php if ($notice !== '') : ?><div class="notice <?php echo $isError ? 'notice-error' : 'notice-success'; ?> is-dismissible"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>

            <h2><?php esc_html_e('Add integration', 'digiforge'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:900px;background:#fff;padding:16px;border:1px solid #ccd0d4">
                <input type="hidden" name="action" value="digiforge_integration_create">
                <?php wp_nonce_field('digiforge_integration_create'); ?>
                <table class="form-table" role="presentation">
                    <tr><th><label for="provider_preset">Provider</label></th><td><select id="provider_preset" name="provider_preset"><?php foreach ($providers as $slug => $meta) : ?><option value="<?php echo esc_attr($slug); ?>"><?php echo esc_html($meta['label']); ?></option><?php endforeach; ?><option value="custom"><?php esc_html_e('Custom / future API', 'digiforge'); ?></option></select></td></tr>
                    <tr><th><label for="provider_custom">Custom provider slug</label></th><td><input id="provider_custom" name="provider_custom" type="text" class="regular-text" placeholder="e.g. shipstation"></td></tr>
                    <tr><th><label for="connection_key">Connection key</label></th><td><input id="connection_key" name="connection_key" type="text" class="regular-text" required placeholder="e.g. primary_store"></td></tr>
                    <tr><th><label for="display_name">Display name</label></th><td><input id="display_name" name="display_name" type="text" class="regular-text" placeholder="e.g. Etsy — DigiCraftifyGoods"></td></tr>
                    <tr><th><label for="environment">Environment</label></th><td><select id="environment" name="environment"><option value="production">Production</option><option value="sandbox">Sandbox</option><option value="test">Test</option></select></td></tr>
                </table>
                <?php submit_button(__('Create integration', 'digiforge')); ?>
            </form>

            <h2><?php esc_html_e('Provider credential guide', 'digiforge'); ?></h2>
            <table class="widefat striped" style="max-width:1100px"><thead><tr><th>Provider</th><th>Authentication</th><th>Suggested secret names</th><th>Non-secret config</th></tr></thead><tbody>
            <?php foreach ($providers as $slug => $meta) : ?><tr><td><strong><?php echo esc_html($meta['label']); ?></strong></td><td><?php echo esc_html($meta['auth']); ?></td><td><?php echo esc_html(implode(', ', array_keys($meta['suggested_secrets']))); ?></td><td><?php echo esc_html(implode(', ', array_keys($meta['config_fields']))); ?></td></tr><?php endforeach; ?>
            </tbody></table>

            <h2><?php esc_html_e('Configured integrations', 'digiforge'); ?></h2>
            <?php if ($items === []) : ?><p><?php esc_html_e('No integrations configured.', 'digiforge'); ?></p><?php endif; ?>
            <?php foreach ($items as $item) : ?>
                <div style="background:#fff;border:1px solid #ccd0d4;padding:16px;margin:14px 0;max-width:1100px">
                    <h3><?php echo esc_html((string) ($item['provider_label'] ?? $item['provider'])); ?> — <?php echo esc_html((string) $item['display_name']); ?> <small>(<?php echo esc_html((string) $item['environment']); ?>)</small></h3>
                    <p><strong>Status:</strong> <?php echo esc_html((string) $item['status']); ?> &nbsp; <strong>Enabled:</strong> <?php echo $item['enabled'] ? esc_html__('Yes', 'digiforge') : esc_html__('No', 'digiforge'); ?></p>
                    <p><strong>Stored credentials:</strong> <?php if (($item['secrets'] ?? []) === []) { esc_html_e('None', 'digiforge'); } else { foreach ($item['secrets'] as $secret) { echo '<code>' . esc_html((string) $secret['secret_name']) . ' · ' . esc_html((string) $secret['fingerprint']) . '</code> '; } } ?></p>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:12px 0;padding:12px;background:#f6f7f7">
                        <input type="hidden" name="action" value="digiforge_integration_secret"><input type="hidden" name="integration_id" value="<?php echo esc_attr((string) $item['id']); ?>"><?php wp_nonce_field('digiforge_integration_secret'); ?>
                        <strong><?php esc_html_e('Store / rotate credential', 'digiforge'); ?></strong><br>
                        <input name="secret_name" type="text" required placeholder="secret name" list="df-secrets-<?php echo esc_attr((string) $item['id']); ?>"> <input name="secret_value" type="password" required class="regular-text" autocomplete="new-password" placeholder="credential value"> <?php submit_button(__('Save credential', 'digiforge'), 'secondary', 'submit', false); ?>
                        <datalist id="df-secrets-<?php echo esc_attr((string) $item['id']); ?>"><?php foreach (($item['suggested_secrets'] ?? []) as $name => $label) : ?><option value="<?php echo esc_attr((string) $name); ?>"><?php echo esc_html((string) $label); ?></option><?php endforeach; ?></datalist>
                        <p class="description"><?php esc_html_e('The value is encrypted at rest and never rendered back to the browser.', 'digiforge'); ?></p>
                    </form>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="digiforge_integration_update"><input type="hidden" name="integration_id" value="<?php echo esc_attr((string) $item['id']); ?>"><?php wp_nonce_field('digiforge_integration_update'); ?>
                        <p><label>Display name <input name="display_name" type="text" class="regular-text" value="<?php echo esc_attr((string) $item['display_name']); ?>"></label></p>
                        <p><label>Status <select name="status"><?php foreach (Repository::STATUSES as $status) : ?><option value="<?php echo esc_attr($status); ?>" <?php selected($status, $item['status']); ?>><?php echo esc_html($status); ?></option><?php endforeach; ?></select></label> &nbsp; <label><input type="checkbox" name="enabled" value="1" <?php checked((bool) $item['enabled']); ?>> Enabled</label></p>
                        <p><label>Non-secret configuration JSON<br><textarea name="config" rows="6" cols="90"><?php echo esc_textarea((string) wp_json_encode($item['config'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></textarea></label></p>
                        <p class="description"><?php esc_html_e('Secrets are rejected from configuration JSON. Enabling requires CONFIGURED status and at least one stored credential.', 'digiforge'); ?></p>
                        <?php submit_button(__('Update integration', 'digiforge'), 'secondary'); ?>
                    </form>
                </div>
            <?php endforeach; ?>
            <?php if ($pagination['total_pages'] > 1) { echo wp_kses_post(paginate_links(['total' => $pagination['total_pages'], 'current' => $page, 'base' => add_query_arg('paged', '%#%')])); } ?>
        </div><?php
    }
}
