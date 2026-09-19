<?php
declare(strict_types=1);
namespace DigiForge\Portal;

use DigiForge\Core\Config;
use DigiForge\Core\Settings;
use DigiForge\Operations\Readiness;
use DigiForge\Security\Logger;

final class FrontendControls
{
    private const ACTION = 'digiforge_frontend_update_control';
    private const ACTIVATE = 'digiforge_frontend_activate_production';

    public function register(): void
    {
        add_filter('do_shortcode_tag', [$this, 'replaceSystemControls'], 30, 4);
        add_action('admin_post_' . self::ACTION, [$this, 'update']);
        add_action('admin_post_' . self::ACTIVATE, [$this, 'activate']);
    }

    public function replaceSystemControls(string $output, string $tag, array $attr, array $match): string
    {
        if ($tag !== 'digiforge_admin_portal' || ! is_user_logged_in() || ! current_user_can('manage_digiforge_automation')) { return $output; }
        $view = isset($_GET['df_view']) ? sanitize_key(wp_unslash($_GET['df_view'])) : 'dashboard';
        if ($view !== 'system') { return $output; }
        $replaced = preg_replace('~<section class="df-panel"><div class="df-panel-head"><h2>Control state</h2></div>.*?</section>~s', $this->render(), $output, 1);
        return is_string($replaced) ? $replaced : $output;
    }

    public function activate(): void
    {
        $this->authorize(self::ACTIVATE);
        $report = (new Readiness())->report();
        if (($report['status'] ?? '') !== 'READY_LOCKED') {
            Logger::audit('production_activation_refused', ['status' => $report['status'] ?? 'unknown', 'evidence_hash' => $report['evidence_hash'] ?? ''], 'system', 'production_activation');
            $this->redirect('Production release refused: readiness certification is not READY_LOCKED.', true);
        }
        if (Settings::get('stop_all', true) !== true || Settings::get('activation_authorized', false) === true || Settings::get('automation_armed', false) === true) {
            $this->redirect('Production release refused: protected pre-release state changed. Re-certify first.', true);
        }
        if (! Settings::activateProduction()) {
            Logger::audit('production_activation_failed', ['evidence_hash' => $report['evidence_hash'] ?? ''], 'system', 'production_activation');
            $this->redirect('Production release failed atomically; STOP ALL remains protected.', true);
        }
        Logger::audit('production_activated', ['evidence_hash' => $report['evidence_hash'] ?? '', 'external_feature_switches_changed' => false], 'system', 'production_activation');
        $this->redirect('Production activated and STOP ALL released. Individual external feature switches remain unchanged.');
    }

    public function update(): void
    {
        $this->authorize(self::ACTION);
        $key = isset($_POST['control_key']) ? sanitize_key(wp_unslash($_POST['control_key'])) : '';
        $enabled = isset($_POST['enabled']) && wp_unslash($_POST['enabled']) === '1';
        if (! Config::allowed_switch($key)) { $this->redirect('Unknown DigiForge control.', true); }
        if ($key !== 'stop_all' && $enabled && Settings::get('activation_authorized', false) !== true) { $this->redirect('Activation is not authorized. The requested control remains OFF.', true); }
        if ($key !== 'stop_all' && $enabled && Settings::get('automation_armed', false) !== true) { $this->redirect('Automation is not armed. The requested control remains OFF.', true); }
        if ($key === 'stop_all' && ! $enabled) { $this->redirect('Use the protected Production Activation action to release STOP ALL.', true); }
        if (! Settings::set($key, $enabled, 'boolean')) { $this->redirect('Unable to save the DigiForge control.', true); }
        Logger::audit('frontend_control_updated', ['control' => $key, 'enabled' => $enabled], 'setting', $key);
        $this->redirect(sprintf('%s set to %s.', ucwords(str_replace('_', ' ', $key)), $enabled ? 'ON' : 'OFF'));
    }

