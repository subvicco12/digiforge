<?php
declare(strict_types=1);
namespace DigiForge\Launch;
use DigiForge\Core\Settings;
final class OrderAutomationActivationPreflight {
 public function report():array {return self::summarize(['provider_effective'=>Settings::is_enabled('printify')||Settings::is_enabled('gelato'),'order_automation_configured'=>Settings::get('order_automation',false),'order_automation_authorized'=>Settings::get('order_automation_activation_authorized',false),'order_automation_effective'=>Settings::is_enabled('order_automation')]);}
 public static function summarize(array $s):array {$c=['provider_effective'=>($s['provider_effective']??false)===true,'order_automation_configured'=>($s['order_automation_configured']??false)===true,'order_automation_not_authorized_yet'=>($s['order_automation_authorized']??true)===false,'order_automation_not_effective_yet'=>($s['order_automation_effective']??true)===false];$b=array_keys(array_filter($c,fn(bool $v):bool=>!$v));return ['status'=>$b===[]?'READY_FOR_CONTROLLED_ORDER_AUTOMATION_ACTIVATION':'BLOCKED','checks'=>$c,'blockers'=>$b,'network_requests_performed'=>false,'external_actions_performed'=>false];}
}
