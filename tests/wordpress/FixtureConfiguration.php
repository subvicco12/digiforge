<?php
declare(strict_types=1);
/** Test harness only. Preserve old plugin tables; isolate each suite in a fresh bounded namespace. */
final class DigiForgeTestFixtureConfiguration {
 public static function resolve(string $original):array {
  if(getenv('WP_TESTS_SKIP_INSTALL')!=='1')return self::prepare($original);
  $path=getenv('DIGIFORGE_WP_SUITE_CONFIG');$prefix=getenv('DIGIFORGE_WP_SUITE_PREFIX');
  if(!is_string($path)||!is_readable($path)||!str_starts_with(basename($path),'df-wp-suite-')||!is_string($prefix)||!preg_match('/^df[a-f0-9]{9}_$/D',$prefix))throw new RuntimeException('Existing parent test namespace required for child worker.');
  return ['path'=>$path,'prefix'=>$prefix];
 }
 public static function prepare(string $original):array {
  $source=realpath($original);if($source===false||!is_readable($source))throw new RuntimeException('Readable WordPress test configuration required.');
  $path=tempnam(sys_get_temp_dir(),'df-wp-suite-');if($path===false)throw new RuntimeException('Private suite configuration unavailable.');
  $prefix='df'.substr(bin2hex(random_bytes(5)),0,9).'_';
  $body="<?php\nrequire ".var_export($source,true).";\n\$table_prefix=".var_export($prefix,true).";\n";
  if(!chmod($path,0600)||file_put_contents($path,$body)!==strlen($body)){@unlink($path);throw new RuntimeException('Private suite configuration persistence failed.');}
  putenv('DIGIFORGE_WP_SUITE_CONFIG='.$path);putenv('DIGIFORGE_WP_SUITE_PREFIX='.$prefix);
  register_shutdown_function(static function()use($path):void{@unlink($path);});return ['path'=>$path,'prefix'=>$prefix];
 }
}
