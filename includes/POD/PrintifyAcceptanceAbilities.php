<?php
declare(strict_types=1);
namespace DigiForge\POD;
/** Optional core Abilities API surface. Does not register a public proxy or create authority. */
final class PrintifyAcceptanceAbilities {
 public function register():void {
  if(defined('DIGIFORGE_PRINTIFY_ACCEPTANCE_EMERGENCY_EVENTS')&&DIGIFORGE_PRINTIFY_ACCEPTANCE_EMERGENCY_EVENTS===true)(new PrintifyAcceptanceEmergencyEvents())->register();
  add_action('wp_abilities_api_categories_init',static function(){if(function_exists('wp_register_ability_category'))wp_register_ability_category('digiforge-acceptance',['label'=>'DigiForge acceptance','description'=>'Governed acceptance evidence, not provider activation.']);});
  add_action('wp_abilities_api_init',[$this,'abilities']);
 }
 public function abilities():void {
  if(!function_exists('wp_register_ability'))return;
  $hash=['type'=>'string','pattern'=>'^[a-f0-9]{64}$'];
  foreach(['execute','receipt','revoke','readiness'] as $op){$props=$op==='execute'?['session_hash'=>$hash,'slot'=>['type'=>'integer','minimum'=>0,'maximum'=>9],'authorization_hash'=>$hash]:($op==='readiness'?[]:[(in_array($op,['receipt','readiness'],true)?'receipt_hash':'session_hash')=>$hash]);
   wp_register_ability('digiforge/printify-acceptance-'.$op,['label'=>'Printify acceptance '.$op,'description'=>'Owner/session-bound, independently reviewed GET-only acceptance. Existing locks remain authoritative. Local audit writes occur during execution.','category'=>'digiforge-acceptance','input_schema'=>['type'=>'object','properties'=>$props,'required'=>array_keys($props),'additionalProperties'=>false],'permission_callback'=>static fn()=>self::permission()===true,'execute_callback'=>fn($input)=>$this->invoke($op,$input),'meta'=>['show_in_rest'=>true,'annotations'=>['readonly'=>in_array($op,['receipt','readiness'],true),'destructive'=>false,'idempotent'=>in_array($op,['receipt','readiness'],true)]]]);
  }
 }
 public static function permission():true|\WP_Error {
  if(get_current_user_id()<1)return new \WP_Error('printify_acceptance_authentication_required','WordPress authentication required.',['status'=>401,'stage'=>'AUTHENTICATION']);
  $owner=\DigiForge\PortalAccess\Owner::require('personalized_pod');if(is_wp_error($owner)){$fresh=str_ends_with($owner->get_error_code(),'fresh_email_required');return new \WP_Error($fresh?'printify_acceptance_fresh_email_required':'printify_acceptance_owner_required',$fresh?'Fresh owner email verification in this WordPress session is required.':'Verified DigiForge owner authority required.',['status'=>403,'stage'=>$fresh?'FRESH_EMAIL':'OWNER_AUTHORITY']);}
  return PrintifyAcceptanceBridge::permission()?true:new \WP_Error('printify_acceptance_scope_required','Native shop/module authority required.',['status'=>403,'stage'=>'SHOP_MODULE']);
 }
 public function invoke(string $op,mixed $input):array|bool|\WP_Error {
  $permission=self::permission();if(is_wp_error($permission))return $permission;if(!is_array($input))return new \WP_Error('printify_acceptance_ability_input','Object input required.',['status'=>400,'stage'=>'INPUT']);
  if($op==='readiness')return $input===[]?PrintifyAcceptanceReadiness::snapshot():new \WP_Error('printify_acceptance_ability_input','Readiness accepts no parameters.',['status'=>400,'stage'=>'INPUT']);
  $keys=match($op){'execute'=>['session_hash','slot','authorization_hash'],'receipt'=>['receipt_hash'],'revoke'=>['session_hash'],default=>[]};if(!$keys||count($input)!==count($keys)||array_diff(array_keys($input),$keys))return new \WP_Error('printify_acceptance_ability_input','Exact input required.',['status'=>400]);
  foreach($input as $k=>$v)if($k==='slot'?(!is_int($v)||$v<0||$v>9):(!is_string($v)||!preg_match('/^[a-f0-9]{64}$/D',$v)))return new \WP_Error('printify_acceptance_ability_input','Bounded input required.',['status'=>400]);
  $b=new PrintifyAcceptanceBridge();return match($op){'execute'=>(new PrintifyControlledTransport())->executeAcceptance($input['session_hash'],$input['slot'],$input['authorization_hash']),'receipt'=>$b->receipt($input['receipt_hash']),'revoke'=>$b->revoke($input['session_hash'])};
 }
}
