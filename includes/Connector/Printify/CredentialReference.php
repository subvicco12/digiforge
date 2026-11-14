<?php
declare(strict_types=1);
namespace DigiForge\Connector\Printify;
/** Non-secret reference only. The existing DigiForge vault retains all material. */
final readonly class CredentialReference {
 public function __construct(public int $integrationId){if($integrationId<1)throw new \InvalidArgumentException('Integration reference required.');}
}
