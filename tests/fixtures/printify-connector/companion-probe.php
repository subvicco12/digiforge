<?php
declare(strict_types=1);
$root=dirname(__DIR__,3);$hooks=[];$http=0;define('ABSPATH','synthetic-fixture');
function add_action($name,$callback,$priority=10){$GLOBALS['hooks'][$name][]=$callback;}
function did_action($name){return 0;}
function wp_remote_get(){++$GLOBALS['http'];throw new RuntimeException('NO_NETWORK');}
function register_rest_route(){throw new RuntimeException('NO_ROUTE');}
function wp_schedule_event(){throw new RuntimeException('NO_SCHEDULE');}
class WP_Error {public function __construct(...$args){}}
if(($argv[1]??'')==='loaded'){define('DIGIFORGE_VERSION','1.0.103');spl_autoload_register(static function($class)use($root){if(str_starts_with($class,'DigiForge\\')){$path=$root.'/includes/'.str_replace('\\','/',substr($class,10)).'.php';if(is_file($path))require_once $path;}});}
require $root.'/companion/digiforge-printify-connector/digiforge-printify-connector.php';
DigiForgePrintifyConnector\Plugin::register();DigiForgePrintifyConnector\Plugin::boot();DigiForgePrintifyConnector\Plugin::boot();
echo json_encode(['hooks'=>count($hooks['plugins_loaded']??[]),'http_calls'=>$http,'status'=>DigiForgePrintifyConnector\Plugin::status()['state']],JSON_THROW_ON_ERROR);
