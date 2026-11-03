<?php
declare(strict_types=1);
namespace DigiForge\Research;
use DigiForge\Core\Settings;
use DigiForge\Database\Tables;
use DigiForge\AI\GovernedGeneration;
use WP_Error;

/** Reuses the installed governed integration; never activates it or exposes credentials. */
final class GovernedResearchProvider implements ResearchProvider
{
    public function status(): array
    {
        if (!self::executionAllowed()) return ['available'=>false,'reason'=>'STOP_ALL_OR_UNAUTHORIZED','manual_import_available'=>true];
        global $wpdb;$wpdb->last_error='';
        $count=$wpdb->get_var("SELECT COUNT(*) FROM ".Tables::integrations()." i INNER JOIN ".Tables::integration_secrets()." s ON s.integration_id=i.id AND s.secret_name='api_key' WHERE i.provider='ai' AND i.environment='production' AND i.status='CONFIGURED' AND i.enabled=1");
        return ['available'=>$wpdb->last_error===''&&(int)$count>0,'reason'=>$wpdb->last_error!==''?'CREDENTIAL_READINESS_UNAVAILABLE':((int)$count>0?'GOVERNED_PREFLIGHT_REQUIRED':'CREDENTIALS_UNAVAILABLE'),'manual_import_available'=>true];
    }
    public static function executionAllowed(): bool
    {
        return \DigiForge\Security\PersonalizedPodAccess::allows(true) && get_current_user_id()>0 && current_user_can('manage_digiforge_pod') && current_user_can('manage_digiforge_research') && current_user_can('manage_digiforge_ai') && !Settings::safety_locked() && Settings::is_enabled('research') && Settings::is_enabled('ai');
    }
    public function generate(array $research,string $attemptKey): array|WP_Error
    {
        if (($research['shop_key']??null)!=='personalized_pod' || !preg_match('/^[a-f0-9]{64}$/D',$attemptKey) || !($this->status()['available']??false)) return new WP_Error('commercial_provider_unavailable','Live research is locked or credentials are unavailable. Use source/response import; no call or retry was attempted.');
        $result=(new GovernedGeneration())->research('personalized_pod',CommercialResearchContract::researchPrompt($research),'commercial-'.$attemptKey);
        if ($result instanceof WP_Error) return new WP_Error('commercial_provider_failed','Governed research failed or was denied. No automatic retry; inspect existing attempt evidence before another request.');
        if (!is_array($result['payload']??null) || !is_string($result['model']??null) || !is_string($result['response_id']??null)) return new WP_Error('commercial_provider_invalid','Provider metadata or structured response unavailable.');
        return ['raw'=>CommercialResearchContract::json($result['payload']),'provenance'=>['method'=>'governed_ai','provider'=>'existing_governed_ai','model'=>$result['model'],'response_id'=>$result['response_id'],'generated_at'=>gmdate('Y-m-d\TH:i:s\Z')]];
    }
}
