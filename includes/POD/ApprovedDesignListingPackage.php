<?php

declare(strict_types=1);

namespace DigiForge\POD;

use DigiForge\Listings\Validator;
use InvalidArgumentException;

/**
 * Offline review compiler, never an authorization or provider request payload.
 * Callers MUST resolve current approved designs from trusted shop-scoped storage
 * after authentication/authorization. A checksum is integrity, not authentication.
 * No caller-provided review flag can authorize transfer, publication or orders.
 */
final class ApprovedDesignListingPackage
{
    /** Authenticated entry: current capabilities, shop and durable approval are re-read. */
    public static function fromApprovedVersion(string $shop, string $versionHash, array $evidence): array|\WP_Error
    {
        $envelope = (new \DigiForge\Research\OpportunityDesignRepository())->read($shop, $versionHash);
        if ($envelope instanceof \WP_Error) {
            return $envelope;
        }
        try {
            return self::compile($shop, $envelope, $evidence);
        } catch (InvalidArgumentException) {
            return new \WP_Error(
                'digiforge_review_package_evidence_required',
                'Exact approved artwork and scoped review evidence are required.',
                ['status' => 409, 'external_execution_authorized' => false]
            );
        }
    }

    public static function compile(string $shop, array $approvedDesign, array $evidence): array
    {
        self::jsonValues($approvedDesign);
        self::jsonValues($evidence);
        Validator::structured($approvedDesign);
        Validator::structured($evidence);
        foreach (['fixture', 'listing', 'native_fields', 'media', 'reviews', 'parity', 'economics'] as $field) {
            if (isset($evidence[$field]) && !is_array($evidence[$field])) {
                throw new InvalidArgumentException('Structured package evidence must contain arrays.');
            }
        }
        $envelope = $approvedDesign;
        $approvedDesign = self::laneADesign($shop, $envelope, $evidence['environment'] ?? null);
        if (
            $shop !== 'personalized_pod' || ($approvedDesign['shop_key'] ?? null) !== $shop
            || ($evidence['shop_key'] ?? null) !== $shop
            || !in_array($approvedDesign['environment'] ?? null, ['test', 'sandbox', 'production'], true)
            || ($approvedDesign['environment'] ?? null) !== ($evidence['environment'] ?? null)
        ) {
            throw new InvalidArgumentException('Exact shop and environment are required.');
        }
        self::verifySeal($approvedDesign);
        if (
            !is_int($approvedDesign['approved_by'] ?? null) || $approvedDesign['approved_by'] < 1
        ) {
            throw new InvalidArgumentException('An owner-approved immutable design version is required.');
        }
        self::timestamp($approvedDesign['approved_at'] ?? null);
        self::text($approvedDesign['design_id'] ?? null, 120);
        $asset = $approvedDesign['asset'] ?? [];
        self::hash($asset['sha256'] ?? null);
        self::reference($asset['editable_reference'] ?? null);
        foreach (['width_px', 'height_px', 'dpi'] as $key) {
            if (!is_int($asset[$key] ?? null) || $asset[$key] < 1) {
                throw new InvalidArgumentException('Explicit artwork dimensions and DPI are required.');
            }
        }
        $sources = $approvedDesign['provenance'] ?? [];
        if (!is_array($sources) || $sources === []) {
            throw new InvalidArgumentException('Design source provenance is required.');
        }
        foreach ($sources as $source) {
            self::url($source['source_url'] ?? null);
            self::timestamp($source['observed_at'] ?? null);
        }

        $fixture = $evidence['fixture'] ?? [];
        $id = $fixture['fixture_id'] ?? null;
        $products = ['FL-02' => '6ae89ec108d860bc98040ca0', 'FL-04' => '6ae9913d9541969415088814'];
        if (!is_string($id) || !isset($products[$id]) || ($fixture['printify_product_id'] ?? null) !== $products[$id]) {
            throw new InvalidArgumentException('An exact FL-02 or FL-04 technical fixture is required.');
        }
        // These historical fixtures are technical anchors, never commercial approval.
        if ($id === 'FL-04' && ($asset['width_px'] !== 2418 || $asset['height_px'] !== 3018)) {
            throw new InvalidArgumentException('FL-04 technical geometry differs from approved artwork.');
        }
        $blockers = ['OWNER_PACKAGE_APPROVAL_REQUIRED', 'SUPPLIER_CERTIFICATION_REQUIRED',
            'BUYER_PREVIEW_ACCEPTANCE_REQUIRED', 'LIVE_HANDOFF_SEPARATE_AUTHORITY_REQUIRED'];
        $template = $evidence['template'] ?? null;
        $provider = ['supplier' => 'printify', 'product_id' => $products[$id], 'template' => null,
            'blueprint_id' => null, 'provider_id' => null, 'variant_ids' => [], 'sku' => null,
            'identity_state' => 'UNKNOWN', 'source_binding' => null];
        if ($template === null) {
            $blockers[] = 'EXACT_PROVIDER_TEMPLATE_REQUIRED';
        } else {
            if (!is_array($template)) {
                throw new InvalidArgumentException('Invalid provider template.');
            }
            $normalized = ProductionTemplateContract::normalize($template);
            if (
                $normalized['supplier'] !== 'printify' || count($normalized['print_areas']) !== 1
                || $normalized['personalization_pipeline'] !== 'PRINTIFY_NATIVE'
            ) {
                throw new InvalidArgumentException('An exact single-area Printify native template is required.');
            }
            if (isset($template['fingerprint']) && $template['fingerprint'] !== $normalized['fingerprint']) {
                throw new InvalidArgumentException('Provider template fingerprint mismatch.');
            }
            $area = $normalized['print_areas'][0];
            if ($asset['width_px'] !== $area['width_px'] || $asset['height_px'] !== $area['height_px']) {
                throw new InvalidArgumentException('Approved artwork and provider geometry differ.');
            }
            $provider = array_merge($provider, ['template' => $normalized,
                'blueprint_id' => $normalized['provider_blueprint_id'], 'provider_id' => $normalized['provider_id'],
                'variant_ids' => $normalized['variant_ids'], 'identity_state' => 'UNVERIFIED_CANDIDATE']);
            $binding = $evidence['provider_binding'] ?? null;
            if ($binding === null) {
                $blockers[] = 'PRODUCT_TEMPLATE_BINDING_REQUIRED';
                $blockers[] = 'EXACT_PROVIDER_TEMPLATE_REQUIRED';
            } else {
                if (
                    !is_array($binding) || ($binding['shop_key'] ?? null) !== $shop
                    || ($binding['environment'] ?? null) !== $evidence['environment']
                    || ($binding['product_id'] ?? null) !== $products[$id]
                    || ($binding['template_fingerprint'] ?? null) !== $normalized['fingerprint']
                ) {
                    throw new InvalidArgumentException('Exact scoped product/template source binding is required.');
                }
                self::reference($binding['source_reference'] ?? null);
                self::hash($binding['source_sha256'] ?? null);
                self::timestamp($binding['observed_at'] ?? null);
                self::text($binding['sku'] ?? null, 120);
                $provider['source_binding'] = $binding;
                $provider['sku'] = $binding['sku'];
                $provider['identity_state'] = 'SOURCE_LINKED_REQUIRES_SUPPLIER_REVIEW';
            }
            if ($normalized['template_status'] !== 'VALIDATED') {
                $blockers[] = 'VALIDATED_TEMPLATE_REQUIRED';
            }
        }

        $listing = $evidence['listing'] ?? [];
        self::text($listing['title'] ?? null, 140);
        self::text($listing['description'] ?? null, 20000);
        $tags = $listing['tags'] ?? [];
        if (!is_array($tags) || !array_is_list($tags) || count($tags) !== 13) {
            throw new InvalidArgumentException('Exactly 13 distinct Etsy tags are required.');
        }
        $seen = [];
        foreach ($tags as $tag) {
            self::text($tag, 20);
            $key = mb_strtolower($tag, 'UTF-8');
            if (isset($seen[$key])) {
                throw new InvalidArgumentException('Duplicate Etsy tag.');
            }
            $seen[$key] = true;
        }
        if (empty($listing['category']) || empty($listing['attributes'])) {
            $blockers[] = 'CATEGORY_ATTRIBUTES_REQUIRED';
        }
        $native = $evidence['native_fields'] ?? [];
        $questions = null;
        if ($native === []) {
            $blockers[] = 'NATIVE_FIELDS_REQUIRED';
        } else {
            $questions = EtsyPersonalizationContract::normalize($native);
            // Etsy inputs do not prove native Printify placement or overflow handling.
        }
        $reviews = $evidence['reviews'] ?? [];
        foreach (
            ['safe_area', 'bleed', 'font_license', 'mockup_rights', 'native_mapping',
            'overflow', 'choice_routing', 'shipping', 'processing', 'manual_price'] as $review
        ) {
            if (($reviews[$review] ?? null) !== true) {
                $blockers[] = strtoupper($review) . '_REVIEW_REQUIRED';
            }
        }
        if ($asset['dpi'] < 300) {
            $blockers[] = 'ARTWORK_300_DPI_REQUIRED';
        }
        $parity = $evidence['parity'] ?? [];
        if (
            ($parity['design_sha256'] ?? null) !== $asset['sha256']
            || ($parity['template_fingerprint'] ?? null) !== ($provider['template']['fingerprint'] ?? false)
            || ($parity['preview_production_match'] ?? null) !== true
        ) {
            $blockers[] = 'PREVIEW_PRODUCTION_PARITY_REQUIRED';
        }
        $media = $evidence['media'] ?? [];
        if (!is_array($media) || !array_is_list($media)) {
            throw new InvalidArgumentException('Media must be an ordered list.');
        }
        $mediaHashes = [];
        foreach ($media as $item) {
            self::reference($item['reference'] ?? null);
            self::hash($item['sha256'] ?? null);
            if (($item['design_sha256'] ?? null) !== $asset['sha256']) {
                throw new InvalidArgumentException('Mockup and approved design mismatch.');
            }
            $mediaHashes[$item['sha256']] = true;
        }
        if (count($mediaHashes) < 5) {
            $blockers[] = 'FIVE_DISTINCT_REVIEWED_MOCKUPS_REQUIRED';
        }

        $economics = self::economics($evidence['economics'] ?? []);
        if ($economics['unknown_costs'] !== []) {
            $blockers[] = 'ECONOMICS_COST_EVIDENCE_REQUIRED';
        }
        $package = ['schema' => 'approved-design-listing-package-v1', 'state' => 'REVIEW_REQUIRED',
            'shop_key' => $shop, 'environment' => $approvedDesign['environment'],
            'fixture_id' => $id, 'design' => $approvedDesign, 'source_approval' => $envelope, 'provider' => $provider,
            'native_personalization' => ['etsy_questions' => $questions, 'overflow_policy' => 'MANUAL_REVIEW'],
            'listing' => $listing, 'media' => $media, 'reviews' => $reviews, 'parity' => $parity,
            'economics' => $economics,
            'reconciliation' => ['etsy_listing_id' => $fixture['etsy_listing_id'] ?? null,
                'etsy_state' => $fixture['etsy_state'] ?? 'UNKNOWN',
                'shipping_profile_id' => $fixture['shipping_profile_id'] ?? null,
                'processing_profile_id' => $fixture['processing_profile_id'] ?? null,
                'status' => 'HISTORICAL_TECHNICAL_FIXTURE_NOT_CURRENT_READBACK'],
            'controls' => ['stop_all' => true, 'external_lock' => true, 'automation' => false,
                'release_hold' => true, 'order_approval' => 'MANUAL'],
            'handoff' => ['path' => 'REVIEW_EXPORT_THEN_SEPARATELY_AUTHORIZED_PRINTIFY_NATIVE',
                'transfer_authorized' => false, 'shared_shipping_profile_effects' => 'REQUIRES_RECONCILIATION'],
            'blockers' => array_values(array_unique($blockers)),
            'supplier_certified' => false, 'commercial_approved' => false,
            'external_execution_authorized' => false, 'external_execution_performed' => false];
        $package['sha256'] = hash('sha256', Validator::canonicalJson($package));
        Validator::structured($package);
        return $package;
    }

