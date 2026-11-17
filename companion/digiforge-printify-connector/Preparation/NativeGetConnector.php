<?php
declare(strict_types=1);
namespace DigiForgePrintifyConnector\Preparation;
use DigiForge\Connector\Printify\{ConnectorInterface,ExternalOperationIntent,ProviderResult};
/** Optional shim to the preserved GET interface. Actual transport/custody stay in core. */
final class NativeGetConnector implements ConnectorInterface {
 public function __construct(private NativePortAdapter $native){}
 public function version():string{return '1.0.0';}
 public function read(ExternalOperationIntent $i):ProviderResult|\WP_Error{return $this->native->read($i);}
}
