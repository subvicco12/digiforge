<?php
declare(strict_types=1);

namespace DigiForge\ProductFactory;

use DigiForge\Database\Tables;
use DigiForge\Listings\Repository as ListingRepository;
use DigiForge\Security\Logger;
use WP_Error;

/**
 * Continues Gate 2 Product Approval into local Etsy listing preparation.
 *
 * Everything here is database/local evidence only. No Etsy API, provider call,
 * publication, inventory mutation, fulfillment, or external execution occurs.
 */
final class ListingAutomation
{
    private const HOOK = 'digiforge_u3_build_listing';

    public function register(): void
    {
        add_action('digiforge_log', [$this, 'onAudit'], 30, 4);
        add_action(self::HOOK, [$this, 'run'], 10, 1);
    }

    /** @param array<string,mixed> $context */
    public function onAudit(string $event, array $context, string $objectType, string $objectId): void
    {
        if ($event !== 'u3_product_approved' || $objectType !== 'product_version') {
            return;
        }
        $productVersionId = absint($objectId);
        if ($productVersionId < 1) {
            return;
        }

        if (function_exists('as_enqueue_async_action')) {
            $actionId = as_enqueue_async_action(self::HOOK, [$productVersionId], 'digiforge', true);
            if (is_int($actionId) && $actionId > 0) {
                Logger::audit('u3_listing_build_scheduled', ['external_actions' => false], 'product_version', (string) $productVersionId);
                return;
            }
        }

        $scheduled = wp_schedule_single_event(time() + 1, self::HOOK, [$productVersionId], true) === true;
        Logger::audit(
            $scheduled ? 'u3_listing_build_scheduled' : 'u3_listing_build_not_scheduled',
            ['external_actions' => false],
            'product_version',
            (string) $productVersionId
        );
    }

    public function run(int $productVersionId): void
    {
        $result = $this->build($productVersionId);
        if (is_wp_error($result)) {
            Logger::audit('u3_listing_build_failed', [
                'error_code' => $result->get_error_code(),
                'message' => $result->get_error_message(),
                'external_actions' => false,
            ], 'product_version', (string) $productVersionId);
            return;
        }

        Logger::audit('u3_listing_build_completed', [
            'listing_id' => (int) ($result['listing']['id'] ?? 0),
            'workflow_status' => Workflow::LISTING_REVIEW_REQUIRED,
            'external_actions' => false,
        ], 'product_version', (string) $productVersionId);
    }

