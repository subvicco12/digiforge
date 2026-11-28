<?php
declare(strict_types=1);
namespace DigiForge\Connector\Printify;
use DigiForge\Core\Settings;
use DigiForge\POD\PrintifyConnectorReadContract;
/** No alternate sender. Independently reviewed connection identity alone is never a GET grant. */
final class NativeReadConnector implements ConnectorInterface
{
 public function version():string{return '1.0.0';}
 public function read(ExternalOperationIntent $intent):ProviderResult|\WP_Error {
  try{
   PrintifyConnectorReadContract::prepare($intent->operation,'1',$intent->input);
   if(Settings::safety_locked()||Settings::get('stop_all',true)!==false||!Settings::is_enabled('printify'))return NativeFailure::error('external_locked');
   return NativeAuthorityAdapter::read($intent);
  }catch(\Throwable){return NativeFailure::error('read_unavailable');}
 }
}
