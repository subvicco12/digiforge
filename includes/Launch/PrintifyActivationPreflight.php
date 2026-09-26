<?php
declare(strict_types=1);
namespace DigiForge\Launch;

use DigiForge\Core\Settings;

/** Read-only Stage 4 Printify preflight. Performs no credential retrieval, network request, or external action. */
final class PrintifyActivationPreflight
{
    /** @return array<string,mixed> */
    public function report(): array
    {
        return self::summarize([
            'stop_all'=>Settings::get('stop_all',true),
            'activation_authorized'=>Settings::get('activation_authorized',false),
            'automation_armed'=>Settings::get('automation_armed',false),
            'product_development_effective'=>Settings::is_enabled('product_development'),
            'printify_configured'=>Settings::get('printify',false),
            'printify_authorized'=>Settings::get('printify_activation_authorized',false),
            'printify_effective'=>Settings::is_enabled('printify'),
            'order_automation_effective'=>Settings::is_enabled('order_automation'),
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
            'printify_configured'=>($state['printify_configured']??false)===true,
            'printify_not_authorized_yet'=>($state['printify_authorized']??true)===false,
            'printify_not_effective_yet'=>($state['printify_effective']??true)===false,
            'order_automation_still_locked'=>($state['order_automation_effective']??true)===false,
        ];
        $blockers=array_keys(array_filter($checks,static fn(bool $pass):bool=>!$pass));
        return ['status'=>$blockers===[]?'READY_FOR_CONTROLLED_PRINTIFY_ACTIVATION':'BLOCKED','checks'=>$checks,'blockers'=>$blockers,'credential_retrieval_performed'=>false,'network_requests_performed'=>false,'external_actions_performed'=>false];
    }
}
