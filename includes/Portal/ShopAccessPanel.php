<?php

declare(strict_types=1);

namespace DigiForge\Portal;

use DigiForge\Core\Capabilities;
use DigiForge\Security\ShopAccess;
use DigiForge\Security\ShopAccessAuthority;
use DigiForge\Security\ShopAccessPolicy;
use DigiForge\Security\ShopAccessRepository;

/** Website-native explicit local assignments; never creates the independent trust root. */
final class ShopAccessPanel
{
    public const ACTION = 'digiforge_portal_access';
    public function register(): void
    {
        add_action('admin_post_' . self::ACTION, [$this,'submit']);
    }
    public function submit(): never
    {
        $r = $this->processBrowser(wp_unslash($_POST), $_SERVER['REQUEST_METHOD'] ?? '');
        wp_safe_redirect(home_url('/?df_view=access&df_access_result=' . ($r instanceof \WP_Error ? 'blocked' : 'recorded')));
        exit;
    }
    public function processBrowser(array $p, string $method = 'POST'): array|\WP_Error
    {
        if (
            $method !== 'POST' || !is_user_logged_in() || !current_user_can('manage_digiforge_settings')
            || !is_string($p['_wpnonce'] ?? null) || !wp_verify_nonce($p['_wpnonce'], self::ACTION) || ($p['confirm'] ?? null) !== 'CONFIRM_EXPLICIT_SHOP_ACCESS'
        ) {
            return self::error();
        }
        if (strlen((string)wp_json_encode($p)) > 16384) {
            return self::error();
        }
        $allowed = ['action','_wpnonce','_wp_http_referer','confirm','operation','shop','role','caps','expires_at','approval','request_id','expected_revision','subject_id','grant_id'];
        if (
            array_diff(array_keys($p), $allowed) !== [] || !is_string($p['shop'] ?? null) || !ShopAccessPolicy::validShop($p['shop'])
            || !is_string($p['request_id'] ?? null) || !is_string($p['expected_revision'] ?? null) || preg_match('/^[0-9]{1,9}$/D', $p['expected_revision']) !== 1
        ) {
            return self::error();
        }
        $repo = new ShopAccessRepository();
        $revision = (int)$p['expected_revision'];
        if (($p['operation'] ?? null) === 'REVOKE') {
            if (!is_string($p['grant_id'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $p['grant_id']) !== 1) {
                return self::error();
            }
            return $repo->revoke($p['shop'], $p['grant_id'], $p['request_id'], $revision);
        }
        if (
            !is_string($p['role'] ?? null) || !is_array($p['caps'] ?? null) || !array_is_list($p['caps']) || !is_string($p['expires_at'] ?? null)
            || preg_match('/^[0-9]{1,10}$/D', $p['expires_at']) !== 1
        ) {
            return self::error();
        }
        foreach ($p['caps'] as $cap) {
            if (!is_string($cap) || !in_array($cap, Capabilities::ALL, true) || str_contains($cap, 'finance')) {
                return self::error();
            }
        }
        if (($p['operation'] ?? null) === 'ENROLL') {
            if (isset($p['subject_id']) || !is_string($p['approval'] ?? null)) {
                return self::error();
            }
            return $repo->enrollOwner($p['shop'], $p['role'], $p['caps'], (int)$p['expires_at'], $p['approval'], $p['request_id'], $revision);
        }
        if (($p['operation'] ?? null) !== 'GRANT' || !is_string($p['subject_id'] ?? null) || preg_match('/^[1-9][0-9]{0,9}$/D', $p['subject_id']) !== 1) {
            return self::error();
        }
        return $repo->grant((int)$p['subject_id'], $p['shop'], $p['role'], $p['caps'], (int)$p['expires_at'], $p['request_id'], $revision);
    }
    public function render(): string
    {
        if (!is_user_logged_in() || !current_user_can('manage_digiforge_settings')) {
            return '<p>Explicit access-management permission required.</p>';
        }
        $html = '<section class="df-panel"><a href="' . esc_url(home_url('/')) . '">Return to Operations Console</a><h1>Shop access and operator assignments</h1><p>WordPress administration does not grant shop access. Select exact capabilities and confirm each local assignment. No provider or production authority is granted.</p>';
        if (isset($_GET['df_access_result']) && in_array($_GET['df_access_result'], ['recorded','blocked'], true)) {
            $html .= '<p role="status">' . ($_GET['df_access_result'] === 'recorded' ? 'The explicit assignment change was recorded.' : 'Assignment blocked. Refresh signed scope and revision before trying again.') . '</p>';
        }
        $records = ShopAccessAuthority::records();
        if ($records instanceof \WP_Error) {
            return $html . '<p>Independent signed owner/delegate authority is missing or invalid. A trusted host operator must provision the reviewed authority envelope and public trust key. This website cannot create that authority or bypass it.</p></section>';
        }
        $repo = new ShopAccessRepository();
        $count = 0;
        foreach ($records as $record) {
            if ($record['actor_id'] !== get_current_user_id() || !ShopAccessPolicy::validShop($record['shop'])) {
                continue;
            }
            $state = $repo->read($record['shop']);
            if ($state instanceof \WP_Error) {
                $html .= '<p>Assignment evidence unavailable; changes blocked.</p>';
                continue;
            }
            $count++;
            $html .= '<h2>' . esc_html(match ($record['shop']) { 'digital' => 'KINETIQ MATRIX DESIGNS — Digital', 'personalized_pod' => 'DigiCraftifyGoods — Personalized POD', default => $record['shop'] . ' — future shop; assignment does not activate it' }) . '</h2>';
            $html .= '<p>Signed scope expires ' . esc_html(gmdate('Y-m-d H:i:s', $record['expires_at'])) . ' UTC. Current revision ' . (int)$state['revision'] . '.</p>';
            foreach (['ENROLL','GRANT'] as $operation) {
                if ($operation === 'ENROLL' && $record['authority'] !== 'OWNER') {
                    continue;
                }
                $html .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">' . $this->fields($record['shop'], $state['revision'], $operation);
                $html .= '<h3>' . ($operation === 'ENROLL' ? 'Enroll my signed owner scope' : 'Assign an existing operator') . '</h3>';
                if ($operation === 'GRANT') {
                    $html .= '<label>Existing operator user ID <input name="subject_id" type="number" min="1" required></label>';
                }
                $html .= '<label>Existing WordPress role <input name="role" value="" required></label><fieldset><legend>Explicit capabilities (none selected automatically)</legend>';
                foreach ($record['caps'] as $cap) {
                    if (str_contains($cap, 'finance') || !current_user_can($cap)) {
                        continue;
                    }$html .= '<label><input type="checkbox" name="caps[]" value="' . esc_attr($cap) . '"> ' . esc_html($cap) . '</label>';
                }
                $expiry = min(time() + 3600, $record['expires_at']);
                $html .= '</fieldset><input type="hidden" name="expires_at" value="' . (int)$expiry . '"><input type="hidden" name="approval" value="' . esc_attr($record['source_approval_hash']) . '"><p>This assignment expires ' . esc_html(gmdate('Y-m-d H:i:s', $expiry)) . ' UTC; role and capabilities must already exist on the selected user.</p>' . $this->confirmation() . '<button type="submit">Confirm local assignment</button></form>';
            }
            $html .= '<h3>Current assignment records</h3>';
            foreach ($state['grants'] as $id => $grant) {
                if (array_diff($grant['caps'], $record['caps']) !== []) {
                    continue;
                }
                $html .= '<p>User #' . (int)$grant['actor_id'] . ' — ' . esc_html($grant['role']) . ' — ' . esc_html(implode(', ', $grant['caps'])) . ' — ' . ($grant['revoked'] ? 'revoked' : ($grant['expires_at'] <= time() ? 'expired' : 'recorded')) . '</p>';
                if ($grant['revoked']) {
                    continue;
                }
                $html .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">' . $this->fields($record['shop'], $state['revision'], 'REVOKE') . '<input type="hidden" name="grant_id" value="' . esc_attr($id) . '">' . $this->confirmation() . '<button type="submit">Revoke exact assignment</button></form>';
            }
        }
        return $html . ($count === 0 ? '<p>No current signed authority matches your account and active shop scope.</p>' : '') . '</section>';
    }
    private function fields(string $shop, int $revision, string $operation): string
    {
        return wp_nonce_field(self::ACTION, '_wpnonce', true, false) . '<input type="hidden" name="action" value="' . self::ACTION . '"><input type="hidden" name="shop" value="' . esc_attr($shop) . '"><input type="hidden" name="operation" value="' . esc_attr($operation) . '"><input type="hidden" name="expected_revision" value="' . $revision . '"><input type="hidden" name="request_id" value="' . esc_attr(wp_generate_uuid4()) . '">';
    }
    private function confirmation(): string
    {
        return '<label><input type="checkbox" name="confirm" value="CONFIRM_EXPLICIT_SHOP_ACCESS" required> I confirm this exact local shop assignment or revocation.</label>';
    }
    private static function error(): \WP_Error
    {
        if (!\DigiForge\Security\Logger::write('portal_access_denied', ['actor_id' => get_current_user_id()], 'shop_access', 'portal')) {
            return new \WP_Error('digiforge_portal_access_audit', 'Access audit unavailable.', ['status' => 503]);
        }
        return new \WP_Error('digiforge_portal_access_denied', 'Exact signed scope, authenticated confirmation and current role are required.', ['status' => 403]);
    }
    public function landing(array $shops): string
    {
        $html = '<section class="df-panel"><h1>Operations Console</h1><p>Only explicitly assigned shop workflows are available. Global evidence remains restricted.</p><a href="' . esc_url(home_url('/?df_view=access')) . '">Shop access and operator assignments</a>';
        foreach ($shops as $shop) {
            $html .= '<h2>' . esc_html($shop) . '</h2>';foreach (['businesses' => 'manage_digiforge','products' => 'manage_digiforge_products','digital' => 'manage_digiforge_digital','pod_personalized' => 'manage_digiforge_pod','approvals' => 'manage_digiforge_research','listings' => 'manage_digiforge_listings','production' => 'manage_digiforge_production','automation' => 'manage_digiforge_automation','ai_budget' => 'manage_digiforge_ai','audit' => 'manage_digiforge'] as $view => $cap) {
                if (($view === 'digital' && $shop !== 'digital') || ($view === 'pod_personalized' && $shop !== 'personalized_pod') || ShopAccess::authorize($shop, $cap) !== true) {
                    continue;
                }
                $html .= '<p><a href="' . esc_url(add_query_arg(['df_view' => $view,'df_shop' => $shop], home_url('/'))) . '">' . esc_html($view) . '</a></p>';
            }
        }return $html . '</section>';
    }
}
