<?php
declare(strict_types=1);
// Offline package inspection only: no WordPress bootstrap, DB, vault or HTTP.
$root=$argv[1]??'';
if(!is_dir($root)||!is_file($root.'/digiforge.php'))exit(2);
spl_autoload_register(static function(string $class)use($root):void{if(str_starts_with($class,'DigiForge\\')){$p=$root.'/includes/'.str_replace('\\','/',substr($class,10)).'.php';if(is_file($p))require_once $p;}});
$r=DigiForge\Connector\Printify\HostCompatibility::inspect();
echo json_encode($r,JSON_THROW_ON_ERROR).PHP_EOL;
exit($r['native_contracts_available']===true&&$r['upload_ownership_available']===false&&$r['execution_authorized']===false?0:1);
