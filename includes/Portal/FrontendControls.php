<?php
declare(strict_types=1);
namespace DigiForge\Portal;

use DigiForge\Core\Config;
use DigiForge\Core\Settings;
use DigiForge\Operations\Readiness;
use DigiForge\Launch\ResearchActivationPreflight;
use DigiForge\Launch\AiActivationPreflight;
use DigiForge\Launch\OpenAIClient;
use DigiForge\Security\Logger;

final class FrontendControls
{
    private const ACTION = 'digiforge_frontend_update_control';
    private const ACTIVATE = 'digiforge_frontend_activate_production';
    private const PROTECT = 'digiforge_frontend_protect_production';
    private const ACTIVATE_AI = 'digiforge_frontend_activate_ai';
    private const CONTROLLED_AI_TEST = 'digiforge_frontend_controlled_ai_test';

    public function register(): void
    {
        add_filter('do_shortcode_tag', [$this, 'replaceSystemControls'], 30, 4);
        add_action('admin_post_' . self::ACTION, [$this, 'update']);
        add_action('admin_post_' . self::ACTIVATE, [$this, 'activate']);
        add_action('admin_post_' . self::PROTECT, [$this, 'protect']);
        add_action('admin_post_' . self::ACTIVATE_AI, [$this, 'activateAi']);
        add_action('admin_post_' . self::CONTROLLED_AI_TEST, [$this, 'controlledAiTest']);
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
        $researchPreflight = (new ResearchActivationPreflight())->report();
        $aiPreflight = (new AiActivationPreflight())->report();
        if (($researchPreflight['status'] ?? '') !== 'READY_FOR_CONTROLLED_RESEARCH_ACTIVATION') {
            Logger::audit('research_activation_refused', ['status' => $researchPreflight['status'] ?? 'BLOCKED', 'blockers' => $researchPreflight['blockers'] ?? []], 'system', 'research_activation');
            $this->redirect('Research activation refused: dedicated Research preflight is blocked.', true);
        }
        if (($report['status'] ?? '') !== 'READY_LOCKED') {
            Logger::audit('production_activation_refused', ['status' => $report['status'] ?? 'unknown', 'evidence_hash' => $report['evidence_hash'] ?? ''], 'system', 'production_activation');
            $this->redirect('Production release refused: readiness certification is not READY_LOCKED.', true);
        }
        if (Settings::get('stop_all', true) !== true || Settings::get('activation_authorized', false) === true || Settings::get('automation_armed', false) === true) {
            $this->redirect('Production release refused: protected pre-release state changed. Re-certify first.', true);
        }
        if (! Settings::activateResearch()) {
            Logger::audit('production_activation_failed', ['evidence_hash' => $report['evidence_hash'] ?? ''], 'system', 'production_activation');
            $this->redirect('Production release failed atomically; STOP ALL remains protected.', true);
        }
        Logger::audit('research_activation_authorized', ['capability' => 'research', 'evidence_hash' => $report['evidence_hash'] ?? '', 'external_feature_switches_changed' => false], 'system', 'research_activation');
        $this->redirect('Research-only activation authorized and STOP ALL released. AI, Product Development, and all other capabilities remain ineffective.');
    }

    public function activateAi(): void
    {
        $this->authorize(self::ACTIVATE_AI);
        $preflight = (new AiActivationPreflight())->report();
        if (($preflight['status'] ?? '') !== 'READY_FOR_CONTROLLED_AI_ACTIVATION') {
            Logger::audit('ai_activation_refused', ['status' => $preflight['status'] ?? 'BLOCKED', 'blockers' => $preflight['blockers'] ?? []], 'system', 'ai_activation');
            $this->redirect('AI activation refused: dedicated AI preflight is blocked.', true);
        }
        if (! Settings::activateAi()) {
            Logger::audit('ai_activation_failed', [], 'system', 'ai_activation');
            $this->redirect('AI activation failed atomically; AI remains ineffective.', true);
        }
        Logger::audit('ai_activation_authorized', ['capability' => 'ai', 'external_actions_performed' => false], 'system', 'ai_activation');
        $this->redirect('AI capability activation authorized. Product Development and all later capabilities remain ineffective. No provider request was performed by activation.');
    }

