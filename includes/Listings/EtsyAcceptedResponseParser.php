<?php
declare(strict_types=1);

namespace DigiForge\Listings;

use WP_Error;

/**
 * Parses only the minimum non-secret identity required from an accepted Etsy
 * response. Raw provider bodies are never returned or persisted.
 */
final class EtsyAcceptedResponseParser
{
    /** @return array<string,mixed>|WP_Error */
    public static function parse(string $operationType,string $body,string $existingReference=''): array|WP_Error
    {
        $operationType=strtoupper(trim($operationType));
        $existingReference=trim($existingReference);
        if (strlen($body)>1048576) return self::error('body_size','Etsy response body exceeds the bounded parser limit.');

        $decoded=json_decode($body,true);
        if (!is_array($decoded)) return self::error('json','Accepted Etsy response must contain valid JSON.');

        $candidate=$decoded['listing_id']??($decoded['results'][0]['listing_id']??null);
        $reference=is_int($candidate)||is_string($candidate)?trim((string)$candidate):'';
        if ($operationType==='CREATE_DRAFT') {
            if (!ctype_digit($reference) || (int)$reference<1) return self::error('listing_id','Created Etsy draft response requires a positive listing_id.');
        } else {
            if ($reference==='' && ctype_digit($existingReference) && (int)$existingReference>0) $reference=$existingReference;
            if (!ctype_digit($reference) || (int)$reference<1) return self::error('listing_id','Etsy draft mutation requires a bounded listing identity.');
            if ($existingReference!=='' && !hash_equals($existingReference,$reference)) return self::error('identity','Etsy response listing identity does not match the persisted operation resource.');
        }

        return [
            'state'=>'ETSY_ACCEPTED_RESPONSE_PARSED',
            'external_reference'=>$reference,
            'raw_body_returned'=>false,
            'credentials_exposed'=>false,
            'external_execution_performed'=>false,
        ];
    }

    private static function error(string $code,string $message): WP_Error
    {
        return new WP_Error('digiforge_etsy_response_'.$code,$message,['status'=>409]);
    }
}
