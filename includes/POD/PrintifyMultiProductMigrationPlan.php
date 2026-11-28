<?php

declare(strict_types=1);

namespace DigiForge\POD;

/** Pure six-product/three-group review planning. Declared observations never resolve provider authority. */
final class PrintifyMultiProductMigrationPlan
{
    public const SCHEMA = 'printify-multi-product-migration-review-v1';
    public const SHARED_OPTION_LIMIT = 400;

    /** @param array<array-key,mixed> $source
     * @param array<array-key,mixed> $groups
     * @return array<string,mixed>
     */
    public static function build(array $source, array $groups): array
    {
        self::source($source);
        if (!array_is_list($groups) || count($groups) !== 3) {
            self::invalid('Exactly three explicitly selected groups are required');
        }
        $byId = array_column($source['products'], null, 'product_id');
        $seen = $groupKeys = [];
        foreach ($groups as &$group) {
            if (!is_array($group)) {
                self::invalid('Structured group required');
            }
            self::keys($group, ['group_key','product_ids','primary_product_id','counting_rule','evidence_conflicts']);
            self::token($group['group_key']);
            if (isset($groupKeys[$group['group_key']])) {
                self::invalid('Duplicate group identity');
            }
            $groupKeys[$group['group_key']] = true;
            if (
                !is_array($group['product_ids'])
                || !array_is_list($group['product_ids'])
                || $group['product_ids'] === []
                || count($group['product_ids']) > 6
            ) {
                self::invalid('Nonempty bounded product partition required');
            }
            foreach ($group['product_ids'] as $id) {
                self::productId($id);
                if (!isset($byId[$id]) || isset($seen[$id])) {
                    self::invalid('Every source product must occur exactly once');
                }
                $seen[$id] = true;
            }
            sort($group['product_ids'], SORT_STRING);
            if (
                $group['primary_product_id'] !== null
                && !in_array(
                    $group['primary_product_id'],
                    $group['product_ids'],
                    true
                )
            ) {
                self::invalid('Group primary must belong to the group');
            }
            self::strings($group['evidence_conflicts'], 50, false);
            if ($group['counting_rule'] !== null) {
                if (!is_array($group['counting_rule'])) {
                    self::invalid('Explicit counting rule required');
                }
                self::keys($group['counting_rule'], ['rule','review_state','source_reference']);
                if (
                    $group['counting_rule']['rule'] !== 'SUM_PRODUCT_SIZE_SLOTS_X_EXACT_SHARED_VALUE_UNION'
                    || $group['counting_rule']['review_state'] !== 'REVIEWED_FOR_OFFLINE_COUNTING'
                ) {
                    self::invalid('Unsupported or unreviewed counting rule');
                }
                self::reference($group['counting_rule']['source_reference']);
            }
        }
        unset($group);
        if (count($seen) !== count($byId)) {
            self::invalid('The partition omits source products');
        }
        usort($groups, static fn(array $a, array $b): int => strcmp($a['group_key'], $b['group_key']));
        $planned = [];
        foreach ($groups as $group) {
            $products = [];
            foreach ($group['product_ids'] as $id) {
                $products[] = $byId[$id];
            }
            $count = self::countOptions($products, $group);
            $outOfStock = [];
            foreach ($products as $product) {
                foreach ($product['stock'] ?? [] as $stock) {
                    if ($stock['state'] === 'OUT_OF_STOCK') {
                        $outOfStock[] = ['product_id' => $product['product_id']] + $stock;
                    }
                }
            }
            $blockers = ['PROVIDER_IDENTITY_VARIANTS_AND_GEOMETRY_UNVERIFIED',
                'RIGHTS_AND_PERSONALIZATION_UNVERIFIED',
                'SHARED_PRICING_SHIPPING_AND_MOCKUP_DEPENDENCIES_UNVERIFIED',
                'LOCAL_PRODUCT_VERSION_BINDING_UNRESOLVED'];
            if ($group['primary_product_id'] === null) {
                $blockers[] = 'TARGET_PRIMARY_AND_CATEGORY_REVIEW_REQUIRED';
            }
            if ($count['count'] === null) {
                $blockers[] = 'SELECTED_OPTIONS_OR_COUNTING_RULE_UNKNOWN';
            }
            if ($count['limit_state'] === 'EXCEEDS_400') {
                $blockers[] = 'SHARED_OPTION_LIMIT_EXCEEDED';
            }
            if ($outOfStock !== []) {
                $blockers[] = 'STOCK_AND_MANUAL_REPUBLISH_REVIEW_REQUIRED';
            }
            $planned[] = $group + [
                'state' => 'BLOCKED_REVIEW_PLAN', 'option_count' => $count,
                'source_primary_in_group' => in_array(
                    $source['source_primary_product_id'],
                    $group['product_ids'],
                    true
                ),
                'out_of_stock_observations' => $outOfStock, 'stock_availability' => 'UNVERIFIED',
                'automatically_exclude_out_of_stock' => false, 'automatic_trimming_or_splitting' => false,
                'provider_listing_dependencies' => ['state' => 'UNKNOWN',
                    'variant_dependencies' => 'UNKNOWN',
                    'pricing_dependencies' => 'UNKNOWN',
                    'shipping_dependencies' => 'UNKNOWN',
                    'mockup_dependencies' => 'UNKNOWN',
                    'independent_edits_permitted' => false],
                'etsy_listing_id' => null, 'target_printify_listing_id' => null,
                'inventory_plan_permitted' => false, 'shared_price_or_shipping_mutation_permitted' => false,
                'network_request_permitted' => false, 'publish_permitted' => false, 'blockers' => $blockers,
            ];
        }
        $snapshotHash = self::hash($source);
        $rollback = [
            'schema' => 'printify-multi-product-rollback-review-v1', 'source_snapshot_sha256' => $snapshotHash,
            'original_source_snapshot' => $source, 'source_listing_id' => $source['source_listing_id'],
            'source_revision' => $source['source_revision'],
                'revision_state' => $source['source_revision'] === null
                ? 'UNKNOWN' : 'DECLARED_NOT_PROVIDER_VERIFIED',
            'source_primary_product_id' => $source['source_primary_product_id'],
                'source_product_ids' => array_column(
                    $source['products'],
                    'product_id'
                ),
            'dependency_snapshots' => $source['dependency_snapshots'], 'state' => 'REVIEW_REQUIRED',
            'restore_permitted' => false, 'network_request_permitted' => false, 'external_execution_performed' => false,
        ];
        $rollback['rollback_sha256'] = self::hash($rollback);
        $plan = [
            'schema' => self::SCHEMA, 'source_snapshot' => $source, 'source_snapshot_sha256' => $snapshotHash,
            'requested_groups' => $groups, 'groups' => $planned, 'rollback' => $rollback,
            'state' => 'BLOCKED_REVIEW_PLAN', 'acceptance' => 'UNVERIFIED', 'local_product_binding' => 'UNBOUND',
            'source_current_revision_state' => $source['source_revision'] === null
                ? 'UNKNOWN' : 'DECLARED_NOT_PROVIDER_VERIFIED',
            'source_modified' => false, 'drafts_created' => 0, 'independent_edits_permitted' => false,
            'inventory_plan_permitted' => false, 'network_request_permitted' => false,
            'publish_permitted' => false, 'external_execution_performed' => false,
            'blockers' => ['SOURCE_AND_LINKAGE_CURRENT_SNAPSHOT_REVIEW_REQUIRED',
                'EXTERNAL_SHOP_AND_LOCAL_VERSION_BINDINGS_UNRESOLVED',
                'OWNER_MIGRATION_AND_ROLLBACK_APPROVAL_REQUIRED',
                'PROVIDER_RIGHTS_TEMPLATE_AND_ETSY_AUTHORIZATION_UNVERIFIED'],
        ];
        $plan['plan_sha256'] = self::hash($plan);
        return $plan;
    }

