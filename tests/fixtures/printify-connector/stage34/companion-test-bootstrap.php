<?php
declare(strict_types=1);
require_once dirname(__DIR__,4).'/includes/Connector/Printify/CredentialReference.php';
require_once dirname(__DIR__,4).'/includes/Connector/Printify/ShopBinding.php';
if(!class_exists(\DigiForge\POD\PrintifyProductRequest::class))require_once __DIR__.'/CoreProductRequest.php';
spl_autoload_register(static function(string $class):void{$p='DigiForgePrintifyConnector\\';if(str_starts_with($class,$p)){$path=dirname(__DIR__,4).'/companion/digiforge-printify-connector/'.str_replace('\\','/',substr($class,strlen($p))).'.php';if(is_file($path))require_once $path;}});
if(!function_exists('is_wp_error')){function is_wp_error(mixed $v):bool{return $v instanceof WP_Error;}}

require_once __DIR__."/SyntheticCoreMutationHost.php";
