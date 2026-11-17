<?php
declare(strict_types=1);
namespace DigiForge\Connector\Printify;
/** Non-secret source/runtime contract discovery. Never boots WordPress or touches the vault/DB. */
final class HostCompatibility {
 public static function inspect():array {
  $required=[
   'DigiForge\\POD\\PrintifyCatalogClient'=>'connectorRead',
   'DigiForge\\POD\\ScopedProviderConnection'=>'verified',
   'DigiForge\\POD\\PrintifyConnectionBoundary'=>'context',
   'DigiForge\\POD\\PrintifyScopedCredentialRetriever'=>'consume',
   'DigiForge\\Security\\ShopAccess'=>'authorize',
   'DigiForge\\Core\\Settings'=>'is_enabled',
   'DigiForge\\Integrations\\Repository'=>'find',
   'DigiForge\\Security\\Logger'=>'write',
  ];
  $missing=[];foreach($required as $class=>$method)if(!class_exists($class)||!method_exists($class,$method))$missing[]=$class.'::'.$method;
  $upload=class_exists('DigiForge\\POD\\PrintifyUploadOwnership')&&method_exists('DigiForge\\POD\\PrintifyUploadOwnership','resolve');
  return ['interface_version'=>'1.0.0','native_contracts_available'=>$missing===[],'missing_contracts'=>$missing,'upload_ownership_available'=>$upload,'execution_authorized'=>false,'runtime_authority_verified'=>false,'production_equivalence_verified'=>false];
 }
}
