<?php
declare(strict_types=1);
namespace DigiForge\Connector\Printify;
/** Fixed, bounded JSON-schema subset; same bytes are read, hashed and parsed once. No remote refs. */
final class NativePortContract {
 public const DIGEST='6d19ac1c9971d5dd730fa910d08b34ad868e7d0f351b5996029dd60322ed3434';
 public static function hash(array $value):string {
  $sort=static function(array $v)use(&$sort):array{foreach($v as &$x)if(is_array($x))$x=$sort($x);unset($x);if(!array_is_list($v))ksort($v);return $v;};
  return hash('sha256',json_encode($sort($value),JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION));
 }
 public static function digest():string{return self::DIGEST;}
 public static function specification():array {
  $b=file_get_contents(__DIR__.'/native-core-port.v1.json',false,null,0,65537);if(!is_string($b)||strlen($b)>65536||!hash_equals(self::DIGEST,hash('sha256',$b)))throw new \RuntimeException('Native port contract integrity unavailable.');$s=json_decode($b,true,32,JSON_THROW_ON_ERROR);if(($s['contract']??null)!=='DF_NATIVE_CORE_PORT_1'||($s['version']??null)!=='1.0.0')throw new \RuntimeException('Native port contract incompatible.');return $s;
 }
 public static function valid(string $definition,mixed $value):bool {try{$s=self::specification();if(!self::safe($value,0))return false;$json=json_encode($value,JSON_THROW_ON_ERROR);if(strlen($json)>4194304||!isset($s['$defs'][$definition]))return false;return self::check($s['$defs'][$definition],$value,$s['$defs'],0);}catch(\Throwable){return false;}}
 private static function safe(mixed $v,int $depth):bool{if($depth>32||is_object($v)||is_resource($v))return false;if(is_array($v))foreach($v as $x)if(!self::safe($x,$depth+1))return false;return true;}
 private static function check(array $r,mixed $v,array $defs,int $depth):bool {
  if($depth>32)return false;if(isset($r['$ref'])){$name=substr($r['$ref'],8);return str_starts_with($r['$ref'],'#/$defs/')&&isset($defs[$name])&&self::check($defs[$name],$v,$defs,$depth+1);}
  $types=(array)($r['type']??[]);$ok=false;foreach($types as $t)$ok=$ok||match($t){'object'=>is_array($v)&&($v===[]||!array_is_list($v)),'array'=>is_array($v)&&array_is_list($v),'integer'=>is_int($v),'string'=>is_string($v),'boolean'=>is_bool($v),'null'=>$v===null,default=>false};if(!$ok)return false;
  if(isset($r['enum'])&&!in_array($v,$r['enum'],true))return false;
  if(is_string($v)){if(strlen($v)<($r['minLength']??0)||strlen($v)>($r['maxLength']??4194304))return false;if(isset($r['pattern'])&&preg_match('~'.$r['pattern'].'~D',$v)!==1)return false;}
  if(is_int($v)&&($v<($r['minimum']??PHP_INT_MIN)||$v>($r['maximum']??PHP_INT_MAX)))return false;
  if(is_array($v)&&in_array('object',$types,true)){foreach($r['required']??[]as $k)if(!array_key_exists($k,$v))return false;$p=$r['properties']??[];foreach($v as $k=>$x){if(isset($p[$k])){if(!self::check($p[$k],$x,$defs,$depth+1))return false;}elseif(($r['additionalProperties']??true)===false)return false;}}
  if(is_array($v)&&in_array('array',$types,true)){if(count($v)>($r['maxItems']??1000)||count($v)<($r['minItems']??0))return false;if(($r['uniqueItems']??false)&&count(array_unique($v,SORT_REGULAR))!==count($v))return false;foreach($v as $x)if(!self::check($r['items']??[], $x,$defs,$depth+1))return false;}
  return true;
 }
}
