<?php

declare(strict_types=1);

namespace DigiForge\POD;

use DigiForge\Database\Tables;

/**
 * Canonical ownership boundary for POD business-active records.
 * Supplier catalog records remain shared and must not derive ownership from a
 * provider product/blueprint.
 */
final class BusinessScope
{
    public const PERSONALIZED_POD = 'PERSONALIZED_POD';
    public const ORIGINAL_DESIGN_POD = 'ORIGINAL_DESIGN_POD';
    public const DIGICRAFTIFY_GOODS = 'digicraftifygoods';

    /** @return array{business_id:string,store_id:string,product_program:string} */
    public static function normalize(array $input): array
    {
        $businessId = self::requiredToken($input['business_id'] ?? null, 'business_id');
        $storeId = self::requiredToken($input['store_id'] ?? null, 'store_id');
        $program = self::program($input['product_program'] ?? null);

        if ($businessId === self::DIGICRAFTIFY_GOODS && $program !== self::PERSONALIZED_POD) {
            throw new \InvalidArgumentException('DigiCraftifyGoods is restricted to PERSONALIZED_POD');
        }

        return ['business_id' => $businessId, 'store_id' => $storeId, 'product_program' => $program];
    }

    /**
     * Resolve the persisted ownership registry before a business-active POD
     * record is created. Inputs may use numeric primary IDs or configured keys.
     *
     * @return array{business_id:int,store_id:int,product_program_id:int,product_program:string}
     */
    public static function resolveConfigured(array $input): array
    {
        global $wpdb;
        $program = self::program($input['product_program'] ?? null);
        $businessRef = self::requiredToken($input['business_id'] ?? null, 'business_id');
        $storeRef = self::requiredToken($input['store_id'] ?? null, 'store_id');

        $business = ctype_digit($businessRef)
            ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Tables::businesses() . ' WHERE id=%d LIMIT 1', (int) $businessRef), ARRAY_A)
            : $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Tables::businesses() . ' WHERE business_key=%s LIMIT 1', $businessRef), ARRAY_A);
        if (!is_array($business) || (int) ($business['id'] ?? 0) < 1) {
            throw new \InvalidArgumentException('configured business is required');
        }
        $businessKey = sanitize_key((string) ($business['business_key'] ?? ''));
        if ($businessKey === self::DIGICRAFTIFY_GOODS && $program !== self::PERSONALIZED_POD) {
            throw new \InvalidArgumentException('DigiCraftifyGoods is restricted to PERSONALIZED_POD');
        }

        $businessId = (int) $business['id'];
        $store = ctype_digit($storeRef)
            ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Tables::stores() . ' WHERE id=%d AND business_id=%d LIMIT 1', (int) $storeRef, $businessId), ARRAY_A)
            : $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Tables::stores() . ' WHERE store_key=%s AND business_id=%d LIMIT 1', $storeRef, $businessId), ARRAY_A);
        if (!is_array($store) || (int) ($store['id'] ?? 0) < 1) {
            throw new \InvalidArgumentException('configured store owned by business is required');
        }

        $storeId = (int) $store['id'];
        $programRow = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . Tables::product_programs() . ' WHERE business_id=%d AND store_id=%d AND program_key=%s LIMIT 1',
            $businessId,
            $storeId,
            $program
        ), ARRAY_A);
        if (!is_array($programRow) || (int) ($programRow['id'] ?? 0) < 1) {
            throw new \InvalidArgumentException('configured product program is required for business/store');
        }

        return [
            'business_id' => $businessId,
            'store_id' => $storeId,
            'product_program_id' => (int) $programRow['id'],
            'product_program' => $program,
        ];
    }

    public static function assertMatches(array $expected, array $actual): void
    {
        $expected = self::normalize($expected);
        $actual = self::normalize($actual);
        if ($expected !== $actual) {
            throw new \InvalidArgumentException('business/store/product-program scope mismatch');
        }
    }

    private static function program(mixed $value): string
    {
        $program = strtoupper(trim((string) $value));
        if (!in_array($program, [self::PERSONALIZED_POD, self::ORIGINAL_DESIGN_POD], true)) {
            throw new \InvalidArgumentException('unsupported product_program');
        }
        return $program;
    }

    private static function requiredToken(mixed $value, string $field): string
    {
        $token = sanitize_key((string) $value);
        if ($token === '') {
            throw new \InvalidArgumentException($field . ' is required');
        }
        return $token;
    }
}
