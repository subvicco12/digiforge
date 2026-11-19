<?php
/** Pinned interface bytes only. Synthetic claims below are never owner approvals. */
declare(strict_types=1);
spl_autoload_register(static function(string $class):void {
 $prefix='DigiForge\\';if(str_starts_with($class,$prefix)){$path=__DIR__.'/../../includes/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';if(is_file($path))require_once $path;return;}
 $prefix='DigiForgePrintifyConnector\\Preparation\\';if(str_starts_with($class,$prefix)){$name=substr($class,strlen($prefix));if(preg_match('/^[A-Za-z]+$/D',$name)){$path=__DIR__.'/../fixtures/native-printify-companion/'.$name.'.php';if(is_file($path))require_once $path;}}
});
final class NativePrintifyContractFixture {
 public static function binding():array{return ['operation_id'=>str_repeat('a',64),'payload_fingerprint'=>str_repeat('b',64),'intent_fingerprint'=>str_repeat('c',64),'scope_fingerprint'=>str_repeat('d',64),'approval_reference'=>str_repeat('e',64),'shop_id'=>2,'integration_id'=>3,'credential_reference'=>3,'actor_id'=>1,'authorization_revision'=>1,'shop_key'=>'personalized_pod','provider_shop_id'=>'28930593','environment'=>'test','product_version'=>1,'expected_product_version'=>1];}
 public static function mutation(string $action):array{$b=self::binding();return ['contract_version'=>'1.0.0','action'=>$action,'binding'=>$b,'context'=>['actor_id'=>1,'logical_reference'=>'SYNTHETIC_ONLY','approval_reference'=>$b['approval_reference'],'authorization_revision'=>1,'product_version'=>1,'expected_product_version'=>1],'evidence_references'=>[],'duplicate_key'=>str_repeat('f',64),'data'=>[]];}
}
