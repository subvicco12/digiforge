<?php
declare(strict_types=1);

namespace DigiForge\Portal;

use DigiForge\Core\Config;
use DigiForge\Core\Settings;
use DigiForge\Security\Logger;

/**
 * Front-end operational control surface.
 *
 * Keeps routine DigiForge control changes in the private front-end console.
 * Internal release gates remain visible but intentionally non-writable.
 */
final class FrontendControls
{
    private const ACTION = 'digiforge_frontend_update_control';

    public function register(): void
    {
        add_filter('do_shortcode_tag', [$this, 'replaceSystemControls'], 30, 4);
        add_action('admin_post_' . self::ACTION, [$this, 'update']);
    }

    /** @param array<string,mixed> $attr @param array<int,string> $match */
    public function replaceSystemControls(string $output, string $tag, array $attr, array $match): string
    {
        if ($tag !== 'digiforge_admin_portal' || ! is_user_logged_in() || ! current_user_can('manage_digiforge_automation')) {
            return $output;
        }

        $view = isset($_GET['df_view']) ? sanitize_key(wp_unslash($_GET['df_view'])) : 'dashboard';
        if ($view !== 'system') {
            return $output;
        }

        $panel = $this->render();
        $pattern = '~<section class="df-panel"><div class="df-panel-head"><h2>Control state</h2></div>.*?</section>~s';
        $replaced = preg_replace($pattern, $panel, $output, 1);

        return is_string($replaced) ? $replaced : $output;
    }

    public function update(): void
    {
        if (! is_user_logged_in() || ! current_user_can('manage_digiforge_automation')) {
            wp_die(esc_html__('You are not authorized to manage DigiForge automation.', 'digiforge'));
        }

        check_admin_referer(self::ACTION);

        $key = isset($_POST['control_key']) ? sanitize_key(wp_unslash($_POST['control_key'])) : '';
        $enabled = isset($_POST['enabled']) && wp_unslash($_POST['enabled']) === '1';

        if (! Config::allowed_switch($key)) {
            $this->redirect('Unknown DigiForge control.', true);
        }

        // Preserve the same fail-closed activation rules as the REST control API.
        if ($key !== 'stop_all' && $enabled && Settings::get('activation_authorized', false) !== true) {
            $this->redirect('Activation is not authorized. The requested control remains OFF.', true);
        }
        if ($key !== 'stop_all' && $enabled && Settings::get('automation_armed', false) !== true) {
            $this->redirect('Automation is not armed. The requested control remains OFF.', true);
        }
        if ($key === 'stop_all' && ! $enabled && Settings::get('activation_authorized', false) !== true) {
            $this->redirect('STOP ALL cannot be disabled until production activation is explicitly authorized.', true);
        }

        if (! Settings::set($key, $enabled, 'boolean')) {
            $this->redirect('Unable to save the DigiForge control.', true);
        }

        Logger::audit(
            'frontend_control_updated',
            ['control' => $key, 'enabled' => $enabled],
            'setting',
            $key
        );

        $this->redirect(sprintf('%s set to %s.', ucwords(str_replace('_', ' ', $key)), $enabled ? 'ON' : 'OFF'));
    }

    private function render(): string
    {
        $locked = Settings::safety_locked();
        $activation = Settings::get('activation_authorized', false) === true;
        $armed = Settings::get('automation_armed', false) === true;

        ob_start(); ?>
        <section class="df-panel df-frontend-controls">
            <div class="df-panel-head">
                <div>
                    <h2>System &amp; Automation Controls</h2>
                    <p>Routine DigiForge operations are controlled here. WordPress admin is not required.</p>
                </div>
                <span class="df-status"><?php echo esc_html($locked ? 'EXTERNALLY LOCKED' : 'ACTIVE'); ?></span>
            </div>

            <div class="df-signal-grid">
                <div><span>Production activation</span><b><?php echo $activation ? 'AUTHORIZED' : 'NOT AUTHORIZED'; ?></b></div>
                <div><span>Automation arming</span><b><?php echo $armed ? 'ARMED' : 'NOT ARMED'; ?></b></div>
                <div><span>External execution</span><b><?php echo $locked ? 'LOCKED' : 'AVAILABLE'; ?></b></div>
            </div>
            <p class="df-muted">Activation authorization and automation arming are protected release gates. They are displayed here but cannot be changed by ordinary runtime control requests.</p>

            <div class="df-control-grid">
                <?php foreach (Config::SWITCHES as $key) :
                    $enabled = Settings::get($key, $key === 'stop_all') === true;
                    $effective = $key === 'stop_all' ? $enabled : Settings::is_enabled($key);
                    ?>
                    <article class="df-control-card">
                        <div>
                            <strong><?php echo esc_html(ucwords(str_replace('_', ' ', $key))); ?></strong>
                            <div class="df-muted">
                                Configured: <?php echo $enabled ? 'ON' : 'OFF'; ?>
                                <?php if ($key !== 'stop_all') : ?> · Effective: <?php echo $effective ? 'ON' : 'OFF'; ?><?php endif; ?>
                            </div>
                        </div>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
                            <input type="hidden" name="control_key" value="<?php echo esc_attr($key); ?>">
                            <input type="hidden" name="enabled" value="<?php echo $enabled ? '0' : '1'; ?>">
                            <?php wp_nonce_field(self::ACTION); ?>
                            <button class="df-button <?php echo $enabled ? 'df-button-danger' : 'df-button-primary'; ?>" type="submit">
                                <?php echo esc_html($enabled ? 'Turn OFF' : 'Turn ON'); ?>
                            </button>
                        </form>
                    </article>
                <?php endforeach; ?>
            </div>

            <div class="df-notice">
                Safety interlocks remain authoritative: protected controls cannot be enabled while activation is unauthorized or automation is unarmed, and STOP ALL cannot be released prematurely.
            </div>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    private function redirect(string $message, bool $error = false): never
    {
        wp_safe_redirect(add_query_arg([
            'df_view' => 'system',
            'df_message' => $message,
            'df_error' => $error ? '1' : '0',
        ], home_url('/')));
        exit;
    }
}
