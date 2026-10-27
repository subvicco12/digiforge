<?php
declare(strict_types=1);

namespace DigiForge\ProductFactory;

use DigiForge\Database\Tables;
use DigiForge\Portal\ProductEvidenceScope;
use DigiForge\Production\Validator;
use DigiForge\Security\ShopAccess;
use WP_Error;

/** Rechecks local external-document custody at every PDF approval/release gate. */
final class ExternalDocumentRevision
{
    public static function validate(array $revision): true|WP_Error
    {
        $reviewActor = get_current_user_id();
        if ($reviewActor < 1 || ($revision['mime_type'] ?? null) !== 'application/pdf') { return self::blocked(); }
        $provenance = $revision['provenance'] ?? null;
        if (is_string($provenance)) {
            try { $provenance = json_decode($provenance, true, 16, JSON_THROW_ON_ERROR); }
            catch (\Throwable) { return self::blocked(); }
        }
        if (!is_array($provenance) || ($provenance['generator'] ?? null) !== 'digiforge_external_document'
            || ($provenance['pdf_generator_profile'] ?? null) !== 'digiforge-external-docx-pdf-v1') {
            return self::blocked();
        }
        $binding = $provenance['binding'] ?? null;
        if (!is_array($binding) || ($binding['shop_key'] ?? null) !== 'digital'
            || !self::positive($binding['actor_id'] ?? null)
            || !self::positive($binding['product_id'] ?? null)
            || !self::positive($binding['product_version_id'] ?? null)
            || !self::positive($binding['asset_spec_id'] ?? null)
            || !is_string($binding['operation_key'] ?? null)
            || preg_match('/^[A-Za-z0-9_.:-]{1,160}$/D', $binding['operation_key']) !== 1
            || ($revision['revision_label'] ?? null) !== $binding['operation_key']) {
            return self::blocked();
        }
        $access = ShopAccess::authorize('digital', 'manage_digiforge_products');
        if ($access instanceof WP_Error) { return $access; }
        if (($revision['asset_spec_id'] ?? null) === null
            || (int)$revision['asset_spec_id'] !== $binding['asset_spec_id']
            || !self::positive((int)($revision['id'] ?? 0))) {
            return self::blocked();
        }

        global $wpdb;
        $spec = Tables::asset_specs(); $versions = Tables::product_versions();
        $scope = ProductEvidenceScope::forTable($spec, 'digital');
        if (!is_string($scope)) { return self::blocked(); }
        $wpdb->last_error = '';
        $lineage = $wpdb->get_results($wpdb->prepare(
            "SELECT {$spec}.id, {$spec}.product_version_id, {$versions}.product_id FROM {$spec} INNER JOIN {$versions} ON {$versions}.id={$spec}.product_version_id WHERE {$spec}.id=%d AND {$spec}.format='pdf' AND {$scope}",
            $binding['asset_spec_id']
        ), ARRAY_A);
        if ($wpdb->last_error !== '' || !is_array($lineage)) { return self::unavailable(); }
        if (count($lineage) !== 1 || (int)$lineage[0]['product_version_id'] !== $binding['product_version_id']
            || (int)$lineage[0]['product_id'] !== $binding['product_id']) { return self::blocked(); }

        $manifestReference = $provenance['custody_manifest_storage_reference'] ?? null;
        $manifestHash = $provenance['custody_manifest_sha256'] ?? null;
        $base = AssetStorage::RELATIVE_ROOT . '/external-documents/' . $binding['actor_id'] . '/'
            . $binding['product_version_id'] . '/' . $binding['asset_spec_id'] . '/';
        if (!is_string($manifestReference)
            || $manifestReference !== $base . hash('sha256', $binding['operation_key']) . '.json'
            || !self::sha($manifestHash)) { return self::blocked(); }
        $manifestPath = self::path($manifestReference, 65536);
        if ($manifestPath === null) { return self::blocked(); }
        $manifestBytes = @file_get_contents($manifestPath, false, null, 0, 65537);
        if (!is_string($manifestBytes) || strlen($manifestBytes) > 65536
            || !hash_equals($manifestHash, hash('sha256', $manifestBytes))) { return self::blocked(); }
        try { $manifest = json_decode($manifestBytes, true, 16, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { return self::blocked(); }
        if (!is_array($manifest) || array_keys($manifest) !== ['binding', 'evidence', 'export', 'source']
            || !self::same($manifest['binding'], $binding)) { return self::blocked(); }
        $files = [];
        foreach (['source' => ['docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
                  'export' => ['pdf', 'application/pdf']] as $kind => [$extension, $mime]) {
            $item = $manifest[$kind] ?? null;
            if (!is_array($item) || !self::sha($item['checksum_sha256'] ?? null)
                || !self::positive($item['byte_size'] ?? null) || $item['byte_size'] > 16777216
                || ($item['mime_type'] ?? null) !== $mime
                || ($item['storage_reference'] ?? null) !== $base . $kind . '-' . $item['checksum_sha256'] . '.' . $extension) {
                return self::blocked();
            }
            $path = self::path($item['storage_reference'], 16777216);
            if ($path === null || filesize($path) !== $item['byte_size']
                || !hash_equals($item['checksum_sha256'], (string)hash_file('sha256', $path))) { return self::blocked(); }
            $files[$kind] = $path;
        }
        $export = $manifest['export']; $source = $manifest['source'];
        if (($revision['storage_reference'] ?? null) !== $export['storage_reference']
            || ($revision['checksum_sha256'] ?? null) !== $export['checksum_sha256']
            || (int)($revision['byte_size'] ?? 0) !== $export['byte_size']
            || ($provenance['source_storage_reference'] ?? null) !== $source['storage_reference']
            || ($provenance['source_byte_size'] ?? null) !== $source['byte_size']) { return self::blocked(); }

        $receipt = ExternalDocumentEvidence::inspect($files['source'], $files['export']);
        if ($receipt instanceof WP_Error || !self::same($manifest['evidence'], $receipt)
            || !self::same($provenance['evidence'] ?? null, $receipt)) { return self::blocked(); }
        foreach ($receipt as $key => $value) {
            if (($provenance[$key] ?? null) !== $value) { return self::blocked(); }
        }

        $wpdb->last_error = '';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT status,details FROM " . Tables::production_qa()
            . " WHERE target_type='revision' AND target_id=%d AND check_type='external_document_source_completeness'",
            (int)$revision['id']
        ), ARRAY_A);
        if ($wpdb->last_error !== '' || !is_array($rows)) { return self::unavailable(); }
        if (count($rows) !== 1 || ($rows[0]['status'] ?? null) !== 'PASS') { return self::blocked(); }
        try { $details = json_decode((string)$rows[0]['details'], true, 16, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { return self::blocked(); }
        if (!self::same($details, $receipt + ['binding' => $binding])) { return self::blocked(); }
        // The bounded verifier and QA read may take time. Authority and stored
        // ownership are mutable, so a passing earlier check cannot be reused.
        if (get_current_user_id() !== $reviewActor) { return self::blocked(); }
        $access = ShopAccess::authorize('digital', 'manage_digiforge_products');
        if ($access instanceof WP_Error) { return $access; }
        $scope = ProductEvidenceScope::forTable($spec, 'digital');
        if (!is_string($scope)) { return self::blocked(); }
        $wpdb->last_error = '';
        $current = $wpdb->get_results($wpdb->prepare(
            "SELECT {$spec}.id FROM {$spec} INNER JOIN {$versions} ON {$versions}.id={$spec}.product_version_id WHERE {$spec}.id=%d AND {$spec}.product_version_id=%d AND {$versions}.product_id=%d AND {$spec}.format='pdf' AND {$scope}",
            $binding['asset_spec_id'], $binding['product_version_id'], $binding['product_id']
        ), ARRAY_A);
        if ($wpdb->last_error !== '' || !is_array($current)) { return self::unavailable(); }
        if (count($current) !== 1) { return self::blocked(); }
        // A QA read can race with changes to retained bytes or storage paths.
        // Recheck the same manifest and both exact source/export byte identities
        // after the final authority and ownership query.
        $freshManifest = self::path($manifestReference, 65536);
        if ($freshManifest === null) { return self::blocked(); }
        $freshBytes = @file_get_contents($freshManifest, false, null, 0, 65537);
        if (!is_string($freshBytes) || strlen($freshBytes) > 65536
            || !hash_equals($manifestHash, hash('sha256', $freshBytes))) { return self::blocked(); }
        foreach (['source', 'export'] as $kind) {
            $item = $manifest[$kind];
            $freshPath = self::path($item['storage_reference'], 16777216);
            if ($freshPath === null || $freshPath !== $files[$kind]) { return self::blocked(); }
            clearstatcache(true, $freshPath);
            $hash = @hash_file('sha256', $freshPath);
            if (@filesize($freshPath) !== $item['byte_size'] || !is_string($hash)
                || !hash_equals($item['checksum_sha256'], $hash)) { return self::blocked(); }
        }
        // The QA row must belong to this stored immutable revision, rather
        // than an in-memory array borrowing a genuine revision ID.
        $wpdb->last_error = '';
        $stored = $wpdb->get_results($wpdb->prepare(
            'SELECT asset_spec_id,revision_label,storage_reference,checksum_sha256,mime_type,byte_size,provenance FROM '
            . Tables::asset_revisions() . ' WHERE id=%d', (int)$revision['id']
        ), ARRAY_A);
        if ($wpdb->last_error !== '' || !is_array($stored)) { return self::unavailable(); }
        if (count($stored) !== 1
            || (int)$stored[0]['asset_spec_id'] !== $binding['asset_spec_id']
            || $stored[0]['revision_label'] !== $binding['operation_key']
            || $stored[0]['storage_reference'] !== $export['storage_reference']
            || $stored[0]['checksum_sha256'] !== $export['checksum_sha256']
            || $stored[0]['mime_type'] !== 'application/pdf'
            || (int)$stored[0]['byte_size'] !== $export['byte_size']) { return self::blocked(); }
        try { $storedProvenance = json_decode((string)$stored[0]['provenance'], true, 16, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { return self::blocked(); }
        if (!self::same($storedProvenance, $provenance)) { return self::blocked(); }
        return true;
    }

    private static function path(string $reference, int $limit): ?string
    {
        $uploads = wp_upload_dir();
        if (!empty($uploads['error']) || !is_string($uploads['basedir'] ?? null)
            || str_contains($reference, '..') || str_contains($reference, '//')) { return null; }
        $unresolved = trailingslashit($uploads['basedir']) . $reference;
        for ($part = $unresolved; ; $part = dirname($part)) {
            if (is_link($part)) { return null; }
            if (dirname($part) === $part) { break; }
        }
        $path = AssetStorage::absolutePath($reference);
        if ($path === null || !is_readable($path) || !is_file($path)) { return null; }
        for ($part = $path; ; $part = dirname($part)) {
            if (is_link($part)) { return null; }
            if (dirname($part) === $part) { break; }
        }
        clearstatcache(true, $path);
        $size = @filesize($path);
        return is_int($size) && $size > 0 && $size <= $limit ? $path : null;
    }

    private static function same(mixed $a, mixed $b): bool
    {
        if (!is_array($a) || !is_array($b)) { return false; }
        $validator = new Validator();
        return hash_equals($validator->canonicalJson($a), $validator->canonicalJson($b));
    }
    private static function positive(mixed $value): bool { return is_int($value) && $value > 0; }
    private static function sha(mixed $value): bool { return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1; }
    private static function blocked(): WP_Error { return new WP_Error('digiforge_external_document_revision_blocked', 'Current exact digital-document custody and source-completeness evidence is required.', ['status' => 409]); }
    private static function unavailable(): WP_Error { return new WP_Error('digiforge_external_document_revision_unavailable', 'Digital-document lineage or QA evidence is unavailable.', ['status' => 503]); }
}
