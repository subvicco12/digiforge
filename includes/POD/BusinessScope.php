<?php

declare(strict_types=1);

namespace DigiForge\POD;

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
        $program = strtoupper(trim((string) ($input['product_program'] ?? '')));

        if (!in_array($program, [self::PERSONALIZED_POD, self::ORIGINAL_DESIGN_POD], true)) {
            throw new \InvalidArgumentException('unsupported product_program');
        }
        if ($businessId === self::DIGICRAFTIFY_GOODS && $program !== self::PERSONALIZED_POD) {
            throw new \InvalidArgumentException('DigiCraftifyGoods is restricted to PERSONALIZED_POD');
        }

        return [
            'business_id' => $businessId,
            'store_id' => $storeId,
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

    private static function requiredToken(mixed $value, string $field): string
    {
        $token = sanitize_key((string) $value);
        if ($token === '') {
            throw new \InvalidArgumentException($field . ' is required');
        }
        return $token;
    }
}