    /** Compare declarations only; neither matching hashes nor a known revision certify live provider state.
     * @param array<array-key,mixed> $plan
     * @param array<array-key,mixed> $currentSource
     * @return array<string,mixed>
     */
    public static function assertCurrent(array $plan, array $currentSource): array
    {
        if (!is_array($plan['source_snapshot'] ?? null) || !is_array($plan['requested_groups'] ?? null)) {
            self::invalid('Complete immutable plan required');
        }
        $rebuilt = self::build($plan['source_snapshot'], $plan['requested_groups']);
        if (!self::same($plan, $rebuilt)) {
            self::invalid('Plan or rollback material changed');
        }
        self::source($currentSource);
        $matches = hash_equals($plan['source_snapshot_sha256'], self::hash($currentSource));
        $state = !$matches
            ? 'STALE_SOURCE' : ($currentSource['source_revision'] === null
            ? 'UNKNOWN' : 'MATCHING_DECLARED_SNAPSHOT');
        return ['state' => $state,
            'snapshot_matches' => $matches,
            'current_source_verified' => false,
            'network_request_permitted' => false,
            'publish_permitted' => false];
    }

    /** @param list<array<array-key,mixed>> $products
     * @param array<string,mixed> $group
     * @return array<string,mixed>
     */
    private static function countOptions(array $products, array $group): array
    {
        $unknown = ['state' => 'UNKNOWN',
            'count' => null,
            'selected_product_size_slots' => null,
            'shared_exact_values' => null,
            'unsupported_shared_pairs' => null,
            'limit' => self::SHARED_OPTION_LIMIT,
            'limit_state' => 'UNKNOWN',
            'provider_verified' => false];
        if ($group['counting_rule'] === null || $group['evidence_conflicts'] !== []) {
            return $unknown;
        }
        $slots = 0;
        $union = [];
        foreach ($products as $product) {
            if (
                !$product['selections_complete']
                || $product['selected_size_slots'] === null
                || $product['selected_shared_values'] === null
                || $product['options_source_reference'] === null
            ) {
                return $unknown;
            }
            $slots += count($product['selected_size_slots']);
            foreach ($product['selected_shared_values'] as $value) {
                if (!in_array($value, $union, true)) {
                    $union[] = $value;
                }
            }
        }
        sort($union, SORT_STRING);
        $count = $slots * count($union);
        $unsupported = 0;
        foreach ($products as $product) {
            $unsupported += count($product['selected_size_slots'])
                * (count($union) - count($product['selected_shared_values']));
        }
        return ['state' => 'LOCAL_CALCULATED',
            'count' => $count,
            'selected_product_size_slots' => $slots,
            'shared_exact_values' => $union,
            'unsupported_shared_pairs' => $unsupported,
            'limit' => self::SHARED_OPTION_LIMIT,
            'limit_state' => $count > self::SHARED_OPTION_LIMIT
            ? 'EXCEEDS_400' : 'LOCALLY_WITHIN_400_NOT_PROVIDER_ACCEPTANCE',
            'provider_verified' => false];
    }

