<?php

declare(strict_types=1);

namespace DigiForge\POD;

use DigiForge\Listings\Validator;
use InvalidArgumentException;

/** Provider-side manual declarations supplement Etsy questions; never certify actual native behavior. */
final class ProductPackageSpecification
{
    public static function normalize(array $input, array $layers): array
    {
        Validator::structured($input);
        if (array_diff(array_keys($input), ['native_fields','shipping'])) {
            throw new InvalidArgumentException('Unsupported specification field.');
        }
        $fields = $input['native_fields'] ?? [];
        $shipping = $input['shipping'] ?? [];
        if (!is_array($fields) || !array_is_list($fields) || count($fields) > 10 || !is_array($shipping)) {
            throw new InvalidArgumentException('Bounded native fields and shipping object required.');
        }
        $seen = [];
        $slots = [];
        $blockers = ['NATIVE_PROVIDER_ACCEPTANCE_REQUIRED'];
        foreach ($fields as $field) {
            if (
                !is_array($field) || !is_string($field['layer'] ?? null) || !in_array($field['layer'], $layers, true)
                || isset($seen[$field['layer']]) || !in_array($field['type'] ?? null, ['TEXT','PHOTO'], true)
                || !is_bool($field['required'] ?? null)
            ) {
                throw new InvalidArgumentException('Exact unique approved native layer required.');
            }
            $seen[$field['layer']] = true;
            $allowed = ['layer','type','provider_field_id','required'];
            if ($field['type'] === 'TEXT') {
                $allowed[] = 'max_characters';
                if (!is_int($field['max_characters'] ?? null) || $field['max_characters'] < 1 || $field['max_characters'] > 1024) {
                    throw new InvalidArgumentException('Text character bound required.');
                }
            } else {
                $allowed[] = 'max_files';
                $allowed[] = 'slot';
                if (
                    !is_int($field['max_files'] ?? null) || $field['max_files'] < 1 || $field['max_files'] > 10
                    || !is_int($field['slot'] ?? null) || $field['slot'] < 1 || $field['slot'] > 10 || isset($slots[$field['slot']])
                ) {
                    throw new InvalidArgumentException('Unique bounded photo slot required.');
                }
                $slots[$field['slot']] = true;
            }
            if (array_diff(array_keys($field), $allowed)) {
                throw new InvalidArgumentException('Unsupported native field instruction.');
            }
            $id = $field['provider_field_id'] ?? null;
            if ($id !== null && (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{1,120}$/D', $id))) {
                throw new InvalidArgumentException('Invalid native provider field ID.');
            }
            if ($id === null) {
                $blockers[] = 'NATIVE_PROVIDER_FIELD_EVIDENCE_REQUIRED';
            }
        }
        if (count($seen) !== count(array_unique($layers))) {
            $blockers[] = 'NATIVE_LAYER_MAPPING_INCOMPLETE';
        }
        $defaults = ['destination' => 'UNKNOWN','processing_min_days' => null,'processing_max_days' => null,
            'source_reference' => null,'source_sha256' => null];
        if (array_diff(array_keys($shipping), array_keys($defaults))) {
            throw new InvalidArgumentException('Unsupported shipping declaration.');
        }
        $shipping = array_replace($defaults, $shipping);
        if (!in_array($shipping['destination'], ['US','UNKNOWN'], true)) {
            throw new InvalidArgumentException('US or unknown destination required.');
        }
        foreach (['processing_min_days','processing_max_days'] as $key) {
            $v = $shipping[$key];
            if ($v !== null && (!is_int($v) || $v < 1 || $v > 60)) {
                throw new InvalidArgumentException('Bounded processing days required.');
            }
        }
        if (
            $shipping['processing_min_days'] !== null && $shipping['processing_max_days'] !== null
            && $shipping['processing_min_days'] > $shipping['processing_max_days']
        ) {
            throw new InvalidArgumentException('Processing range reversed.');
        }
        if (($shipping['source_reference'] === null) !== ($shipping['source_sha256'] === null)) {
            throw new InvalidArgumentException('Shipping source reference and checksum required together.');
        }
        if (
            $shipping['source_reference'] !== null && (!is_string($shipping['source_reference'])
            || !preg_match('#^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_.-]+)+$#D', $shipping['source_reference'])
            || str_contains($shipping['source_reference'], '..') || strlen($shipping['source_reference']) > 512
            || !is_string($shipping['source_sha256']) || !preg_match('/^[a-f0-9]{64}$/D', $shipping['source_sha256']))
        ) {
            throw new InvalidArgumentException('Bounded shipping source metadata required.');
        }
        $blockers[] = 'SHIPPING_PROCESSING_PROVIDER_REVIEW_REQUIRED';
        return ['native_fields' => $fields,'overflow_policy' => 'MANUAL_REVIEW','photo_policy' => 'MANUAL_CROP_AND_ORDER_REVIEW',
            'shipping' => $shipping,'provenance' => 'MANUAL_DECLARATIONS_UNVERIFIED',
            'supplier_certified' => false,'blockers' => array_values(array_unique($blockers))];
    }
}
