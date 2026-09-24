<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Core\Settings;
use WP_Error;
/** Final runtime interlock for Printify mutations. Performs no HTTP. */
final class PrintifyLiveTransportInterlock{
 public static function authorize(array $permit,array $request):array|WP_Error{
  if(($permit['state']??'')!=='ADAPTER_CALL_PERMITTED'||($permit['nonce_consumed']??null)!==true||($permit['external_execution_performed']??null)!==false)return self::e('permit','Consumed controlled execution permit required.');
  $action=(string)($permit['action']??'');if(!in_array($action,['PROVIDER_ORDER_SUBMIT','PROVIDER_PRODUCTION_AUTHORIZE'],true)||($request['action']??'')!==$action)return self::e('scope','Printify request action must match the permit.');
  if(Settings::safety_locked()||Settings::get('activation_authorized',false)!==true||Settings::get('automation_armed',false)!==true||Settings::get('stop_all',true)!==false||Settings::is_enabled('printify')!==true||Settings::is_enabled('order_automation')!==true)return self::e('locked','Printify mutation execution remains locked by production safety controls.');
  return ['state'=>'PRINTIFY_LIVE_TRANSPORT_AUTHORIZED','action'=>$action,'integration_id'=>(int)$request['integration_id'],'request_fingerprint'=>(string)$request['request_fingerprint'],'network_request_permitted'=>true,'network_request_attempted'=>false,'external_execution_performed'=>false];
 }
 private static function e(string $c,string $m):WP_Error{return new WP_Error('digiforge_printify_interlock_'.$c,$m,['status'=>409]);}
}
