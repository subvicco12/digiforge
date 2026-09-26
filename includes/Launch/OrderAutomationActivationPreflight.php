<?php
declare(strict_types=1);
namespace DigiForge\Launch;
use DigiForge\Core\Settings;
final class OrderAutomationActivationPreflight {
 public function report(): array { return self::summarize([
  'stop_all'=>Settings::get('stop_all',true),'activation_authorized'=>Settings::get('activation_authorized',false),'automation_armed'=>Settings::get('automation_armed',false),
  'printify_effective'=>Settings::is_enabled('printify'),'configured'=>Settings::get('order_automation',false),'authorized'=>Settings::get('order_automation_activation_authorized',false),'effective'=>Settings::is_enabled('order_automation'),
 ]); }
 public static function summarize(array $s): array { $c=['stop_all_released'=>($s['stop_all']??true)===false,'activation_authorized'=>($s['activation_authorized']??false)===true,'automation_armed'=>($s['automation_armed']??false)===true,'printify_effective'=>($s['printify_effective']??false)===true,'configured'=>($s['configured']??false)===true,'not_authorized_yet'=>($s['authorized']??true)===false,'not_effective_yet'=>($s['effective']??true)===false]; $b=array_keys(array_filter($c,static fn(bool $p):bool=>!$p)); return ['status'=>$b===[]?'READY_FOR_CONTROLLED_ORDER_AUTOMATION_ACTIVATION':'BLOCKED','checks'=>$c,'blockers'=>$b,'network_requests_performed'=>false,'external_actions_performed'=>false]; }
}