    /** @param array<array-key,mixed> $source */
    private static function source(array $source): void
    {
        self::keys(
            $source,
            ['shop_key',
            'environment',
            'printify_store_id',
            'source_listing_id',
            'source_primary_product_id',
            'source_revision',
            'source_state',
            'provenance',
            'products',
            'dependency_snapshots']
        );
        if (
            $source['shop_key'] !== 'personalized_pod'
            || !in_array(
                $source['environment'],
                ['test',
                'sandbox',
                'production'],
                true
            )
        ) {
            self::invalid('Explicit personalized POD environment required');
        }
        if (
            !is_string($source['printify_store_id'])
            || preg_match(
                '/^[1-9][0-9]{0,18}$/D',
                $source['printify_store_id']
            ) !== 1
        ) {
            self::invalid('External store ID must remain an exact decimal string');
        }
        if (
            !is_string($source['source_listing_id'])
            || preg_match(
                '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',
                $source['source_listing_id']
            ) !== 1
        ) {
            self::invalid('Exact source listing UUID required');
        }
        self::productId($source['source_primary_product_id']);
        if ($source['source_revision'] !== null) {
            self::text($source['source_revision'], 200);
        }
        self::token($source['source_state']);
        if (!is_array($source['provenance'])) {
            self::invalid('Source provenance required');
        }
        self::keys($source['provenance'], ['kind','source_reference','observed_at','evidence_sha256']);
        if (
            !in_array(
                $source['provenance']['kind'],
                ['SYNTHETIC_TEST_ONLY',
                'ATTRIBUTED_AUTHENTICATED_UI_REPORT',
                'AUTHENTICATED_UI_OBSERVATION',
                'AUTHENTICATED_READ_ONLY_API_OBSERVATION'],
                true
            )
        ) {
            self::invalid('Explicit observation kind required');
        }
        self::reference($source['provenance']['source_reference']);
        $observed = $source['provenance']['observed_at'];
        if ($observed !== null) {
            $date = is_string($observed)
                ? \DateTimeImmutable::createFromFormat(
                    '!Y-m-d\TH:i:s\Z',
                    $observed,
                    new \DateTimeZone('UTC')
                ) : false;
            if ($date === false || $date->format('Y-m-d\TH:i:s\Z') !== $observed) {
                self::invalid('Exact UTC observation timestamp or explicit unknown required');
            }
        }
        if ($source['provenance']['evidence_sha256'] !== null) {
            self::sha($source['provenance']['evidence_sha256']);
        }
        if (!is_array($source['products']) || !array_is_list($source['products']) || count($source['products']) !== 6) {
            self::invalid('Complete six-product source snapshot required');
        }
        $seen = [];
        foreach ($source['products'] as $product) {
            if (!is_array($product)) {
                self::invalid('Structured source product required');
            }
            self::product($product);
            if (isset($seen[$product['product_id']])) {
                self::invalid('Duplicate source product identity');
            }
            $seen[$product['product_id']] = true;
        }
        if (!isset($seen[$source['source_primary_product_id']])) {
            self::invalid('Source primary is absent from source membership');
        }
        if (!is_array($source['dependency_snapshots'])) {
            self::invalid('Explicit dependency snapshot states required');
        }
        self::keys($source['dependency_snapshots'], ['linkage','pricing','shipping','mockups','personalization']);
        foreach ($source['dependency_snapshots'] as $snapshot) {
            if ($snapshot === null) {
                continue;
            }
            if (!is_array($snapshot)) {
                self::invalid('Pinned snapshot reference required');
            }
            self::keys($snapshot, ['source_reference','sha256','revision']);
            self::reference($snapshot['source_reference']);
            self::sha($snapshot['sha256']);
            if ($snapshot['revision'] !== null) {
                self::text($snapshot['revision'], 200);
            }
        }
        if (strlen(self::json($source)) > 262144) {
            self::invalid('Source snapshot exceeds resource bounds');
        }
    }