    private function authorize(string $action): void
    {
        if (! is_user_logged_in() || ! current_user_can('manage_digiforge_automation')) { wp_die(esc_html__('You are not authorized to manage DigiForge automation.', 'digiforge')); }
        check_admin_referer($action);
    }

    private function render(): string
    {
        $locked = Settings::safety_locked();
        $activation = Settings::get('activation_authorized', false) === true;
        $armed = Settings::get('automation_armed', false) === true;
        $report = (new Readiness())->report();
        $certified = ($report['status'] ?? '') === 'READY_LOCKED';
        ob_start(); ?>
        <section class="df-panel df-frontend-controls">
            <div class="df-panel-head"><div><h2>System &amp; Automation Controls</h2><p>Routine DigiForge operations are controlled here. WordPress admin is not required.</p></div><span class="df-status"><?php echo esc_html($locked ? 'EXTERNALLY LOCKED' : 'ACTIVE'); ?></span></div>
            <div class="df-signal-grid">
                <div><span>Production certification</span><b><?php echo esc_html($certified ? 'READY_LOCKED' : 'REVIEW REQUIRED'); ?></b></div>
                <div><span>Production activation</span><b><?php echo $activation ? 'AUTHORIZED' : 'NOT AUTHORIZED'; ?></b></div>
                <div><span>Automation arming</span><b><?php echo $armed ? 'ARMED' : 'NOT ARMED'; ?></b></div>
                <div><span>External execution</span><b><?php echo $locked ? 'LOCKED' : 'AVAILABLE'; ?></b></div>
            </div>
            <?php if ($certified && $locked && ! $activation && ! $armed) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Activate DigiForge production and release STOP ALL? Individual external feature switches will NOT be enabled automatically.');">
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTIVATE); ?>"><?php wp_nonce_field(self::ACTIVATE); ?>
                    <button class="df-button df-button-danger" type="submit">Activate Production &amp; Release STOP ALL</button>
                </form>
            <?php else : ?><div class="df-notice">Final production release is unavailable until the system is certified READY_LOCKED in the protected pre-release state.</div><?php endif; ?>
            <div class="df-control-grid">
            <?php foreach (Config::SWITCHES as $key) : $enabled = Settings::get($key, $key === 'stop_all') === true; $effective = $key === 'stop_all' ? $enabled : Settings::is_enabled($key); ?>
                <article class="df-control-card"><div><strong><?php echo esc_html(ucwords(str_replace('_', ' ', $key))); ?></strong><div class="df-muted">Configured: <?php echo $enabled ? 'ON' : 'OFF'; ?><?php if ($key !== 'stop_all') : ?> · Effective: <?php echo $effective ? 'ON' : 'OFF'; ?><?php endif; ?></div></div>
                <?php if ($key === 'stop_all' && $enabled) : ?><span class="df-muted">Protected by production release gate</span><?php else : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>"><input type="hidden" name="control_key" value="<?php echo esc_attr($key); ?>"><input type="hidden" name="enabled" value="<?php echo $enabled ? '0' : '1'; ?>"><?php wp_nonce_field(self::ACTION); ?><button class="df-button <?php echo $enabled ? 'df-button-danger' : 'df-button-primary'; ?>" type="submit"><?php echo esc_html($enabled ? 'Turn OFF' : 'Turn ON'); ?></button></form>
                <?php endif; ?></article>
            <?php endforeach; ?>
            </div>
            <div class="df-notice">Production activation never enables Etsy, Printify, Gelato, order, GST, research, AI, or product-development switches. Those remain individually controlled and approval-gated.</div>
        </section><?php return (string) ob_get_clean();
    }

    private function redirect(string $message, bool $error = false): never
    {
        wp_safe_redirect(add_query_arg(['df_view' => 'system', 'df_message' => $message, 'df_error' => $error ? '1' : '0'], home_url('/'))); exit;
    }
}
