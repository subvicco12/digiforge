<?php

declare(strict_types=1);

namespace DigiForge\Listings;

use DigiForge\Database\Tables;
use DigiForge\Security\Logger;
use WP_Error;

/**
 * Deterministically prepares local listing evidence after Gate 2.
 *
 * This service never calls Etsy or any external provider. It stops the listing
 * at REVIEW_REQUIRED so Gate 3 remains an explicit human decision.
 */
final class ListingFactory
{
    public function prepare(int $productVersionId, string $idempotencyKey): array|WP_Error
    {
        if ($productVersionId < 1) {
            return $this->error('listing_factory_product_version', 'Valid product version is required.');
        }
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 160) {
            return $this->error('listing_factory_idempotency', 'A bounded idempotency key is required.');
        }

        global $wpdb;
        $version = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . Tables::product_versions() . ' WHERE id=%d LIMIT 1', $productVersionId),
            ARRAY_A
        );
        if (! is_array($version) || (string) ($version['state'] ?? '') !== 'APPROVED') {
            return $this->error('listing_factory_gate2', 'Product version must be human-approved before listing preparation.', 409);
        }

        $bundle = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT b.* FROM ' . Tables::release_bundles() . ' b INNER JOIN ' . Tables::production_plans()
                . " p ON p.id=b.production_plan_id WHERE p.product_version_id=%d AND p.state='APPROVED'"
                . " AND b.state='RELEASE_READY' ORDER BY b.id DESC LIMIT 1",
                $productVersionId
            ),
            ARRAY_A
        );
        if (! is_array($bundle)) {
            return $this->error('listing_factory_release', 'An approved release-ready bundle is required.', 409);
        }

        $notes = json_decode((string) ($version['notes'] ?? ''), true);
        $spec = is_array($notes) && isset($notes['spec']) && is_array($notes['spec']) ? $notes['spec'] : null;
        if (! is_array($spec)) {
            return $this->error('listing_factory_spec', 'Product capability specification is missing.', 409);
        }

        $title = trim((string) ($spec['listing_title_draft'] ?? ''));
        $description = trim((string) ($spec['listing_description_draft'] ?? ''));
        $keywords = isset($spec['seo_keywords']) && is_array($spec['seo_keywords']) ? array_values($spec['seo_keywords']) : [];
        $price = $spec['price_strategy']['recommended_etsy_price_usd'] ?? null;
        if ($title === '' || $description === '' || $keywords === [] || ! is_numeric($price)) {
            return $this->error('listing_factory_spec', 'Listing title, description, SEO keywords and USD price are required.', 409);
        }

        $repo = new Repository();
        $listing = $repo->createListing([
            'product_version_id' => $productVersionId,
            'channel' => 'etsy',
            'environment' => 'production',
            'shop_reference' => 'KinetiqMatrixDesigns',
            'title' => $title,
            'description' => $description,
            'taxonomy_metadata' => [
                'family_name' => (string) ($spec['family_name'] ?? ''),
                'product_name' => (string) ($spec['product_name'] ?? ''),
                'target_buyer' => (string) ($spec['target_buyer'] ?? ''),
            ],
            'price_amount' => (float) $price,
            'currency' => 'USD',
            'quantity_policy' => ['delivery' => 'digital_download'],
            'personalization_enabled' => false,
        ], $idempotencyKey . '-listing');
        if (is_wp_error($listing)) {
            return $listing;
        }
        $listingId = (int) ($listing['id'] ?? 0);

        $seo = $repo->setSeo([
            'listing_id' => $listingId,
            'tags' => array_slice($keywords, 0, 13),
            'keywords' => $keywords,
            'materials' => ['PDF', 'SVG', 'ZIP', 'digital download'],
            'attributes' => [
                'language' => (string) ($spec['delivery_selection']['selected_language'] ?? 'English'),
                'variant' => (string) ($spec['delivery_selection']['selected_variant'] ?? ''),
            ],
            'audience_metadata' => [
                'target_buyer' => (string) ($spec['target_buyer'] ?? ''),
                'positioning' => (string) ($spec['price_strategy']['positioning'] ?? ''),
            ],
            'evidence' => [
                'product_version_id' => $productVersionId,
                'release_bundle_id' => (int) $bundle['id'],
                'source' => 'approved_product_capability_spec',
            ],
        ], $idempotencyKey . '-seo');
        if (is_wp_error($seo)) {
            return $seo;
        }

        $media = $repo->bindMedia([
            'listing_id' => $listingId,
            'release_bundle_id' => (int) $bundle['id'],
            'media_role' => 'release_bundle',
            'position_index' => 0,
        ], $idempotencyKey . '-media');
        if (is_wp_error($media)) {
            return $media;
        }

        $validated = $repo->transition('listing', $listingId, 'VALIDATED');
        if (is_wp_error($validated)) {
            return $validated;
        }
        $review = $repo->transition('listing', $listingId, 'REVIEW_REQUIRED');
        if (is_wp_error($review)) {
            return $review;
        }

        $readiness = $repo->readiness($listingId);
        if (is_wp_error($readiness)) {
            return $readiness;
        }

        Logger::audit('u3_listing_factory_completed', [
            'product_version_id' => $productVersionId,
            'listing_id' => $listingId,
            'release_bundle_id' => (int) $bundle['id'],
            'workflow_status' => 'LISTING_REVIEW_REQUIRED',
            'listing_approval_required' => true,
            'etsy_api_invoked' => false,
            'external_actions' => false,
        ], 'listing', (string) $listingId);

        return [
            'product_version_id' => $productVersionId,
            'listing_id' => $listingId,
            'release_bundle_id' => (int) $bundle['id'],
            'listing' => $review,
            'seo' => $seo,
            'media' => $media,
            'readiness' => $readiness,
            'workflow_status' => 'LISTING_REVIEW_REQUIRED',
            'listing_approval_required' => true,
            'draft_package_created' => false,
            'etsy_api_invoked' => false,
            'external_actions_performed' => false,
        ];
    }

    private function error(string $code, string $message, int $status = 400): WP_Error
    {
        return new WP_Error($code, $message, ['status' => $status]);
    }
}
