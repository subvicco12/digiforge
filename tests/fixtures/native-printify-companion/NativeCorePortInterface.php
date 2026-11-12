<?php
declare(strict_types=1);
namespace DigiForgePrintifyConnector\Preparation;
use DigiForge\Connector\Printify\{ShopBinding,ExternalOperationIntent,ProviderResult};
/** One CORE implementation, not a new dispatcher/store. Returned only by the reviewed fixed factory. */
interface NativeCorePortInterface extends MutationInspectionCoreInterface,PublishingCoreInterface,OperationsCoreInterface {
 public function contractManifest():array;
 public function read(ExternalOperationIntent $intent):ProviderResult|\WP_Error;
 public function allocation(ShopBinding $scope,int $actor,array $request):array|\WP_Error;
 public function recovery(ShopBinding $scope,int $actor,array $request):array|\WP_Error;
 public function panelAccess(ShopBinding $scope,int $actor,array $context):array|\WP_Error;
}
