<?php
declare(strict_types=1);
// Isolated synthetic REST ingress only: never configure the parent PHPUnit process.
if(PHP_SAPI!=='cli'||count($argv)!==3)exit(2);
$f=json_decode((string)file_get_contents($argv[1]),true);
if(!is_array($f)||($f['synthetic_only']??null)!==true||!is_array($f['requests']??null))exit(2);
if(is_string($f['config']??null)&&$f['config']!=='')define('WP_TESTS_CONFIG_FILE_PATH',$f['config']);
define('DIGIFORGE_ETSY_WEBHOOK_SECRET','whsec_'.base64_encode('synthetic-rest-ingress-secret'));
define('WP_HTTP_BLOCK_EXTERNAL',true);putenv('WP_TESTS_SKIP_INSTALL=1');
require $f['autoload'];require dirname(__DIR__).'/bootstrap.php';
wp_set_current_user(0);global $wpdb;$wpdb->query('COMMIT');$wpdb->query('SET autocommit=1');
$http=0;$deny=static function()use(&$http){++$http;return new WP_Error('synthetic_ingress_http_forbidden');};add_filter('pre_http_request',$deny,PHP_INT_MAX);
$server=rest_get_server();$out=['actor_id'=>get_current_user_id(),'route_registered'=>isset($server->get_routes()['/digiforge/v1/etsy/webhook']),'responses'=>[]];
foreach($f['requests']as $case){
    $request=new WP_REST_Request('POST','/digiforge/v1/etsy/webhook');$request->set_header('Content-Type','application/json');
    foreach($case['headers']as $key=>$value)$request->set_header($key,$value);$request->set_body($case['body']);
    $faults=0;$mode=$case['fault']??'';
    $fault=static function(string $sql)use($mode,&$faults):string{
        $t=DigiForge\Database\Tables::class;
        $trip=($mode==='order_insert'&&str_starts_with($sql,'INSERT INTO `'.$t::orders().'`'))
            ||($mode==='order_transition'&&str_starts_with($sql,'UPDATE `'.$t::orders().'`'))
            ||($mode==='completion'&&str_starts_with($sql,'UPDATE ')&&str_contains($sql,$t::idempotency())&&str_contains($sql,"'PROCESSED'"));
        if($trip){++$faults;return 'INSERT INTO synthetic_missing_ingress_persistence (id) VALUES (1)';}return $sql;
    };
    $old=$wpdb->suppress_errors(true);if($mode!=='')add_filter('query',$fault);
    try{$response=rest_do_request($request);$out['responses'][]=['status'=>$response->get_status(),'data'=>$response->get_data(),'header_keys'=>array_keys($request->get_headers()),'faults'=>$faults];}
    finally{if($mode!=='')remove_filter('query',$fault);$wpdb->suppress_errors($old);}
}
$out['enabled_switches']=[];foreach(DigiForge\Core\Config::SWITCHES as $switch)if(DigiForge\Core\Settings::is_enabled($switch))$out['enabled_switches'][]=$switch;$out['http_calls']=$http;$out['stop_all']=DigiForge\Core\Settings::get('stop_all');$out['automation_armed']=DigiForge\Core\Settings::get('automation_armed');$out['safety_locked']=DigiForge\Core\Settings::safety_locked();$out['actor_after']=get_current_user_id();
file_put_contents($argv[2],wp_json_encode($out));exit(0);
