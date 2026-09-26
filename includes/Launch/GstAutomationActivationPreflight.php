<?php
declare(strict_types=1);
namespace DigiForge\Launch;
use DigiForge\Core\Settings;
final class GstAutomationActivationPreflight {
 public function report():array {return self::summarize(['order_automation_effective'=>Settings::is_enabled('order_automation'),'gst_automation_configured'=>Settings::get('gst_automation',false),'gst_automation_authorized'=>Settings::get('gst_automation_activation_authorized',false),'gst_automation_effective'=>Settings::is_enabled('gst_automation')]);}
 public static function summarize(array $s):array {$c=['order_automation_effective'=>($s['order_automation_effective']??false)===true,'gst_automation_configured'=>($s['gst_automation_configured']??false)===true,'gst_automation_not_authorized_yet'=>($s['gst_automation_authorized']??true)===false,'gst_automation_not_effective_yet'=>($s['gst_automation_effective']??true)===false];$b=array_keys(array_filter($c,fn(bool $v):bool=>!$v));return ['status'=>$b===[]?'READY_FOR_CONTROLLED_GST_AUTOMATION_ACTIVATION':'BLOCKED','checks'=>$c,'blockers'=>$b,'network_requests_performed'=>false,'external_actions_performed'=>false];}
}
