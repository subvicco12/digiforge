<?php
declare(strict_types=1);
namespace DigiForgePrintifyConnector\Preparation;
use DigiForge\Connector\Printify\ShopBinding;
/** Offline preparation extension v1.0.0. Existing GET ConnectorInterface is unchanged. */
final class PreparationService {
 public function version():string{return '1.0.0';}
 public function upload(ShopBinding $scope,string $bytes,array $evidence,array $context):MutationIntent {
  $asset=ArtworkContract::inspect($bytes,$evidence,$scope);return new MutationIntent('IMAGE_UPLOAD',$scope,UploadContract::prepare($scope,$asset,$bytes),$context);
 }
 public function uploadFromCoreDescriptor(ShopBinding $scope,string $bytes,array $evidence,array $descriptor,array $context):MutationIntent {
  if(!is_int($context['actor_id']??null))throw new \InvalidArgumentException('Exact owner context required.');
  $asset=ArtworkContract::inspect($bytes,$evidence,$scope);
  return new MutationIntent('IMAGE_UPLOAD',$scope,UploadContract::prepareFromCoreDescriptor($scope,$asset,$bytes,$descriptor,$context['actor_id']),$context);
 }
 public function product(string $operation,ShopBinding $scope,array $input,array $evidence,array $context):MutationIntent {
  if(!class_exists(\DigiForge\POD\PrintifyProductRequest::class))throw new \RuntimeException('Compatible existing DigiForge product request contract required.');
  return new MutationIntent($operation,$scope,ProductDraftContract::prepare($operation,$scope,$input,$evidence),$context);
 }
 public function publish(ShopBinding $scope,array $input,array $evidence,array $context):MutationIntent{return new MutationIntent('PRODUCT_PUBLISH',$scope,PublicationContract::prepare($scope,$input,$evidence),$context);}
 public function status():array{return ['preparation_version'=>'1.0.0','core_product_contract_available'=>class_exists(\DigiForge\POD\PrintifyProductRequest::class),'native_mutation_host_available'=>false,'live_dispatch_available'=>false,'credentials_owned'=>false,'provider_execution_authorized'=>false];}
}
