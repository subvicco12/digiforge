<?php
require_once __DIR__.'/SyntheticShopAuthority.php';
/** Synthetic isolated test authority only. Never use this fixture for provisioning. */
final class PortalAccessFixture {
 public static function reset():void{\DigiForge\Core\Capabilities::add();global $wpdb;foreach(['digiforge_portal_','digiforge_shop_access_state_','digiforge_shop_access_event_'] as $pre)$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",$wpdb->esc_like($pre).'%'));foreach(['portal_access_profile','portal_invitation','shop_access'] as $type)$wpdb->delete(\DigiForge\Database\Tables::audit_log(),['object_type'=>$type]);$_COOKIE=[];SyntheticShopAuthority::clear();}
 public static function login(int $actor):void{wp_set_current_user($actor);$token=WP_Session_Tokens::get_instance($actor)->create(time()+3600);$_COOKIE[LOGGED_IN_COOKIE]=wp_generate_auth_cookie($actor,time()+3600,'logged_in',$token);}
 public static function owner():int{$u=get_user_by('email','subvicco12@gmail.com');$id=$u?$u->ID:wp_insert_user(['user_login'=>'synthetic_owner','user_email'=>'subvicco12@gmail.com','user_pass'=>wp_generate_password(),'role'=>'administrator']);foreach(\DigiForge\Core\Capabilities::ALL as $c)get_userdata($id)->add_cap($c);self::sign($id);self::login($id);return $id;}
 public static function sign(int $actor,int $expiry=0):void{SyntheticShopAuthority::write([['actor_id'=>$actor,'shop'=>'personalized_pod','caps'=>\DigiForge\Core\Capabilities::ALL,'expires_at'=>$expiry?:time()+3600,'source_approval_hash'=>hash('sha256','synthetic1257'),'authority'=>'OWNER']]);}
 public static function enroll():int{$id=self::owner();$r=\DigiForge\PortalAccess\Owner::enroll('personalized_pod','administrator',\DigiForge\Core\Capabilities::ALL,time()+1800,hash('sha256','synthetic1257'),0,wp_generate_uuid4());if(is_wp_error($r))throw new RuntimeException($r->get_error_code());return $id;}
}
