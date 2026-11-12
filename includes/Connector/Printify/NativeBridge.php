<?php
declare(strict_types=1);
namespace DigiForge\Connector\Printify;

/** Inert discovery. Never includes the optional companion or resolves credentials. */
final class NativeBridge
{
 public static function connect(string $version):NativeCorePort|\WP_Error {
  if($version!=='1.0.0'||!self::compatible())return NativeFailure::error('companion_incompatible');
  try{NativePortContract::specification();return new NativeCorePort();}catch(\Throwable){return NativeFailure::error('contract_unavailable');}
 }
 private static function compatible():bool {
  $name=\DigiForgePrintifyConnector\Preparation\NativeCorePortInterface::class;
  try{
   if(!interface_exists($name))return false;
   $methods=[];$type=static function(?\ReflectionType $t):string{$parts=explode('|',(string)$t);sort($parts);return implode('|',$parts);};
   foreach((new \ReflectionClass($name))->getMethods()as $m){$params=[];foreach($m->getParameters()as $p)$params[]=[$type($p->getType()),$p->isOptional(),$p->isPassedByReference(),$p->isVariadic()];$methods[$m->getName()]=[$type($m->getReturnType()),$m->isStatic(),$m->returnsReference(),$params];}
   ksort($methods);
   return hash_equals('31b796a5d4dc65491e076fd18ba59a3edc074ea1ebf11a24e984150c93463902',hash('sha256',json_encode($methods,JSON_THROW_ON_ERROR)));
  }catch(\Throwable){return false;}
 }
}
