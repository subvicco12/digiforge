<?php
declare(strict_types=1);
namespace DigiForge\Connector\Printify;
interface ConnectorInterface {
 public function version():string;
 public function read(ExternalOperationIntent $intent):ProviderResult|\WP_Error;
}
