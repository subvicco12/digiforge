<?php
declare(strict_types=1);
namespace DigiForge\Launch;

use DigiForge\Core\Settings;

/** Read-only Stage 5 Etsy Draft preflight. Performs no network request or external action. */
final class EtsyDraftActivationPreflight
{
    /** @return array<string,mixed> */
    public function report(): array
    {
        return self::summarize([
            'stop_all'=>Settings::get('stop_all',true),
            'activation_authorized'=>Settings::get('activation_authorized',false),
            'automation_armed'=>Settings::get('automation_armed',false),
            'product_development_effective'=>Settings::is_enabled('product_development'),
            'etsy_draft_configured'=>Settings::get('etsy_draft',false),
            'etsy_draft_authorized'=>Settings::get('etsy_draft_activation_authorized',false),
            'etsy_draft_effective'=>Settings::is_enabled('etsy_draft'),
        ]);
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    public static function summarize(array $state): array
    {
        $checks=[
            'stop_all_released'=>($state['stop_all']??true)===false,
            'activation_authorized'=>($state['activation_authorized']??false)===true,
            'automation_armed'=>($state['automation_armed']??false)===true,
            'product_development_effective'=>($state['product_development_effective']??false)===true,
            'etsy_draft_configured'=>($state['etsy_draft_configured']??false)===true,
            'etsy_draft_not_authorized_yet'=>($state['etsy_draft_authorized']??true)===false,
            'etsy_draft_not_effective_yet'=>($state['etsy_draft_effective']??true)===false,
        ];
        $blockers=array_keys(array_filter($checks,static fn(bool $pass):bool=>!$pass));
        return ['status'=>$blockers===[]?'READY_FOR_CONTROLLED_ETSY_DRAFT_ACTIVATION':'BLOCKED','checks'=>$checks,'blockers'=>$blockers,'network_requests_performed'=>false,'external_actions_performed'=>false];
    }
}