    /** @return array<string,mixed>|WP_Error */
    public function build(int $productVersionId): array|WP_Error
    {
        if ($productVersionId < 1) {
            return $this->error('invalid_product_version', 'Valid product version is required.');
        }

        global $wpdb;
        $version = $wpdb->get_row($wpdb->prepare(
            'SELECT pv.*,p.name AS product_name,p.description AS product_description FROM ' . Tables::product_versions() . ' pv '
            . 'INNER JOIN ' . Tables::products() . ' p ON p.id=pv.product_id WHERE pv.id=%d LIMIT 1',
            $productVersionId
        ), ARRAY_A);
        if (! is_array($version) || ! in_array((string) ($version['state'] ?? ''), ['APPROVED','RELEASED'], true)) {
            return $this->error('product_not_approved', 'Product version must be approved before listing preparation.', 409);
        }

        $existing = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . Tables::listings() . ' WHERE product_version_id=%d ORDER BY id DESC LIMIT 1',
            $productVersionId
        ), ARRAY_A);
        if (is_array($existing)) {
            return [
                'listing' => $existing,
                'workflow_status' => (string) ($existing['state'] ?? '') === 'REVIEW_REQUIRED'
                    ? Workflow::LISTING_REVIEW_REQUIRED
                    : Workflow::LISTING_BUILDING,
                'external_actions_performed' => false,
                'idempotent' => true,
            ];
        }

        $notes = json_decode((string) ($version['notes'] ?? '{}'), true);
        $notes = is_array($notes) ? $notes : [];
        $spec = is_array($notes['spec'] ?? null) ? $notes['spec'] : [];
        $shop = sanitize_key((string) ($notes['shop'] ?? 'digital'));
        if (! in_array($shop, ['digital','goods'], true)) {
            return $this->error('shop_missing', 'Approved product does not contain a supported shop target.', 409);
        }

        $price = $spec['recommended_price_amount'] ?? null;
        if (! is_numeric($price) || (float) $price <= 0) {
            return $this->error('price_missing', 'Approved specification must contain a positive recommended_price_amount before listing preparation.', 409);
        }
        $currency = strtoupper(sanitize_text_field((string) ($spec['currency'] ?? 'USD')));
        $title = sanitize_text_field((string) ($spec['listing_title_draft'] ?? $version['product_name'] ?? ''));
        $description = sanitize_textarea_field((string) ($spec['listing_description_draft'] ?? $version['product_description'] ?? ''));
        if ($title === '' || $description === '') {
            return $this->error('content_missing', 'Approved specification must contain listing title and description content.', 409);
        }

        $repo = new ListingRepository();
        $listing = $repo->createListing([
            'product_version_id' => $productVersionId,
            'channel' => 'etsy',
            'environment' => 'production',
            'shop_reference' => $shop === 'digital' ? 'DigiCraftifyDigital' : 'DigiCraftifyGoods',
            'title' => $title,
            'description' => $description,
            'taxonomy_metadata' => [
                'target_buyer' => sanitize_text_field((string) ($spec['target_buyer'] ?? '')),
                'differentiation' => sanitize_textarea_field((string) ($spec['differentiation'] ?? '')),
            ],
            'price_amount' => (float) $price,
            'currency' => $currency,
            'quantity_policy' => ['mode' => $shop === 'digital' ? 'digital' : 'provider_managed'],
            'personalization_enabled' => ! empty($spec['personalization']),
        ], 'u3-listing-' . $productVersionId);
        if (is_wp_error($listing)) {
            return $listing;
        }

        $tags = $this->listValues($spec['etsy_tags'] ?? $spec['seo_keywords'] ?? []);
        $seo = $repo->setSeo([
            'listing_id' => (int) $listing['id'],
            'tags' => array_slice($tags, 0, 13),
            'keywords' => $tags,
            'audience_metadata' => ['target_buyer' => (string) ($spec['target_buyer'] ?? '')],
            'evidence' => ['source' => 'approved_product_specification'],
        ], 'u3-listing-seo-' . $productVersionId);
        if (is_wp_error($seo)) {
            return $seo;
        }

        $media = $wpdb->get_results($wpdb->prepare(
            'SELECT ar.id FROM ' . Tables::asset_revisions() . ' ar '
            . 'INNER JOIN ' . Tables::asset_specs() . ' s ON s.id=ar.asset_spec_id '
            . "WHERE s.product_version_id=%d AND s.asset_type='marketing_asset' AND ar.state='APPROVED' ORDER BY s.id ASC",
            $productVersionId
        ), ARRAY_A);
        $media = is_array($media) ? $media : [];
        if ($media === []) {
            return $this->error('media_missing', 'At least one approved marketing asset is required before listing review.', 409);
        }
        foreach (array_slice($media, 0, 10) as $position => $row) {
            $bound = $repo->bindMedia([
                'listing_id' => (int) $listing['id'],
                'asset_revision_id' => (int) ($row['id'] ?? 0),
                'media_role' => $position === 0 ? 'hero' : 'image',
                'position_index' => $position,
            ], 'u3-listing-media-' . $productVersionId . '-' . $position);
            if (is_wp_error($bound)) {
                return $bound;
            }
        }

        $validated = $repo->transition('listing', (int) $listing['id'], 'VALIDATED');
        if (is_wp_error($validated)) {
            return $validated;
        }
        $review = $repo->transition('listing', (int) $listing['id'], 'REVIEW_REQUIRED');
        if (is_wp_error($review)) {
            return $review;
        }

        return [
            'listing' => $review,
            'seo' => $seo,
            'media_count' => count(array_slice($media, 0, 10)),
            'workflow_status' => Workflow::LISTING_REVIEW_REQUIRED,
            'publish_authorized' => false,
            'etsy_api_invoked' => false,
            'external_actions_performed' => false,
        ];
    }

    /** @return list<string> */
    private function listValues(mixed $value): array
    {
        $items = is_array($value) ? $value : preg_split('/[,;\n]+/', (string) $value);
        $out = [];
        foreach (is_array($items) ? $items : [] as $item) {
            $clean = trim(sanitize_text_field((string) $item));
            if ($clean !== '' && ! in_array($clean, $out, true)) {
                $out[] = $clean;
            }
        }
        return array_slice($out, 0, 20);
    }

    private function error(string $code, string $message, int $status = 400): WP_Error
    {
        return new WP_Error('digiforge_u3_listing_' . $code, __($message, 'digiforge'), ['status' => $status]);
    }
}
