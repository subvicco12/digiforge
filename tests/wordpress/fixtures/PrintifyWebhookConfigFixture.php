<?php
/** Shared synthetic private configuration, never overwrites pre-provisioned host configuration. */
final class PrintifyWebhookConfigFixture{
 private static ?string $path=null;
 public static function path():string{
  if(self::$path===null){if(defined('DIGIFORGE_PRINTIFY_WEBHOOK_CONFIG'))throw new RuntimeException('Refusing pre-provisioned webhook configuration.');self::$path=tempnam(sys_get_temp_dir(),'df-synthetic-webhook-');chmod(self::$path,0600);define('DIGIFORGE_PRINTIFY_WEBHOOK_CONFIG',self::$path);register_shutdown_function(static function(){if(self::$path!==null&&is_file(self::$path))unlink(self::$path);});}
  return self::$path;
 }
}
