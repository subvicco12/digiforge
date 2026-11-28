<?php
declare(strict_types=1);
namespace DigiForge\Connector\Printify;
/** Preserved GET-only discovery shape. Never constructs a transport or touches the vault. */
final class Bridge
{
 public const API_VERSION='1.0.0';
 public static function connect(string $version):ConnectorInterface|\WP_Error {
  return $version===self::API_VERSION?new NativeReadConnector():NativeFailure::error('get_version');
 }
}
