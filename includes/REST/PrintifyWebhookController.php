<?php
declare(strict_types=1);
namespace DigiForge\REST;
use DigiForge\POD\PrintifyWebhookInbox;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
final class PrintifyWebhookController
{
 public function register():void {add_action('rest_api_init',function():void{register_rest_route('digiforge/v1','/printify/webhooks/ingress/(?P<binding>[a-f0-9]{32})',['methods'=>'POST','callback'=>[$this,'receive'],'permission_callback'=>[$this,'permission']]);});}
 public function permission(WP_REST_Request $request):bool|WP_Error {if($request->get_method()!=='POST')return new WP_Error('digiforge_printify_webhook_method','POST required.',['status'=>405]);$result=PrintifyWebhookInbox::authenticate((string)($request->get_url_params()['binding']??''),$request->get_body(),(string)$request->get_header('x-pfy-signature'));return $result instanceof WP_Error?$result:true;}
 public function receive(WP_REST_Request $request):WP_REST_Response|WP_Error {$permission=$this->permission($request);if($permission instanceof WP_Error)return $permission;$result=PrintifyWebhookInbox::receive((string)($request->get_url_params()['binding']??''),$request->get_body(),(string)$request->get_header('x-pfy-signature'));return $result instanceof WP_Error?$result:new WP_REST_Response($result,200);}
}
