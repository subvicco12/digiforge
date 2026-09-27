<?php
declare(strict_types=1);
namespace DigiForge\Listings;

/** Non-secret operational readiness projection for Etsy webhook ingress. */
final class EtsyWebhookReadiness {
 /** @return array<string,mixed> */
 public static function inspect():array {
  $configured=defined('DIGIFORGE_ETSY_WEBHOOK_SECRET')&&is_string(DIGIFORGE_ETSY_WEBHOOK_SECRET)&&str_starts_with(DIGIFORGE_ETSY_WEBHOOK_SECRET,'whsec_')&&strlen(DIGIFORGE_ETSY_WEBHOOK_SECRET)>6;
  return ['state'=>$configured?'READY':'CONFIGURATION_REQUIRED','signing_secret_configured'=>$configured,'supported_events'=>['order.paid','order.canceled','order.shipped','order.delivered'],'signature_verification_required'=>true,'replay_window_seconds'=>300,'external_execution_performed'=>false,'secret_exposed'=>false];
 }
}