    /** Integrity-checked review JSON only; no filesystem, transport or permission effect. */
    public static function export(array $package): string
    {
        self::jsonValues($package);
        Validator::structured($package);
        self::verifySeal($package);
        if (
            ($package['schema'] ?? null) !== 'approved-design-listing-package-v1'
            || ($package['external_execution_authorized'] ?? null) !== false
            || Validator::canonicalJson($package['controls'] ?? []) !== Validator::canonicalJson([
                'stop_all' => true, 'external_lock' => true, 'automation' => false,
                'release_hold' => true, 'order_approval' => 'MANUAL'])
        ) {
            throw new InvalidArgumentException('Only held review artifacts can be exported.');
        }
        return Validator::canonicalJson($package);
    }

    /** Normalize exactly OpportunityDesignRepository::read() output; never client JSON. */
    private static function laneADesign(string $shop, array $envelope, mixed $environment): array
    {
        $record = $envelope['record'] ?? null;
        $approval = $envelope['approval'] ?? null;
        $hash = $envelope['version_hash'] ?? null;
        self::hash($hash);
        if (
            !is_array($record) || !is_array($approval)
            || !hash_equals($hash, hash('sha256', (string) wp_json_encode(
                $record,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            )))
            || ($record['schema_version'] ?? null) !== 1
            || ($record['input']['shop_key'] ?? null) !== $shop
            || ($approval['state'] ?? null) !== 'HUMAN_APPROVED'
            || ($approval['decision'] ?? null) !== 'APPROVE'
            || ($approval['evidence_hash'] ?? null) !== $hash
            || !is_int($approval['approved_by'] ?? null) || $approval['approved_by'] < 1
            || ($envelope['release_hold'] ?? null) !== true
            || ($record['publishing_enabled'] ?? null) !== false
            || ($record['order_execution_enabled'] ?? null) !== false
            || ($approval['publishing_enabled'] ?? null) !== false
            || ($approval['order_execution_enabled'] ?? null) !== false
        ) {
            throw new InvalidArgumentException('Current server-resolved Lane A approval is required.');
        }
        $input = $record['input'];
        $artwork = $input['artwork'] ?? [];
        $svg = $artwork['svg'] ?? null;
        if (!is_string($svg) || $svg === '' || strlen($svg) > 60000) {
            throw new InvalidArgumentException('Immutable editable artwork bytes are required.');
        }
        $sources = [];
        foreach ($input['sources'] ?? [] as $source) {
            $sources[] = ['source_url' => $source['url'] ?? null, 'observed_at' => $source['observed_at'] ?? null];
        }
        // Lane A has no environment member: caller supplies the review context only.
        // No environment-specific provider permission is inferred from the envelope.
        $design = ['shop_key' => $shop, 'environment' => $environment, 'design_id' => $hash,
            'source_version_hash' => $hash,
            'approved_by' => $approval['approved_by'], 'approved_at' => $approval['decided_at'] ?? null,
            'asset' => ['editable_reference' => 'approved-designs/' . $hash . '.svg',
                'sha256' => hash('sha256', $svg), 'width_px' => $artwork['width_px'] ?? null,
                'height_px' => $artwork['height_px'] ?? null, 'dpi' => $artwork['dpi'] ?? null],
            'provenance' => $sources];
        $design['sha256'] = hash('sha256', Validator::canonicalJson($design));
        return $design;
    }

