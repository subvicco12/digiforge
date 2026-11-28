<?php
declare(strict_types=1);
namespace DigiForge\Connector\Printify;
/** Fixed build-owned metadata, never a request/filter/constant supplied source pin. */
final class NativeBuildIdentity
{
 public static function source():string {
  $path=__DIR__.'/native-build.json';$bytes=@file_get_contents($path,false,null,0,65537);
  if(!is_string($bytes)||strlen($bytes)>65536)throw new \RuntimeException('Native build identity unavailable.');
  $m=json_decode($bytes,true,8,JSON_THROW_ON_ERROR);
  if(!is_array($m)||array_keys($m)!==['schema','source_sha','spec_sha256','files']||$m['schema']!=='DF_NATIVE_BUILD_1'||!is_string($m['source_sha'])||!preg_match('/^[a-f0-9]{40}$/D',$m['source_sha'])||$m['spec_sha256']!==NativePortContract::digest()||!is_array($m['files'])||count($m['files'])<1)throw new \RuntimeException('Native build identity incompatible.');
  $root=dirname(__DIR__,3).'/';
  foreach($m['files']as $file=>$hash){if(!is_string($file)||!preg_match('#^includes/(?:Connector/Printify/(?:[A-Za-z][A-Za-z0-9]*\.php|native-core-port\.v1\.json)|POD/PrintifyConnectorReadContract\.php)$#D',$file)||!is_string($hash)||!preg_match('/^[a-f0-9]{64}$/D',$hash)||!is_file($root.$file)||!hash_equals($hash,hash_file('sha256',$root.$file)))throw new \RuntimeException('Native build source integrity unavailable.');}
  // A stamp cannot silently omit runtime files added after its source was built.
  $actual=[];foreach(glob(__DIR__.'/*.php')?:[]as $file)$actual[]='includes/Connector/Printify/'.basename($file);
  $actual[]='includes/Connector/Printify/native-core-port.v1.json';$actual[]='includes/POD/PrintifyConnectorReadContract.php';$listed=array_keys($m['files']);sort($actual);sort($listed);
  if($actual!==$listed)throw new \RuntimeException('Native build inventory mismatch.');
  return $m['source_sha'];
 }
}
