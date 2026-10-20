<?php

declare(strict_types=1);

namespace DigiForge\Listings;

use DigiForge\POD\EtsyPersonalizationContract;
use DigiForge\POD\ProductionTemplateContract;

/**
 * Offline composition of one design into separate, unapproved Etsy draft plans.
 * References are declarations, not resolved evidence or dispatch authorization.
 */
final class EtsyMultiProductDraftContract
{
    /** @return array<string,mixed> */
    public static function build(array $design, array $products): array
    {
        self::keys($design, ['shop_key', 'family_id', 'version', 'spec_sha256', 'etsy_shop_id', 'environment']);
        if ($design['shop_key'] !== 'personalized_pod') {
            throw new \InvalidArgumentException('Only the personalized POD draft scope is supported');
        }
        if (!in_array($design['environment'], ['test', 'sandbox', 'production'], true)) {
            throw new \InvalidArgumentException('Explicit environment identity is required');
        }
        self::token($design['family_id']);
        self::positive($design['version']);
        if ($design['family_id'] !== 'FC01' || $design['version'] !== 2) {
            throw new \InvalidArgumentException('Only the FC01 v2 question contract is supported');
        }
        self::positive($design['etsy_shop_id']);
        self::sha($design['spec_sha256']);
        if (!array_is_list($products) || count($products) < 1 || count($products) > 50) {
            throw new \InvalidArgumentException('One to fifty separate products are required');
        }
        $seen = [];
        $listings = [];
        foreach ($products as $product) {
            if (!is_array($product)) {
                throw new \InvalidArgumentException('Each product must be structured');
            }
            self::keys($product, ['shop_key', 'product_id', 'product_version_id', 'product_version_sha256', 'sku', 'template', 'variant_id', 'placement', 'download_template', 'mockup', 'mockup_rights', 'personalization', 'draft']);
            if ($product['shop_key'] !== $design['shop_key']) {
                throw new \InvalidArgumentException('Foreign product scope');
            }
            foreach (['product_id', 'product_version_id', 'variant_id'] as $key) {
                self::positive($product[$key]);
            }
            self::sha($product['product_version_sha256']);
            self::token($product['sku']);
            self::token($product['placement']);
            foreach (['product_id', 'product_version_id', 'sku'] as $key) {
                $identity = $key . ':' . $product[$key];
                if (isset($seen[$identity])) {
                    throw new \InvalidArgumentException('Duplicate product, version or SKU');
                }
                $seen[$identity] = true;
            }
            if (!is_array($product['template'])) {
                throw new \InvalidArgumentException('A versioned production template is required');
            }
            $template = ProductionTemplateContract::normalize($product['template']);
            if ($template['supplier'] !== 'printify' || !in_array($product['variant_id'], $template['variant_ids'], true)) {
                throw new \InvalidArgumentException('Exact supplier variant must match template');
            }
            $areas = array_values(array_filter($template['print_areas'], static fn(array $area): bool => $area['position'] === $product['placement']));
            if (count($areas) !== 1) {
                throw new \InvalidArgumentException('An unambiguous template placement is required');
            }
            foreach (['download_template', 'mockup', 'mockup_rights'] as $key) {
                self::reference($product[$key], $key === 'download_template');
            }
            $download = $product['download_template'];
            if ($download['template_sha256'] !== $template['fingerprint']
                || $download['variant_id'] !== $product['variant_id']
                || $download['placement'] !== $product['placement']) {
                throw new \InvalidArgumentException('Download metadata must bind the exact template, variant and placement');
            }
            $questions = self::questions($product['personalization']);
            if (!is_array($product['draft'])) {
                throw new \InvalidArgumentException('Explicit Etsy draft fields are required');
            }
            self::keys($product['draft'], ['quantity', 'title', 'description', 'price', 'who_made', 'when_made', 'taxonomy_id', 'shipping_profile_id', 'readiness_state_id']);
            self::positive($product['draft']['quantity']);
            self::positive($product['draft']['taxonomy_id']);
            self::positive($product['draft']['shipping_profile_id']);
            self::positive($product['draft']['readiness_state_id']);
            foreach (['title', 'description', 'who_made', 'when_made'] as $key) {
                if (!is_string($product['draft'][$key]) || trim($product['draft'][$key]) === '' || strlen($product['draft'][$key]) > 10000 || preg_match('//u', $product['draft'][$key]) !== 1) {
                    throw new \InvalidArgumentException('Explicit bounded draft text is required');
                }
            }
            if ((!is_int($product['draft']['price']) && !is_float($product['draft']['price'])) || !is_finite((float) $product['draft']['price']) || $product['draft']['price'] <= 0) {
                throw new \InvalidArgumentException('An explicit positive draft price is required');
            }
            $operation = EtsyDraftListingOperations::create($design['etsy_shop_id'], $product['draft']);
            if (!is_array($operation)) {
                throw new \InvalidArgumentException('Draft operation could not be planned');
            }
            $material = $product;
            $material['template'] = $template;
            $material['design'] = $design;
            $material['draft_operation'] = $operation;
            // Etsy requires a listing ID before its dedicated personalization POST.
            // This dependent plan is never a ready-to-dispatch provider operation.
            $material['personalization_plan'] = [
                'state' => 'WAITING_FOR_DRAFT_LISTING_ID',
                'operation' => 'UPDATE_PERSONALIZATION',
                'method' => 'POST',
                'endpoint_template' => '/application/shops/' . $design['etsy_shop_id'] . '/listings/{listing_id}/personalization',
                'payload' => ['personalization_questions' => $questions['personalization_questions']],
                'network_request_permitted' => false,
                'external_execution_performed' => false,
            ];
            $material['field_behavior'] = [
                'length_unit' => 'code_points', 'normalization' => 'NONE', 'automatic_case_change' => false,
                'overflow' => 'REJECT', 'established_prefix' => 'ESTABLISHED ', 'blank_established' => 'OMIT_ENTIRE_LINE',
            ];
            $material['draft_key'] = hash('sha256', self::json($material));
            $material['state'] = 'REVIEW_REQUIRED';
            $material['supplier_certification'] = 'UNKNOWN';
            $material['ownership'] = 'UNKNOWN';
            $material['rights'] = 'UNKNOWN';
            $material['native_compatibility'] = 'UNKNOWN';
            $listings[] = $material;
        }
        usort($listings, static fn(array $a, array $b): int => $a['product_version_id'] <=> $b['product_version_id']);
        return [
            'schema' => 'etsy-multi-product-draft-v1', 'design' => $design, 'listings' => $listings,
            'manifest_sha256' => hash('sha256', self::json([$design, $listings])),
            // Separate plans do not establish independently editable provider listings.
            // Detect byte-identical declared mockups, but never infer independence
            // from different assets, variants, prices or missing provider records.
            'provider_listing_dependencies' => self::dependencies($listings),
            'state' => 'REVIEW_REQUIRED', 'draft_packages_created' => 0, 'external_execution_performed' => false,
            'network_request_permitted' => false, 'publish_permitted' => false, 'acceptance' => 'UNVERIFIED',
        ];
    }

