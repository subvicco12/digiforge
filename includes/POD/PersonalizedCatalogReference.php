<?php

declare(strict_types=1);

namespace DigiForge\POD;

final class PersonalizedCatalogReference
{
    public const CATALOG_KEY = 'digicraftifygoods-master-500-v1';
    public const SOURCE_FILE = 'DigiForge_DigiCraftifyGoods_Product_Template_Master_500.xlsx';
    public const SOURCE_SHA256 = 'e874e5bc92652202e942135ffd4a40d6d0ee7dff0c0b1032637635b6980b0c9c';
    public const LISTING_COUNT = 500;
    public const PERSONALIZATION_ENGINE_COUNT = 16;
    public const TEMPLATE_RULE_COUNT = 10;
    public const PRODUCT_PROGRAM = BusinessScope::PERSONALIZED_POD;

    /** @return array<string,mixed> */
    public static function metadata(): array
    {
        return [
            'catalog_key' => self::CATALOG_KEY,
            'business_key' => BusinessScope::DIGICRAFTIFY_GOODS,
            'product_program' => self::PRODUCT_PROGRAM,
            'source_file' => self::SOURCE_FILE,
            'source_sha256' => self::SOURCE_SHA256,
            'listing_count' => self::LISTING_COUNT,
            'personalization_engine_count' => self::PERSONALIZATION_ENGINE_COUNT,
            'template_rule_count' => self::TEMPLATE_RULE_COUNT,
            'source_state' => 'IMMUTABLE_REFERENCE',
            'production_authority' => false,
        ];
    }
}
