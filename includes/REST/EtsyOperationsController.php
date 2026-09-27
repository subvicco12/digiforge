<?php
declare(strict_types=1);
namespace DigiForge\REST;
use DigiForge\Operations\EtsyOperationsSnapshot;
use WP_REST_Response;
final class EtsyOperationsController {
 public function register():void {add_action('rest_api_init',function():void{register_rest_route('digiforge/v1','/etsy/operations',['methods'=>'GET','callback'=>[$this,'get'],'permission_callback'=>static fn():bool=>current_user_can('manage_digiforge_orders')&&current_user_can('manage_digiforge_connections')]);});}
 public function get():WP_REST_Response{return new WP_REST_Response(EtsyOperationsSnapshot::inspect(),200);}
}
