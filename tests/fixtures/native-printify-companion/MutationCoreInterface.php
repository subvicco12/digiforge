<?php
declare(strict_types=1);
namespace DigiForgePrintifyConnector\Preparation;
/** Implemented ONLY by the authoritative core integration, never a browser-selected callback.
 * Core atomically validates current approval/binding/safety and persists journal transitions.
 * No method sends HTTP or returns provider credentials. Epoch/checkpoint authority is core-owned.
 */
interface MutationCoreInterface {
 public function version():string;
 public function exchange(array $request):array|\WP_Error;
}
