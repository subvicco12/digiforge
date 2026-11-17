<?php
declare(strict_types=1);
namespace DigiForge\Connector\Printify;
/** Trusted DigiForge-owned execution port. Companion code never implements this authority. */
interface HostInterface {
 public function authorize(ExternalOperationIntent $intent):ShopBinding|\WP_Error;
 public function reserve(ExternalOperationIntent $intent,ShopBinding $binding):true|\WP_Error;
 public function consume(CredentialReference $reference,callable $consumer):mixed;
 public function record(array $receipt):bool;
}
