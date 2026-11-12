<?php
declare(strict_types=1);
namespace DigiForgePrintifyConnector;
/** Companion owns no provider transport, configuration, credentials, routes or scheduled work. */
final class Plugin {
 private static bool $registered=false;
 private static ?\DigiForge\Connector\Printify\ConnectorInterface $connector=null;
 private static string $state='NOT_BOOTED';
 private function __construct(){}
 public static function register():void{if(self::$registered)return;self::$registered=true;add_action('plugins_loaded',[self::class,'boot'],20);if(did_action('plugins_loaded'))self::boot();}
 public static function boot():void {
  if(self::$connector!==null)return;
  try{
   if(!class_exists(\DigiForge\Connector\Printify\Bridge::class)||!interface_exists(\DigiForge\Connector\Printify\ConnectorInterface::class)){self::$state='MISSING_HOST_INTERFACE';return;}
   $connector=\DigiForge\Connector\Printify\Bridge::connect('1.0.0');
   if(!$connector instanceof \DigiForge\Connector\Printify\ConnectorInterface||$connector->version()!=='1.0.0'){self::$state='INCOMPATIBLE_HOST_INTERFACE';return;}
   self::$connector=$connector;self::$state='INTERFACE_AVAILABLE_AUTHORITY_UNVERIFIED';
  }catch(\Throwable){self::$state='INCOMPATIBLE_HOST_INTERFACE';}
 }
 public static function connector():?\DigiForge\Connector\Printify\ConnectorInterface{return self::$connector;}
 public static function status():array{return ['interface_version'=>'1.0.0','state'=>self::$state,'read_only'=>true,'provider_execution_authorized'=>false,'credential_storage'=>false,'scheduling'=>false];}
}
