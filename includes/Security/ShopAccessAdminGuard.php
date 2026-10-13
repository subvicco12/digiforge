<?php
declare(strict_types=1);
namespace DigiForge\Security;

/** Legacy global admin surfaces require explicit grants; ordinary WP guards still run. */
final class ShopAccessAdminGuard
{
    public function register(): void
    {
        add_action('admin_init', [$this,'page'], 0);
        add_action('all', [$this,'action'], 0);
    }
    public function page(): void
    {
        $page=$_REQUEST['page']??null;
        if (!is_string($page)||($page!=='digiforge'&&!str_starts_with($page,'digiforge-'))) return;
        if (str_starts_with($page,'digiforge-finance')) return;
        $cap=match (true) {
            str_contains($page,'research')=>'manage_digiforge_research',
            str_contains($page,'ai-governance')=>'manage_digiforge_ai',
            str_contains($page,'connection')=>'manage_digiforge_connections',
            str_contains($page,'production')=>'manage_digiforge_production',
            str_contains($page,'listing')=>'manage_digiforge_listings',
            str_contains($page,'orders')=>'manage_digiforge_orders',
            str_contains($page,'pod')=>'manage_digiforge_pod',
            str_contains($page,'digital')=>'manage_digiforge_digital',
            str_contains($page,'product'),str_contains($page,'opportunit')=>'manage_digiforge_products',
            default=>'manage_digiforge',
        };
        $this->globalAccess($cap);
    }
    public function action(string $hook): void
    {
        if (!str_starts_with($hook,'admin_post_digiforge_')&&!str_starts_with($hook,'admin_post_nopriv_digiforge_')) return;
        $action=substr($hook,str_starts_with($hook,'admin_post_nopriv_')?18:11);
        // These reviewed entry points independently resolve stored scopes before private
        // reads/writes. Unknown future actions receive no automatic exemption.
        if (\DigiForge\Portal\ReviewConfirmation::definition($action)!==null || in_array($action,[
            'digiforge_portal_save_ai_secret','digiforge_portal_save_ai_policy',
            'digiforge_portal_prepare_research_run','digiforge_portal_execute_research_run',
            'digiforge_portal_recovery_plan','digiforge_portal_recovery_accept',
            'digiforge_portal_notification_preferences','digiforge_portal_draft_qa_preview',
            'digiforge_ai_evidence_review','digiforge_local_render_prepare',
            'digiforge_u3_asset_review','digiforge_u3_product_review',
            // Applying stronger protection reveals no shop data and grants no access.
            'digiforge_frontend_protect_production',
        ],true)) return;
        if (str_starts_with($action,'digiforge_finance_')) return;
        $cap=match (true) {
            in_array($action,['digiforge_etsy_oauth_start','digiforge_etsy_oauth_callback','digiforge_gelato_normalize_key','digiforge_integration_create','digiforge_integration_secret','digiforge_integration_secret_delete','digiforge_integration_update','digiforge_integration_test','digiforge_integration_toggle','digiforge_integration_delete'],true)=>'manage_digiforge_connections',
            in_array($action,['digiforge_frontend_update_control','digiforge_frontend_activate_production','digiforge_frontend_activate_ai','digiforge_frontend_controlled_ai_test','digiforge_frontend_activate_product_development'],true)=>'manage_digiforge_automation',
            default=>null,
        };
        if ($cap===null) $this->deny('unmapped_action');
        $this->globalAccess($cap);
    }
    private function globalAccess(string $cap): void
    {
        $shops=ShopAccess::allowedShops($cap);
        if ($shops instanceof \WP_Error || count($shops)!==count(ShopAccessPolicy::canonicalShops())) $this->deny('explicit_global_assignments_required');
    }
    private function deny(string $reason): never
    {
        $ok=Logger::write('shop_access_admin_denied',['actor_id'=>get_current_user_id(),'reason'=>$reason],'shop_access','admin');
        wp_die($ok?'Explicit current shop assignments are required.':'Access denial audit unavailable.','',['response'=>$ok?403:503]);
    }
}
