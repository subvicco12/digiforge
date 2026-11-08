<?php
declare(strict_types=1);
/** Definitions-only profile: shared core present except the inventory's missing OWNER helper. */
final class WP_Error
{
    public function __construct(private string $code,private string $message='',private array $data=[]){}
    public function get_error_code(): string {return $this->code;}
}
$hooks=0;$http=0;$mode=$argv[1]??'missing-owner';
if($mode==='unmapped-guards')eval('namespace DigiForge\\Security; final class ShopAccessRestGuard {} final class ShopAccessTargetScope {} final class ShopAccessAdminGuard {}');
function add_action(...$args): void {global $hooks;$hooks++;throw new RuntimeException('Unexpected registration');}
function add_filter(...$args): void {global $hooks;$hooks++;throw new RuntimeException('Unexpected registration');}
function wp_remote_get(...$args): void {global $http;$http++;throw new RuntimeException('HTTP forbidden');}
function wp_remote_request(...$args): void {global $http;$http++;throw new RuntimeException('HTTP forbidden');}
spl_autoload_register(static function(string $class)use($mode):void {
    if(!str_starts_with($class,'DigiForge\\')||($mode==='missing-owner'&&$class==='DigiForge\\Security\\ShopAccessAuthority'))return;
    $path=dirname(__DIR__,2).'/includes/'.str_replace('\\','/',substr($class,10)).'.php';if(is_file($path))require_once $path;
});
$report=\DigiForge\POD\PrintifyResearchDependencies::inspect();
$result=(new \DigiForge\Portal\PrintifyProductIntelligenceFeature())->register();
if(!$result instanceof WP_Error)throw new RuntimeException('Missing owner did not deny');
echo json_encode(['missing'=>$report['missing_dependencies'],'error'=>$result->get_error_code(),'hooks'=>$hooks,'http_calls'=>$http,'portal_controls_ready'=>$report['portal_controls_ready'],'rest_admission_mapped'=>$report['rest_admission_mapped']],JSON_THROW_ON_ERROR);
