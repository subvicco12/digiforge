<?php
declare(strict_types=1);
namespace DigiForge\Listings;

/** Normalizes non-authoritative Etsy lifecycle metadata for local audit/review only. */
final class EtsyOrderEventEvidence {
 /** @return array<string,mixed> */
 public static function normalize(string $type,array $payload):array {
  $type=strtoupper(trim($type));$tracking=trim((string)($payload['tracking_code']??$payload['trackingCode']??''));$carrier=trim((string)($payload['carrier_name']??$payload['carrierName']??''));
  return ['event_type'=>$type,'tracking_present'=>$tracking!=='','carrier_present'=>$carrier!=='','tracking_fingerprint'=>$tracking===''?'':hash('sha256',$tracking),'carrier'=>$carrier===''?'':sanitize_text_field(substr($carrier,0,100)),'authoritative_fulfillment_state'=>false,'fulfillment_authorized'=>false,'external_execution_performed'=>false];
 }
}
