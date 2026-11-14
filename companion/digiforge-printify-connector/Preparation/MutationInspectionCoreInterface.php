<?php
declare(strict_types=1);
namespace DigiForgePrintifyConnector\Preparation;
/** Optional local-read port. Core separately checks CURRENT operator/session/shop read permissions.
 * Inspection never grants mutation permission and must perform SELECT-only journal reads.
 */
interface MutationInspectionCoreInterface extends MutationCoreInterface {
 public function inspectionVersion():string;
 public function inspect(array $binding):array|\WP_Error;
}
