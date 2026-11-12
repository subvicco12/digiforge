<?php
declare(strict_types=1);
namespace DigiForgePrintifyConnector\Preparation;
use DigiForge\Connector\Printify\ShopBinding;
/** Optional extension of the SAME authoritative core; no companion store or dispatcher. */
interface WorkflowCoreInterface extends MutationCoreInterface {
 public function workflowVersion():string;
 public function authorizeWorkflow(ShopBinding $scope,int $actor,string $action):array|\WP_Error;
 public function withWebhookSecret(ShopBinding $scope,callable $verify):mixed;
 public function recordWorkflow(array $envelope):array|\WP_Error;
 public function inspectWorkflows(ShopBinding $scope,int $actor,int $limit):array|\WP_Error;
}