    /** @param array<array-key,mixed> $product */
    private static function product(array $product): void
    {
        self::keys(
            $product,
            ['product_id',
            'label',
            'routing',
            'provider_id',
            'blueprint_id',
            'selected_variant_ids',
            'sku_mapping',
            'selected_size_slots',
            'selected_shared_values',
            'selections_complete',
            'options_source_reference',
            'stock',
            'local_product_version_id',
            'local_product_version_sha256']
        );
        self::productId($product['product_id']);
        self::text($product['label'], 200);
        if (!in_array($product['routing'], ['DYNAMIC_CHOICE','FIXED_PROVIDER','UNKNOWN'], true)) {
            self::invalid('Explicit routing observation required');
        }
        foreach (['provider_id','blueprint_id','local_product_version_id'] as $field) {
            if ($product[$field] !== null) {
                self::positive($product[$field]);
            }
        }
        if ($product['routing'] === 'DYNAMIC_CHOICE' && $product['provider_id'] !== null) {
            self::invalid('Printify Choice cannot assert a fixed provider');
        }
        if ($product['local_product_version_sha256'] !== null) {
            self::sha($product['local_product_version_sha256']);
        }
        if (($product['local_product_version_id'] === null) !== ($product['local_product_version_sha256'] === null)) {
            self::invalid('Declared local version requires its exact hash; it remains unresolved');
        }
        $variants = $product['selected_variant_ids'];
        if ($variants !== null) {
            if (!is_array($variants) || !array_is_list($variants) || $variants === [] || count($variants) > 400) {
                self::invalid('Bounded selected variant list required');
            }
            foreach ($variants as $variant) {
                self::positive($variant);
            }
            if (count(array_unique($variants)) !== count($variants)) {
                self::invalid('Duplicate selected variant');
            }
        }
        if ($product['sku_mapping'] !== null) {
            if (
                !is_array($product['sku_mapping'])
                || !array_is_list($product['sku_mapping'])
                || count($product['sku_mapping']) > 400
            ) {
                self::invalid('Bounded explicit SKU map required');
            }
            $seen = [];
            foreach ($product['sku_mapping'] as $mapping) {
                if (!is_array($mapping)) {
                    self::invalid('Structured SKU mapping required');
                }
                self::keys($mapping, ['variant_id','sku']);
                self::positive($mapping['variant_id']);
                self::text($mapping['sku'], 200);
                if (
                    $variants === null
                    || !in_array(
                        $mapping['variant_id'],
                        $variants,
                        true
                    )
                    || isset($seen[$mapping['variant_id']])
                ) {
                    self::invalid('SKU mapping must bind a unique selected variant');
                }
                $seen[$mapping['variant_id']] = true;
            }
        }
        foreach (['selected_size_slots','selected_shared_values'] as $field) {
            if ($product[$field] !== null) {
                self::strings($product[$field], 400, true);
            }
        }
        if (!is_bool($product['selections_complete'])) {
            self::invalid('Explicit selected-option completeness required');
        }
        if ($product['options_source_reference'] !== null) {
            self::reference($product['options_source_reference']);
        }
        if ($product['stock'] !== null) {
            if (!is_array($product['stock']) || !array_is_list($product['stock']) || count($product['stock']) > 400) {
                self::invalid('Bounded stock observations required');
            }
            foreach ($product['stock'] as $stock) {
                if (!is_array($stock)) {
                    self::invalid('Structured stock observation required');
                }
                self::keys($stock, ['variant_id','size_slot','shared_value','state','source_reference']);
                if ($stock['variant_id'] !== null) {
                    self::positive($stock['variant_id']);
                }
                foreach (['size_slot','shared_value'] as $field) {
                    if ($stock[$field] !== null) {
                        self::text($stock[$field], 200);
                    }
                }
                if (!in_array($stock['state'], ['IN_STOCK','OUT_OF_STOCK','UNKNOWN'], true)) {
                    self::invalid('Explicit stock state required');
                }
                self::reference($stock['source_reference']);
            }
        }
    }