    public function controlledAiTest(): void
    {
        $this->authorize(self::CONTROLLED_AI_TEST);
        if (! Settings::is_enabled('research') || ! Settings::is_enabled('ai') || Settings::is_enabled('product_development')) {
            Logger::audit('controlled_ai_test_refused', ['reason' => 'capability_isolation'], 'system', 'controlled_ai_test');
            $this->redirect('Controlled AI test refused: Research and AI must be effective and Product Development must remain ineffective.', true);
        }
        $result = (new OpenAIClient())->controlledConnectivityTest();
        if (is_wp_error($result)) { $this->redirect($result->get_error_message(), true); }
        $this->redirect('Controlled AI provider test PASS. Exactly one provider request was performed; no downstream action or automatic retry occurred.');
    }

    public function protect(): void
    {
        $this->authorize(self::PROTECT);
        if (! Settings::protectProduction()) {
            Logger::audit('production_protection_failed', [], 'system', 'production_activation');
            $this->redirect('Unable to restore the protected pre-release posture.', true);
        }
        Logger::audit('production_protected', ['external_feature_switches_changed' => false], 'system', 'production_activation');
        $this->redirect('Protected pre-release posture restored. STOP ALL is ON; activation authorization and automation arming are OFF.');
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
        $researchPreflight = (new ResearchActivationPreflight())->report();
        $aiPreflight = (new AiActivationPreflight())->report();
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
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Authorize the Research capability and release STOP ALL? AI, Product Development, and every other capability will remain ineffective.');">
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTIVATE); ?>"><?php wp_nonce_field(self::ACTIVATE); ?>
                    <button class="df-button df-button-danger" type="submit">Authorize Research &amp; Release STOP ALL</button>
                </form>
            <?php elseif ($locked && ($activation || $armed)) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Restore the protected pre-release posture? Feature configuration will remain unchanged.');">
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::PROTECT); ?>"><?php wp_nonce_field(self::PROTECT); ?>
                    <button class="df-button df-button-primary" type="submit">Restore Protected Pre-Release State</button>
                </form>
            <?php else : ?><div class="df-notice">Final production release is unavailable until the system is certified READY_LOCKED in the protected pre-release state.</div><?php endif; ?>
            <?php if (($aiPreflight['status'] ?? '') === 'READY_FOR_CONTROLLED_AI_ACTIVATION') : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Authorize AI capability? This changes authorization only; it does not make a provider request. Product Development and later capabilities remain ineffective.');">
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTIVATE_AI); ?>"><?php wp_nonce_field(self::ACTIVATE_AI); ?>
                    <button class="df-button df-button-danger" type="submit">Authorize AI Capability</button>
                </form>
            <?php endif; ?>
            <?php if (Settings::is_enabled('research') && Settings::is_enabled('ai') && ! Settings::is_enabled('product_development')) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Run exactly ONE controlled AI provider request? No web search, Product Development, Etsy, POD, order, finance, GST action, or automatic retry will occur.');">
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::CONTROLLED_AI_TEST); ?>"><?php wp_nonce_field(self::CONTROLLED_AI_TEST); ?>
                    <button class="df-button df-button-primary" type="submit">Run Controlled AI Test</button>
                </form>
            <?php endif; ?>
            <div class="df-control-grid">
            <?php foreach (Config::SWITCHES as $key) : $enabled = Settings::get($key, $key === 'stop_all') === true; $effective = $key === 'stop_all' ? $enabled : Settings::is_enabled($key); ?>
                <article class="df-control-card"><div><strong><?php echo esc_html(ucwords(str_replace('_', ' ', $key))); ?></strong><div class="df-muted">Configured: <?php echo $enabled ? 'ON' : 'OFF'; ?><?php if ($key !== 'stop_all') : ?> · Effective: <?php echo $effective ? 'ON' : 'OFF'; ?><?php endif; ?></div></div>
                <?php if ($key === 'stop_all' && $enabled) : ?><span class="df-muted">Protected by production release gate</span><?php else : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>"><input type="hidden" name="control_key" value="<?php echo esc_attr($key); ?>"><input type="hidden" name="enabled" value="<?php echo $enabled ? '0' : '1'; ?>"><?php wp_nonce_field(self::ACTION); ?><button class="df-button <?php echo $enabled ? 'df-button-danger' : 'df-button-primary'; ?>" type="submit"><?php echo esc_html($enabled ? 'Turn OFF' : 'Turn ON'); ?></button></form>
                <?php endif; ?></article>
            <?php endforeach; ?>
            </div>
            <section class="df-panel df-research-preflight">
                <div class="df-panel-head"><div><h3>Research Activation Preflight</h3><p>Read-only production readiness check. No provider request, switch change, or external action is performed.</p></div><span class="df-status"><?php echo esc_html((string) ($researchPreflight['status'] ?? 'BLOCKED')); ?></span></div>
                <div class="df-signal-grid">
                    <?php foreach ((array) ($researchPreflight['checks'] ?? []) as $check => $passed) : ?>
                        <div><span><?php echo esc_html(ucwords(str_replace('_', ' ', (string) $check))); ?></span><b><?php echo $passed ? 'PASS' : 'BLOCKED'; ?></b></div>
                    <?php endforeach; ?>
                </div>
                <?php $blockers = (array) ($researchPreflight['blockers'] ?? []); ?>
                <?php if ($blockers === []) : ?>
                    <div class="df-notice df-notice-success">Research preflight passed. Next action: explicit controlled activation authorization. Live research still requires the separately governed AI activation stage.</div>
                <?php else : ?>
                    <div class="df-notice df-notice-error"><strong>Preflight blockers:</strong> <?php echo esc_html(implode(', ', array_map('strval', $blockers))); ?></div>
                <?php endif; ?>
                <div class="df-muted">Network requests performed: NO · External actions performed: NO</div>
            </section>
            <section class="df-panel df-ai-preflight">
                <div class="df-panel-head"><div><h3>AI Activation Preflight</h3><p>Read-only Stage 2 check. No provider request, switch change, or external action is performed.</p></div><span class="df-status"><?php echo esc_html((string) ($aiPreflight['status'] ?? 'BLOCKED')); ?></span></div>
                <div class="df-signal-grid">
                    <?php foreach ((array) ($aiPreflight['checks'] ?? []) as $check => $passed) : ?>
                        <div><span><?php echo esc_html(ucwords(str_replace('_', ' ', (string) $check))); ?></span><b><?php echo $passed ? 'PASS' : 'BLOCKED'; ?></b></div>
                    <?php endforeach; ?>
                </div>
                <?php $aiBlockers = (array) ($aiPreflight['blockers'] ?? []); ?>
                <?php if ($aiBlockers === []) : ?>
                    <div class="df-notice df-notice-success">AI preflight passed. Explicit AI activation authorization is required. Activation itself performs no provider request.</div>
                <?php else : ?>
                    <div class="df-notice df-notice-error"><strong>AI preflight blockers:</strong> <?php echo esc_html(implode(', ', array_map('strval', $aiBlockers))); ?></div>
                <?php endif; ?>
                <div class="df-muted">Network requests performed: NO · External actions performed: NO</div>
            </section>
            <div class="df-notice">Capability activation is scoped. Research and AI authorization cannot activate Product Development, Etsy, Printify, Gelato, orders, or GST; later stages require separate authorization.</div>
        </section><?php return (string) ob_get_clean();
    }

    private function redirect(string $message, bool $error = false): never
    {
        wp_safe_redirect(add_query_arg(['df_view' => 'system', 'df_message' => $message, 'df_error' => $error ? '1' : '0'], home_url('/'))); exit;
    }
}