    private static function dependencies(array $listings): array
    {
        $shared = [];
        foreach ($listings as $listing) {
            foreach ($listings as $other) {
                if ($listing['product_version_id'] !== $other['product_version_id']
                    && ($listing['mockup']['reference'] === $other['mockup']['reference']
                        || $listing['mockup']['sha256'] === $other['mockup']['sha256'])) {
                    $shared[] = $listing['product_version_id'];
                    break;
                }
            }
        }
        return [
            'state' => 'UNKNOWN',
            'independent_edits_permitted' => false,
            'shared_mockup_product_versions' => $shared,
            'variant_dependencies' => 'UNKNOWN',
            'pricing_dependencies' => 'UNKNOWN',
        ];
    }

    private static function questions(mixed $mappings): array
    {
        if (!is_array($mappings) || !array_is_list($mappings) || count($mappings) !== 2) {
            throw new \InvalidArgumentException('Exact family and optional established field mappings are required');
        }
        $questions = [];
        foreach (['family_name' => true, 'established' => false] as $field => $required) {
            $mapping = $mappings[count($questions)];
            if (!is_array($mapping)) {
                throw new \InvalidArgumentException('Field mapping must be structured');
            }
            self::keys($mapping, ['field', 'question']);
            $q = $mapping['question'];
            if ($mapping['field'] !== $field || !is_array($q)) {
                throw new \InvalidArgumentException('Explicit ordered field mapping is required');
            }
            self::keys($q, ['question_type', 'question_text', 'required', 'max_allowed_characters']);
            if ($q['question_type'] !== 'text_input' || $q['required'] !== $required || $q['max_allowed_characters'] !== 48) {
                throw new \InvalidArgumentException('FC01 field behavior cannot be weakened');
            }
            $questions[] = $q;
        }
        return EtsyPersonalizationContract::normalize($questions);
    }

    private static function reference(mixed $value, bool $download): void
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException('Pinned local evidence reference required');
        }
        $keys = ['shop_key', 'reference', 'sha256'];
        if ($download) {
            $keys = array_merge($keys, ['template_sha256', 'variant_id', 'placement']);
        }
        self::keys($value, $keys);
        if ($value['shop_key'] !== 'personalized_pod') {
            throw new \InvalidArgumentException('Foreign evidence scope');
        }
        if ($download) {
            self::sha($value['template_sha256']);
            self::positive($value['variant_id']);
            self::token($value['placement']);
        }
        self::sha($value['sha256']);
        if (!is_string($value['reference']) || strlen($value['reference']) > 512 || preg_match('~^digiforge/product-factory/[A-Za-z0-9_/-]+\.(?:json|svg|png|pdf|txt)$~D', $value['reference']) !== 1 || str_contains($value['reference'], '//') || !str_contains($value['reference'], '/shop-personalized_pod/')) {
            throw new \InvalidArgumentException('Unsafe local evidence reference');
        }
    }

    private static function positive(mixed $value): void
    {
        if (!is_int($value) || $value < 1) {
            throw new \InvalidArgumentException('Positive integer identity required');
        }
    }

    private static function token(mixed $value): void
    {
        if (!is_string($value) || preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $value) !== 1) {
            throw new \InvalidArgumentException('Invalid bounded identifier');
        }
    }

    private static function sha(mixed $value): void
    {
        if (!is_string($value) || preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new \InvalidArgumentException('Exact SHA-256 required');
        }
    }

    private static function keys(array $value, array $keys): void
    {
        if (count($value) !== count($keys) || array_diff(array_keys($value), $keys)) {
            throw new \InvalidArgumentException('Unexpected or missing contract fields');
        }
    }

    private static function json(array $value): string
    {
        // Stable keys without the trimming performed by Listings Validator.
        $sort = static function (array $v) use (&$sort): array {
            if (!array_is_list($v)) {
                ksort($v, SORT_STRING);
            }
            foreach ($v as &$item) {
                if (is_array($item)) {
                    $item = $sort($item);
                }
            }
            return $v;
        };
        return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
