<?php
declare(strict_types=1);
namespace DigiForge\REST;
use DigiForge\Listings\EtsyWebhookIntake;
use DigiForge\Listings\EtsyWebhookDeduplicator;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/** Public Etsy webhook ingress. Authenticity is established from raw bytes before processing. */
final class EtsyWebhookController {
 public function register():void { add_action('rest_api_init',function():void{
  register_rest_route('digiforge/v1','/etsy/webhook',['methods'=>'POST','callback'=>[$this,'receive'],'permission_callback'=>'__return_true']);
 });}
 public function receive(WP_REST_Request $request):WP_REST_Response|WP_Error {
  if(!defined('DIGIFORGE_ETSY_WEBHOOK_SECRET')||!is_string(DIGIFORGE_ETSY_WEBHOOK_SECRET)||DIGIFORGE_ETSY_WEBHOOK_SECRET==='') return new WP_Error('digiforge_etsy_webhook_unconfigured','Etsy webhook signing secret is unavailable.',['status'=>503]);
  $headers=[]; foreach($request->get_headers() as $key=>$value)$headers[(string)$key]=$value;
  $result=(new EtsyWebhookIntake(new EtsyWebhookDeduplicator()))->accept($request->get_body(),$headers,DIGIFORGE_ETSY_WEBHOOK_SECRET,time());
  if($result instanceof WP_Error)return $result;
  return new WP_REST_Response(['state'=>(string)($result['state']??'ETSY_WEBHOOK_ACCEPTED'),'webhook_verified'=>(bool)($result['webhook_verified']??false),'external_execution_performed'=>false],200);
 }
}
