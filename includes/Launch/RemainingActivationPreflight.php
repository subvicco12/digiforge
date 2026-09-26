<?php
declare(strict_types=1);
namespace DigiForge\Launch;

use DigiForge\Core\Settings;

/** Read-only preflight for scoped late-stage capability activation. Performs no HTTP or external action. */
final class RemainingActivationPreflight
{
    /** @return array<string,mixed> */
    public function report(string $capability): array
    {
        $map = [
            'gelato' => ['requires' => 'product_development', 'auth' => 'gelato_activation_authorized'],
            'etsy_publish' => ['requires' => 'etsy_draft', 'auth' => 'etsy_publish_activation_authorized'],
            'order_automation' => ['requires' => 'etsy_publish', 'auth' => 'order_automation_activation_authorized'],
            'gst_automation' => ['requires' => 'order_automation', 'auth' => 'gst_automation_activation_authorized'],
        ];
        if (!isset($map[$capability])) {
            return ['status'=>'BLOCKED','checks'=>[],'blockers'=>['unsupported_capability'],'network_requests_performed'=>false,'external_actions_performed'=>false];
        }
        $required=$map[$capability]['requires'];
        $authorizationKey=$map[$capability]['auth'];
        $checks=[
            'stop_all_released'=>Settings::get('stop_all',true)===false,
            'activation_authorized'=>Settings::get('activation_authorized',false)===true,
            'automation_armed'=>Settings::get('automation_armed',false)===true,
            'required_capability_effective'=>Settings::is_enabled($required)===true,
            'supported_pod_provider_effective'=>$capability!=='order_automation' || Settings::is_enabled('printify')===true || Settings::is_enabled('gelato')===true,
            'capability_configured'=>Settings::get($capability,false)===true,
            'capability_not_authorized_yet'=>Settings::get($authorizationKey,false)===false,
            'capability_not_effective_yet'=>Settings::is_enabled($capability)===false,
        ];
        $blockers=array_keys(array_filter($checks,static fn(bool $pass):bool=>!$pass));
        return [
            'status'=>$blockers===[]?'READY_FOR_CONTROLLED_'.strtoupper($capability).'_ACTIVATION':'BLOCKED',
            'capability'=>$capability,
            'requires'=>$required,
            'checks'=>$checks,
            'blockers'=>$blockers,
            'network_requests_performed'=>false,
            'external_actions_performed'=>false,
        ];
    }
}
