<?php
declare(strict_types=1);
namespace DigiForgePrintifyConnector\Preparation;
use DigiForge\Connector\Printify\ShopBinding;
/** Optional extension of the SAME core authority/journal. No companion-owned persistence. */
interface OperationsCoreInterface extends WorkflowCoreInterface {
 public function operationsVersion():string;
 public function reviewQueue(ShopBinding $scope,int $actor,int $limit):array|\WP_Error;
 public function decideReview(array $request):array|\WP_Error;
 public function inspectFulfillment(ShopBinding $scope,int $actor,string $orderIdentity):ShipmentReadModel|\WP_Error;
 public function recordFulfillment(array $request):array|\WP_Error;
}