    private static function economics(array $input): array
    {
        $currency = $input['currency'] ?? null;
        if (!is_string($currency) || !preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new InvalidArgumentException('Explicit estimate currency is required.');
        }
        $costs = [];
        $unknown = [];
        foreach (
            ['sale_price', 'base_cost', 'shipping_cost', 'seller_fees', 'ads_cost',
            'fx_cost', 'refund_reserve', 'tax_cost'] as $key
        ) {
            $value = $input[$key] ?? null;
            if ($value === null && $key !== 'sale_price') {
                $unknown[] = $key;
                $costs[$key] = null;
                continue;
            }
            if (
                (!is_int($value) && !is_float($value)) || !is_finite((float) $value)
                || $value < 0 || ($key === 'sale_price' && $value <= 0)
            ) {
                throw new InvalidArgumentException('Finite nonnegative estimate amounts are required.');
            }
            $costs[$key] = (float) $value;
        }
        $contribution = $unknown === [] ? round(2 * $costs['sale_price'] - array_sum($costs), 4) : null;
        return ['classification' => 'CONDITIONAL_ESTIMATE_NOT_REALIZED_PROFIT', 'currency' => $currency,
            'assumptions' => $costs, 'unknown_costs' => $unknown, 'modeled_contribution' => $contribution,
            'modeled_margin_percent' => $contribution === null ? null
                : round(100 * $contribution / $costs['sale_price'], 4),
            'sensitivity' => ['ads_plus_15_percent' => $contribution === null ? null
                : round($contribution - 0.15 * $costs['sale_price'], 4),
                'shipping_plus_5' => $contribution === null ? null : round($contribution - 5, 4),
                'fx_plus_3_percent' => $contribution === null ? null
                    : round($contribution - 0.03 * $costs['sale_price'], 4)],
            'manual_price_decision_required' => true];
    }

