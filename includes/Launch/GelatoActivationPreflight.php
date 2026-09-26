<?php
declare(strict_types=1);
namespace DigiForge\Launch;
use DigiForge\Core\Settings;
final class GelatoActivationPreflight {
 public function report():array { return self::summarize([
 'stop_all'=>Settings::get('stop_all',true),'activation_authorized'=>Settings::get('activation_authorized',false),
 'automation_armed'=>Settings::get('automation_armed',false),'product_development_effective'=>Settings::is_enabled('product_development'),
 'gelato_configured'=>Settings::get('gelato',false),'gelato_authorized'=>Settings::get('gelato_activation_authorized',false),'gelato_effective'=>Settings::is_enabled('gelato')]);}
 public static function summarize(array $s):array {$c=['stop_all_released'=>($s['stop_all']??true)===false,'activation_authorized'=>($s['activation_authorized']??false)===true,'automation_armed'=>($s['automation_armed']??false)===true,'product_development_effective'=>($s['product_development_effective']??false)===true,'gelato_configured'=>($s['gelato_configured']??false)===true,'gelato_not_authorized_yet'=>($s['gelato_authorized']??true)===false,'gelato_not_effective_yet'=>($s['gelato_effective']??true)===false];$b=array_keys(array_filter($c,fn(bool $v):bool=>!$v));return ['status'=>$b===[]?'READY_FOR_CONTROLLED_GELATO_ACTIVATION':'BLOCKED','checks'=>$c,'blockers'=>$b,'network_requests_performed'=>false,'external_actions_performed'=>false];}
}
