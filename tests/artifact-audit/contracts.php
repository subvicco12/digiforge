<?php
declare(strict_types=1);
class WP_Error {public function __construct(public string $code='',public string $message='',public mixed $data=null){} public function get_error_code(){return $this->code;}}
class WP_REST_Request {private array $params=[];public function __construct(private string $method,private string $route){} public function get_route(){return $this->route;} public function get_method(){return $this->method;} public function get_param($key){return $this->params[$key]??null;} public function set_param($key,$value){$this->params[$key]=$value;}}
function is_wp_error($v){return $v instanceof WP_Error;}
spl_autoload_register(function($c){if(str_starts_with($c,'DigiForge\\')){$p=getenv('EXACT_PLUGIN_ROOT').'/includes/'.str_replace('\\','/',substr($c,10)).'.php';if(is_file($p))require $p;}});
use DigiForge\POD\PrintifyCatalogReadContract as Contract;
use DigiForge\POD\PrintifyResearchDependencies as Deps;
use DigiForge\Security\ShopAccessRestGuard as RestGuard;
use DigiForge\Security\ShopAccessTargetScope as Target;
use DigiForge\Security\ShopAccessAdminGuard as Admin;
$fail=[];$checks=0;
function check($v,$label){global $fail,$checks;++$checks;if(!$v)$fail[]=$label;}
foreach(Deps::requirements() as $c=>$methods)foreach($methods as $m)check(class_exists($c)&&method_exists($c,$m),$c.'::'.$m);
foreach(Deps::routes() as $r){$sets=RestGuard::capabilitySets($r['route'],$r['method']);check(count($sets)===1&&count($sets[0])===4,'exact route caps '.$r['route']);$q=new WP_REST_Request($r['method'],$r['route']);check(Target::forRequest($q,['personalized_pod'])==='personalized_pod','fixed Shop2 target '.$r['route']);}
check(method_exists(Admin::class,'scopeForAction'),'pure exact admin scope contract');
try {check(Contract::endpoint(['kind'=>'size_guide','blueprint_id'=>1])==='/v1/catalog/blueprints/1/size_guide.json','size guide endpoint');}catch(Throwable){check(false,'size guide endpoint');}
check(Deps::inspect()['portal_controls_ready']===true,'source integration ready without synthesized owner');
echo json_encode(['checks'=>$checks,'failures'=>$fail],JSON_PRETTY_PRINT)."\n";exit($fail?1:0);
