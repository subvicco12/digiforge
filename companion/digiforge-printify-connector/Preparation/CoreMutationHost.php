<?php
declare(strict_types=1);
namespace DigiForgePrintifyConnector\Preparation;
/** OPTIONAL DigiForge-owned port. Never supplied by browser input or implemented as a second companion store.
 * Host resolves current capabilities/shop/account/credential association, real evidence and independent approval.
 * register/claim/outcome MUST be atomic, durable and audited. Unknown/lost acknowledgement cannot re-arm.
 * A reservation is not provider execution permission; only a future reviewed CORE final sender may consume it.
 */
interface CoreMutationHost {
 public function authorize(MutationIntent $intent):array|\WP_Error;
 public function register(MutationIntent $intent):array|\WP_Error;
 public function claimOnce(MutationIntent $intent,array $authority):array|\WP_Error;
 public function responseEvidence(MutationIntent $intent,string $receiptReference):array|\WP_Error;
 public function outcome(MutationIntent $intent,string $state,array $receipt):bool;
}