    /** @param array<array-key,mixed> $value
     * @param list<string> $keys
     */
    private static function keys(array $value, array $keys): void
    {
        if (count($value) !== count($keys) || array_diff(array_keys($value), $keys) !== []) {
            self::invalid('Unexpected or missing contract fields');
        }
    }
    private static function strings(mixed $values, int $limit, bool $unique): void
    {
        if (!is_array($values) || !array_is_list($values) || count($values) > $limit || ($unique && $values === [])) {
            self::invalid('Bounded explicit option/conflict list required');
        }
        foreach ($values as $value) {
            self::text($value, 200);
        }
        if ($unique && count(array_unique($values, SORT_STRING)) !== count($values)) {
            self::invalid('Duplicate exact option label');
        }
    }
    private static function text(mixed $value, int $maximum): void
    {
        if (
            !is_string($value)
            || trim($value) === ''
            || strlen($value) > $maximum
            || preg_match(
                '//u',
                $value
            ) !== 1
            || preg_match(
                '/[\x00-\x1f\x7f]/',
                $value
            )
        ) {
            self::invalid('Bounded exact text required');
        }
    }
    private static function reference(mixed $value): void
    {
        self::text($value, 1000);
    }
    private static function token(mixed $value): void
    {
        if (!is_string($value) || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,99}$/D', $value) !== 1) {
            self::invalid('Bounded token required');
        }
    }
    private static function positive(mixed $value): void
    {
        if (!is_int($value) || $value < 1) {
            self::invalid('Positive typed integer required');
        }
    }
    private static function productId(mixed $value): void
    {
        if (!is_string($value) || preg_match('/^[a-f0-9]{24}$/D', $value) !== 1) {
            self::invalid('External product ID must remain an exact hexadecimal string');
        }
    }
    private static function sha(mixed $value): void
    {
        if (!is_string($value) || preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            self::invalid('Exact SHA-256 required');
        }
    }
    /** Compare against bounded rebuilt material without serializing arbitrary caller projections. */
    private static function same(mixed $actual, mixed $expected): bool
    {
        if (!is_array($expected)) {
            return $actual === $expected;
        }
        if (
            !is_array($actual)
            || count($actual) !== count($expected)
            || array_is_list($actual) !== array_is_list($expected)
        ) {
            return false;
        }
        foreach ($expected as $key => $value) {
            if (!array_key_exists($key, $actual) || !self::same($actual[$key], $value)) {
                return false;
            }
        }
        return true;
    }
    /** @param array<array-key,mixed> $value */
    private static function hash(array $value): string
    {
        return hash('sha256', self::json($value));
    }
    /** @param array<array-key,mixed> $value */
    private static function json(array $value): string
    {
        $sort = static function (array $item) use (&$sort): array {
            if (!array_is_list($item)) {
                ksort($item, SORT_STRING);
            }
            foreach ($item as &$value) {
                if (is_array($value)) {
                    $value = $sort($value);
                }
            }
            return $item;
        };
        return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    private static function invalid(string $reason): never
    {
        throw new \InvalidArgumentException($reason);
    }
}
