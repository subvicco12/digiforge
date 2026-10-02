<?php
declare(strict_types=1);

namespace DigiForge\Listings;

/** Bounded local evidence of accepted Etsy digital file uploads. No provider request. */
final class EtsyDigitalAttachmentReadModel
{
    public function recent(int $limit = 50, ?string &$evidenceState = null): array
    {
        global $wpdb;
        $limit = max(1, min(100, $limit));
        $table = $wpdb->prefix . 'digiforge_etsy_operations';
        $evidenceState = 'UNAVAILABLE';
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id,shop_reference,intent_id,draft_package_id,operation_type,resource_reference,state,external_reference,external_asset_reference,request_fingerprint,evidence_hash,updated_at FROM {$table} WHERE operation_type='UPLOAD_FILE' AND state='CONFIRMED_SUCCESS' ORDER BY id DESC LIMIT %d",
            $limit
        ), ARRAY_A);
        if (!empty($wpdb->last_error) || !is_array($rows)) {
            return [];
        }
        $evidenceState = 'AVAILABLE';
        return array_map([self::class, 'project'], $rows);
    }

    /** Stored identity checks only; this does not verify current Etsy file state. */
    public static function project(array $row): array
    {
        $listing = trim((string) ($row['external_reference'] ?? ''));
        $resource = trim((string) ($row['resource_reference'] ?? ''));
        $file = trim((string) ($row['external_asset_reference'] ?? ''));
        $identity = (int) ($row['id'] ?? 0) > 0
            && (string) ($row['operation_type'] ?? '') === 'UPLOAD_FILE'
            && (string) ($row['state'] ?? '') === 'CONFIRMED_SUCCESS'
            && (int) ($row['intent_id'] ?? 0) > 0
            && (int) ($row['draft_package_id'] ?? 0) > 0
            && ctype_digit($listing) && (int) $listing > 0
            && hash_equals($listing, $resource)
            && ctype_digit($file) && (int) $file > 0;
        $hashes = preg_match('/^[a-f0-9]{64}$/', (string) ($row['request_fingerprint'] ?? '')) === 1
            && preg_match('/^[a-f0-9]{64}$/', (string) ($row['evidence_hash'] ?? '')) === 1;
        return [
            'operation_id' => (int) ($row['id'] ?? 0),
            'shop_reference' => (string) ($row['shop_reference'] ?? ''),
            'listing_id' => $listing,
            'listing_file_id' => $file,
            'intent_id' => (int) ($row['intent_id'] ?? 0),
            'draft_package_id' => (int) ($row['draft_package_id'] ?? 0),
            'evidence_state' => $identity && $hashes ? 'CONFIRMED_IDENTITY_RECORDED' : 'REVIEW_REQUIRED',
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'provider_live_verified' => false,
            'upload_permitted' => false,
            'publish_permitted' => false,
            'external_execution_authorized' => false,
            'read_only' => true,
        ];
    }
}