    private static function verifySeal(array $record): void
    {
        $hash = $record['sha256'] ?? null;
        self::hash($hash);
        unset($record['sha256']);
        if (!hash_equals($hash, hash('sha256', Validator::canonicalJson($record)))) {
            throw new InvalidArgumentException('Immutable snapshot hash mismatch.');
        }
    }

    private static function hash(mixed $value): void
    {
        if (!is_string($value) || !preg_match('/^[a-f0-9]{64}$/D', $value)) {
            throw new InvalidArgumentException('SHA256 evidence is required.');
        }
    }

    private static function jsonValues(mixed $value, int $depth = 0): void
    {
        if ($depth > 20 || is_object($value) || is_resource($value)) {
            throw new InvalidArgumentException('Bounded JSON values only.');
        }
        if (is_array($value)) {
            foreach ($value as $child) {
                self::jsonValues($child, $depth + 1);
            }
        }
    }

    private static function text(mixed $value, int $limit): void
    {
        if (
            !is_string($value) || trim($value) === '' || trim($value) !== $value
            || preg_match('//u', $value) !== 1 || mb_strlen($value, 'UTF-8') > $limit
            || strip_tags($value) !== $value || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $value)
        ) {
            throw new InvalidArgumentException('Bounded plain UTF-8 text is required.');
        }
    }

    private static function timestamp(mixed $value): void
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('Absolute source/approval timestamp is required.');
        }
        $normalized = str_ends_with($value, 'Z') ? substr($value, 0, -1) . '+00:00' : $value;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $normalized);
        if (!$date || $date->format('Y-m-d\TH:i:sP') !== $normalized) {
            throw new InvalidArgumentException('Absolute source/approval timestamp is required.');
        }
    }

    private static function reference(mixed $value): void
    {
        if (
            !is_string($value) || !preg_match('#^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_.-]+)+$#D', $value)
            || str_contains($value, '..') || strlen($value) > 512
        ) {
            throw new InvalidArgumentException('A local opaque artwork/media reference is required.');
        }
    }

    private static function url(mixed $value): void
    {
        if (!is_string($value) || strlen($value) > 2048 || !filter_var($value, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('Public source URL is required.');
        }
        $parts = parse_url($value);
        if (
            ($parts['scheme'] ?? null) !== 'https' || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
        ) {
            throw new InvalidArgumentException('Credential-free HTTPS source URL is required.');
        }
    }
}
