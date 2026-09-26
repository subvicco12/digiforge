<?php
declare(strict_types=1);
namespace DigiForge\Launch;
use DigiForge\Core\Settings;
final class EtsyPublishActivationPreflight {
 public function report():array {return self::summarize(['etsy_draft_effective'=>Settings::is_enabled('etsy_draft'),'etsy_publish_configured'=>Settings::get('etsy_publish',false),'etsy_publish_authorized'=>Settings::get('etsy_publish_activation_authorized',false),'etsy_publish_effective'=>Settings::is_enabled('etsy_publish')]);}
 public static function summarize(array $s):array {$c=['etsy_draft_effective'=>($s['etsy_draft_effective']??false)===true,'etsy_publish_configured'=>($s['etsy_publish_configured']??false)===true,'etsy_publish_not_authorized_yet'=>($s['etsy_publish_authorized']??true)===false,'etsy_publish_not_effective_yet'=>($s['etsy_publish_effective']??true)===false];$b=array_keys(array_filter($c,fn(bool $v):bool=>!$v));return ['status'=>$b===[]?'READY_FOR_CONTROLLED_ETSY_PUBLISH_ACTIVATION':'BLOCKED','checks'=>$c,'blockers'=>$b,'network_requests_performed'=>false,'external_actions_performed'=>false];}
}
