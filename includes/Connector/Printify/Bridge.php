<?php
declare(strict_types=1);
namespace DigiForge\Connector\Printify;
/** Versioned host discovery; constructing a connector never authorizes or dispatches. */
final class Bridge {
 public const API_VERSION='1.0.0';
 public static function connect(string $version):ConnectorInterface|\WP_Error {
  if($version!==self::API_VERSION||!defined('DIGIFORGE_VERSION')||version_compare(DIGIFORGE_VERSION,'1.0.103','<')||!method_exists(\DigiForge\POD\PrintifyCatalogClient::class,'connectorRead'))return new \WP_Error('printify_host_incompatible','Compatible DigiForge connector host is required.',['status'=>409]);
  return new ReadConnector(new NativeHost());
 }
}
