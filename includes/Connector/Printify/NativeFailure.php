<?php
declare(strict_types=1);
namespace DigiForge\Connector\Printify;
/** Fixed non-secret reason codes; denial never acknowledges persistence or provider outcome. */
final class NativeFailure
{
 public static function error(string $reason):\WP_Error {
  return new \WP_Error('printify_native_'.$reason,'Native Printify authority unavailable: '.$reason.'.',[
   'status'=>409,'external_execution_authorized'=>false,'network_request_attempted'=>false,'retry_permitted'=>false,
  ]);
 }
}
