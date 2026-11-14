<?php
declare(strict_types=1);
namespace DigiForgePrintifyConnector\Preparation;
/** Explicit core-owned publication capability; generic product approval is insufficient. */
interface PublishingCoreInterface extends MutationCoreInterface {
 public function publicationVersion():string;
 public function authorizePublication(array $binding,array $prepared):true|\WP_Error;
}
