<?php
declare(strict_types=1);
namespace DigiForge\Integrations;

/** Secure Integration Control Center for provider setup, credential rotation and explicit validation. */
final class Admin {
    public function register(): void {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_digiforge_integration_create', [$this, 'handleCreate']);
        add_action('admin_post_digiforge_integration_secret', [$this, 'handleSecret']);
        add_action('admin_post_digiforge_integration_secret_delete', [$this, 'handleSecretDelete']);
        add_action('admin_post_digiforge_integration_update', [$this, 'handleUpdate']);
        add_action('admin_post_digiforge_integration_test', [$this, 'handleTest']);
        add_action('admin_post_digiforge_integration_toggle', [$this, 'handleToggle']);
        add_action('admin_post_digiforge_integration_delete', [$this, 'handleDelete']);
        add_action('admin_post_digiforge_gelato_normalize_key', [$this, 'handleGelatoNormalizeKey']);
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

    public function handleSecretDelete(): void {
        $this->authorize('digiforge_integration_secret_delete');
        $id = absint($_POST['integration_id'] ?? 0);
        $name = sanitize_key((string) ($_POST['secret_name'] ?? ''));
        $result = (new Repository())->deleteSecret($id, $name);
        if (is_wp_error($result)) { $this->redirect($result->get_error_message(), true); }
        $this->redirect(__('Credential deleted. The connector was disabled and must be tested again before re-enabling.', 'digiforge'));
    }

    public function handleGelatoNormalizeKey(): void {
        $this->authorize('digiforge_gelato_normalize_key');
        $id = absint($_POST['integration_id'] ?? 0);
        $integration = (new Repository())->find($id);
        if (! $integration || ($integration['provider'] ?? '') !== 'gelato') { $this->redirect(__('Gelato connector not found.', 'digiforge'), true); }
        $result = (new Repository())->migrateSecretName($id, 'personal_access_token', 'api_key');
        if (is_wp_error($result)) { $this->redirect($result->get_error_message(), true); }
        $this->redirect(__('Gelato credential repaired successfully. It is now stored as api_key and ready for testing.', 'digiforge'));
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

    private function providerDocs(string $provider): string {
        return match ($provider) {
            'etsy' => 'https://developers.etsy.com/documentation/essentials/authentication/',
            'printify' => 'https://developers.printify.com/',
            'gelato' => 'https://dashboard.gelato.com/docs/',
            'ai' => 'https://platform.openai.com/docs/api-reference/authentication',
            default => '',
        };
    }

    public function render(): void {
        if (! current_user_can('manage_digiforge_connections')) { wp_die(esc_html__('You are not allowed to manage DigiForge connections.', 'digiforge')); }
        $page = max(1, isset($_GET['paged']) ? absint(wp_unslash($_GET['paged'])) : 1);
        $result = (new Repository())->all($page, 50); $items = $result['items']; $pagination = $result['pagination'];
        $providers = ProviderCatalog::providers();
        $notice = isset($_GET['df_notice']) ? sanitize_text_field(wp_unslash($_GET['df_notice'])) : '';
        $isError = ! empty($_GET['df_error']);
        $configured = count(array_filter($items, static fn(array $i): bool => ($i['status'] ?? '') === 'CONFIGURED'));
        $enabled = count(array_filter($items, static fn(array $i): bool => ! empty($i['enabled'])));
        ?>
        <div class="wrap df-connections">
            <style>
                .df-connections{max-width:1280px}.df-hero{display:flex;justify-content:space-between;gap:20px;align-items:flex-start;margin:18px 0 22px}.df-hero h1{font-size:30px;margin:0 0 6px}.df-sub{color:#646970;margin:0;max-width:800px;font-size:14px}.df-summary{display:flex;gap:8px;flex-wrap:wrap}.df-pill,.df-status{display:inline-flex;align-items:center;border-radius:999px;padding:5px 10px;font-size:12px;font-weight:600}.df-pill{background:#eef2f6;color:#1d2327}.df-status-ok{background:#dff5e5;color:#116329}.df-status-error{background:#fce3e3;color:#8a1f1f}.df-status-warn{background:#fff3cd;color:#755b00}.df-status-muted{background:#eef0f2;color:#50575e}.df-panel{background:#fff;border:1px solid #dcdcde;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.05);padding:18px;margin:0 0 20px}.df-panel h2{margin-top:0}.df-create-grid{display:grid;grid-template-columns:repeat(5,minmax(140px,1fr));gap:12px;align-items:end}.df-field label{display:block;font-weight:600;margin-bottom:5px}.df-field input,.df-field select{width:100%;min-height:40px}.df-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(500px,1fr));gap:18px}.df-card{background:#fff;border:1px solid #dcdcde;border-radius:14px;box-shadow:0 3px 12px rgba(0,0,0,.06);overflow:hidden}.df-card-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;padding:18px 20px;border-bottom:1px solid #eef0f2;background:linear-gradient(180deg,#fff,#fbfcfd)}.df-card-title{font-size:18px;font-weight:700;margin:0 0 4px}.df-provider{display:inline-flex;width:32px;height:32px;border-radius:9px;background:#eef4ff;color:#135e96;align-items:center;justify-content:center;font-weight:800;margin-right:9px}.df-title-row{display:flex;align-items:center}.df-meta{color:#646970;font-size:12px}.df-card-body{padding:18px 20px}.df-kpis{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}.df-steps{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin:0 0 16px}.df-step{border:1px solid #e2e4e7;border-radius:9px;padding:10px;background:#fafafa;font-size:12px}.df-step strong{display:block;margin-bottom:3px}.df-step-ok{border-color:#b9e6c4;background:#f0fbf3}.df-credentials{background:#f6f7f7;border-radius:10px;padding:13px;margin:0 0 14px}.df-secret-row{display:flex;align-items:center;justify-content:space-between;gap:10px;border-top:1px solid #e2e4e7;padding:9px 0}.df-secret-row:first-of-type{margin-top:8px}.df-secret-name{font-weight:600}.df-fingerprint{font-family:monospace;font-size:11px;color:#646970}.df-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:14px 0}.df-actions form{margin:0}.df-danger{color:#b32d2e!important;border-color:#d63638!important}.df-danger:hover{background:#d63638!important;color:#fff!important}.df-enable{background:#2271b1!important;border-color:#2271b1!important;color:#fff!important}.df-enable:hover{background:#135e96!important}.df-secret-form{display:grid;grid-template-columns:minmax(190px,230px) 1fr auto;gap:8px;align-items:center;margin-top:12px}.df-secret-form select,.df-secret-form input{min-height:40px}.df-test-note{font-size:12px;color:#646970;margin:6px 0 0}.df-advanced{margin-top:14px;border-top:1px solid #eef0f2;padding-top:12px}.df-advanced summary{cursor:pointer;font-weight:600}.df-advanced textarea{width:100%;font-family:monospace}.df-warning{background:#fff8e5;border-left:4px solid #dba617;padding:10px 12px;margin:10px 0;border-radius:4px}.df-lasttest{font-size:12px;color:#50575e;margin:6px 0 0}.df-help{display:flex;justify-content:space-between;gap:10px;align-items:center;margin-top:12px;padding-top:12px;border-top:1px solid #eef0f2}.df-help a{text-decoration:none}.df-empty{padding:30px;text-align:center;color:#646970}@media(max-width:1000px){.df-create-grid{grid-template-columns:1fr 1fr}.df-cards{grid-template-columns:1fr}}@media(max-width:650px){.df-create-grid,.df-secret-form,.df-steps{grid-template-columns:1fr}.df-hero{display:block}.df-summary{margin-top:12px}.df-secret-row{align-items:flex-start;flex-direction:column}}
            </style>
            <div class="df-hero">
                <div><h1><?php esc_html_e('DigiForge Connections', 'digiforge'); ?></h1><p class="df-sub"><?php esc_html_e('A secure control center for provider credentials, live read-only connection tests and activation. Credentials remain encrypted and are never displayed after saving.', 'digiforge'); ?></p></div>
                <div class="df-summary"><span class="df-pill"><?php echo esc_html(sprintf(__('%d connectors', 'digiforge'), count($items))); ?></span><span class="df-pill"><?php echo esc_html(sprintf(__('%d configured', 'digiforge'), $configured)); ?></span><span class="df-pill"><?php echo esc_html(sprintf(__('%d enabled', 'digiforge'), $enabled)); ?></span></div>
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
                $provider = (string) ($item['provider'] ?? '');
                $providerLabel = (string) ($item['provider_label'] ?? $provider);
                $status = (string) $item['status'];
                $secrets = is_array($item['secrets'] ?? null) ? $item['secrets'] : [];
                $secretNames = array_column($secrets, 'secret_name');
                $testMeta = is_array($item['config']['_connection_test'] ?? null) ? $item['config']['_connection_test'] : null;
                $legacyGelato = $provider === 'gelato' && in_array('personal_access_token', $secretNames, true) && ! in_array('api_key', $secretNames, true);
                $hasCredential = $secrets !== [];
                $testPassed = is_array($testMeta) && ! empty($testMeta['ok']);
                $docs = $this->providerDocs($provider);
            ?>
                <section class="df-card">
                    <div class="df-card-head"><div class="df-title-row"><span class="df-provider"><?php echo esc_html(strtoupper(substr($providerLabel, 0, 2))); ?></span><div><div class="df-card-title"><?php echo esc_html((string) $item['display_name']); ?></div><div class="df-meta"><?php echo esc_html($providerLabel . ' · ' . strtoupper((string) $item['environment']) . ' · ' . (string) $item['connection_key']); ?></div></div></div><span class="df-status <?php echo esc_attr($this->statusClass($status)); ?>"><?php echo esc_html($status); ?></span></div>
                    <div class="df-card-body">
                        <div class="df-kpis"><span class="df-pill"><?php echo $item['enabled'] ? esc_html__('● Enabled', 'digiforge') : esc_html__('○ Automation off', 'digiforge'); ?></span><span class="df-pill"><?php echo esc_html(sprintf(__('%d credential(s)', 'digiforge'), count($secrets))); ?></span></div>
                        <div class="df-steps"><div class="df-step <?php echo $hasCredential ? 'df-step-ok' : ''; ?>"><strong>1. <?php esc_html_e('Credentials', 'digiforge'); ?></strong><?php echo $hasCredential ? esc_html__('Stored securely', 'digiforge') : esc_html__('Add required key', 'digiforge'); ?></div><div class="df-step <?php echo $testPassed ? 'df-step-ok' : ''; ?>"><strong>2. <?php esc_html_e('Test', 'digiforge'); ?></strong><?php echo $testPassed ? esc_html__('Verified', 'digiforge') : esc_html__('Run connection test', 'digiforge'); ?></div><div class="df-step <?php echo $item['enabled'] ? 'df-step-ok' : ''; ?>"><strong>3. <?php esc_html_e('Automation', 'digiforge'); ?></strong><?php echo $item['enabled'] ? esc_html__('Enabled', 'digiforge') : esc_html__('Kept off', 'digiforge'); ?></div></div>

                        <div class="df-credentials"><strong><?php esc_html_e('Credential vault', 'digiforge'); ?></strong><?php if ($secrets === []) : ?><p class="df-meta"><?php esc_html_e('No credentials stored yet.', 'digiforge'); ?></p><?php else : foreach ($secrets as $secret) : ?><div class="df-secret-row"><div><div class="df-secret-name"><?php echo esc_html((string) $secret['secret_name']); ?></div><div class="df-fingerprint"><?php echo esc_html((string) $secret['fingerprint']); ?> · <?php echo esc_html((string) ($secret['updated_at'] ?? '')); ?></div></div><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(sprintf(__('Delete credential %s? The connector will be disabled and its previous connection test cleared.', 'digiforge'), (string) $secret['secret_name'])); ?>');"><input type="hidden" name="action" value="digiforge_integration_secret_delete"><input type="hidden" name="integration_id" value="<?php echo esc_attr((string) $item['id']); ?>"><input type="hidden" name="secret_name" value="<?php echo esc_attr((string) $secret['secret_name']); ?>"><?php wp_nonce_field('digiforge_integration_secret_delete'); ?><button class="button button-small df-danger" type="submit"><?php esc_html_e('Delete key', 'digiforge'); ?></button></form></div><?php endforeach; endif; ?></div>

                        <?php if ($legacyGelato) : ?><div class="df-warning"><strong><?php esc_html_e('Gelato key needs a one-time label repair.', 'digiforge'); ?></strong> <?php esc_html_e('The existing encrypted value is stored as personal_access_token, while Gelato officially authenticates with X-API-KEY. You do not need to paste the key again.', 'digiforge'); ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px"><input type="hidden" name="action" value="digiforge_gelato_normalize_key"><input type="hidden" name="integration_id" value="<?php echo esc_attr((string) $item['id']); ?>"><?php wp_nonce_field('digiforge_gelato_normalize_key'); ?><button class="button" type="submit"><?php esc_html_e('Repair Gelato key label', 'digiforge'); ?></button></form></div><?php endif; ?>

                        <form class="df-secret-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="digiforge_integration_secret"><input type="hidden" name="integration_id" value="<?php echo esc_attr((string) $item['id']); ?>"><?php wp_nonce_field('digiforge_integration_secret'); ?>
                            <select name="secret_name" required><option value=""><?php esc_html_e('Choose credential…', 'digiforge'); ?></option><?php foreach (($item['suggested_secrets'] ?? []) as $name => $label) : ?><option value="<?php echo esc_attr((string) $name); ?>"><?php echo esc_html((string) $label . ' (' . (string) $name . ')'); ?></option><?php endforeach; ?></select>
                            <input name="secret_value" type="password" required autocomplete="new-password" placeholder="Paste new / replacement credential">
                            <button class="button" type="submit"><?php esc_html_e('Save / Replace', 'digiforge'); ?></button>
                        </form>

                        <div class="df-actions">
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="digiforge_integration_test"><input type="hidden" name="integration_id" value="<?php echo esc_attr((string) $item['id']); ?>"><?php wp_nonce_field('digiforge_integration_test'); ?><button class="button button-primary" type="submit">✓ <?php echo esc_html(sprintf(__('Test %s connection', 'digiforge'), $providerLabel)); ?></button></form>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js($item['enabled'] ? __('Disable this connector?', 'digiforge') : __('Enable this connector? It must already have a successful connection test.', 'digiforge')); ?>');"><input type="hidden" name="action" value="digiforge_integration_toggle"><input type="hidden" name="integration_id" value="<?php echo esc_attr((string) $item['id']); ?>"><input type="hidden" name="enable" value="<?php echo $item['enabled'] ? '0' : '1'; ?>"><?php wp_nonce_field('digiforge_integration_toggle'); ?><button class="button <?php echo $item['enabled'] ? '' : 'df-enable'; ?>" type="submit"><?php echo $item['enabled'] ? esc_html__('Disable automation', 'digiforge') : esc_html__('Enable automation', 'digiforge'); ?></button></form>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('Delete this connector and all stored encrypted credentials? This cannot be undone.', 'digiforge')); ?>');"><input type="hidden" name="action" value="digiforge_integration_delete"><input type="hidden" name="integration_id" value="<?php echo esc_attr((string) $item['id']); ?>"><?php wp_nonce_field('digiforge_integration_delete'); ?><button class="button df-danger" type="submit"><?php esc_html_e('Delete connector', 'digiforge'); ?></button></form>
                        </div>
                        <p class="df-test-note"><?php esc_html_e('Tests use provider-specific, audited, read-only requests. They never publish products, place orders or enable automation.', 'digiforge'); ?></p>
                        <?php if ($testMeta) : ?><p class="df-lasttest"><strong><?php esc_html_e('Last test:', 'digiforge'); ?></strong> <?php echo ! empty($testMeta['ok']) ? esc_html__('Passed', 'digiforge') : esc_html__('Failed', 'digiforge'); ?> · <?php echo esc_html((string) ($testMeta['checked_at'] ?? '')); ?></p><?php endif; ?>
                        <div class="df-help"><span class="df-meta"><?php esc_html_e('Need setup help?', 'digiforge'); ?></span><?php if ($docs !== '') : ?><a href="<?php echo esc_url($docs); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html(sprintf(__('%s official API docs ↗', 'digiforge'), $providerLabel)); ?></a><?php endif; ?></div>
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
