<?php
declare(strict_types=1);

namespace DigiForge\ProductFactory;

use DigiForge\Database\Tables;
use DigiForge\Production\Repository as ProductionRepository;
use DigiForge\Security\Logger;
use WP_Error;

/**
 * Creates a local PNG derivative from an approved SVG revision without
 * mutating the source specification, source revision, plan, or release bundle.
 */
final class DerivedRasterService
{
    public function __construct(
        private ?ProductionRepository $production = null,
        private ?LocalAssetProducer $producer = null
    ) {
        $this->production ??= new ProductionRepository();
        $this->producer ??= new LocalAssetProducer();
    }

    /** @param array<string,mixed> $input @return array<string,mixed>|WP_Error */
    public function derive(int $sourceRevisionId, array $input, ?string $idempotencyKey = null): array|WP_Error
    {
        if ($sourceRevisionId < 1) { return $this->error('invalid_source', 'A valid source revision is required.'); }
        $key = trim((string) $idempotencyKey);
        if ($key === '') { return $this->error('missing_idempotency_key', 'Idempotency-Key is required.'); }

        global $wpdb;
        $source = $wpdb->get_row($wpdb->prepare(
            'SELECT ar.*,s.product_version_id,s.asset_key,s.asset_type,s.variant_key,s.locale FROM ' . Tables::asset_revisions() . ' ar INNER JOIN ' . Tables::asset_specs() . ' s ON s.id=ar.asset_spec_id WHERE ar.id=%d LIMIT 1',
            $sourceRevisionId
        ), ARRAY_A);
        if (! is_array($source)) { return $this->error('source_not_found', 'Source revision was not found.', 404); }
        if ((string) ($source['state'] ?? '') !== 'APPROVED' || strtolower((string) ($source['mime_type'] ?? '')) !== 'image/svg+xml') {
            return $this->error('source_not_approved_svg', 'Raster derivation requires an approved SVG source revision.', 409);
        }
        $sourceChecksum = strtolower((string) ($source['checksum_sha256'] ?? ''));
        $sourcePath = AssetStorage::absolutePath((string) ($source['storage_reference'] ?? ''));
        if ($sourcePath === null || ! is_file($sourcePath) || ! preg_match('/^[a-f0-9]{64}$/', $sourceChecksum)) {
            return $this->error('source_integrity', 'Approved SVG source integrity evidence is unavailable.', 409);
        }
        $actualSourceChecksum = hash_file('sha256', $sourcePath);
        if (! is_string($actualSourceChecksum) || ! hash_equals($sourceChecksum, strtolower($actualSourceChecksum))) {
            return $this->error('source_integrity', 'Approved SVG source bytes do not match recorded integrity evidence.', 409);
        }

        $productVersionId = (int) $source['product_version_id'];
        $width = max(500, min(4000, absint($input['width_px'] ?? 2000)));
        $height = max(500, min(4000, absint($input['height_px'] ?? 2000)));
        $variant = sanitize_key((string) ($input['variant_key'] ?? 'etsy-listing'));
        if ($variant === '') { $variant = 'etsy-listing'; }
        $assetKey = sanitize_key((string) ($input['asset_key'] ?? ((string) $source['asset_key'] . '-png')));
        if ($assetKey === '') { return $this->error('invalid_asset_key', 'A derived asset key is required.'); }
        $filename = sanitize_file_name((string) ($input['filename'] ?? ($assetKey . '.png')));
        if ($filename === '') { return $this->error('invalid_filename', 'A safe PNG filename is required.'); }

        $spec = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . Tables::asset_specs() . ' WHERE product_version_id=%d AND asset_key=%s AND variant_key=%s AND locale=%s LIMIT 1',
            $productVersionId, $assetKey, $variant, (string) ($source['locale'] ?? '')
        ), ARRAY_A);
        if (! is_array($spec)) {
            $created = $this->production->createSpec([
                'product_version_id' => $productVersionId,
                'asset_key' => $assetKey,
                'asset_type' => 'listing_image',
                'purpose' => 'Governed local PNG derivative for listing media; source approval is not inherited.',
                'format' => 'png',
                'width_px' => $width,
                'height_px' => $height,
                'variant_key' => $variant,
                'locale' => (string) ($source['locale'] ?? ''),
                'content_requirements' => ['derived_from_revision_id' => $sourceRevisionId],
                'design_constraints' => ['local_transform_only' => true, 'approval_inherited' => false],
            ], 'raster-spec-' . hash('sha256', $key));
            if (is_wp_error($created)) { return $created; }
            $spec = $created;
        } elseif ((string) ($spec['format'] ?? '') !== 'png' || (int) ($spec['product_version_id'] ?? 0) !== $productVersionId) {
            return $this->error('derived_spec_conflict', 'Existing derived asset specification is incompatible.', 409);
        }

        $rendered = $this->producer->rasterizeSvg($productVersionId, (string) $source['storage_reference'], $filename, $width, $height, $variant);
        if (is_wp_error($rendered)) { return $rendered; }

        $revision = $this->production->addRevision([
            'asset_spec_id' => (int) $spec['id'],
            'revision_label' => 'derived-' . substr($sourceChecksum, 0, 12) . '-' . (int) $rendered['width_px'] . 'x' . (int) $rendered['height_px'],
            'storage_reference' => (string) $rendered['storage_reference'],
            'checksum_sha256' => (string) $rendered['checksum_sha256'],
            'mime_type' => 'image/png',
            'byte_size' => (int) $rendered['byte_size'],
            'width_px' => (int) $rendered['width_px'],
            'height_px' => (int) $rendered['height_px'],
            'provenance' => [
                'derivation_type' => 'local_svg_to_png',
                'source_revision_id' => $sourceRevisionId,
                'source_asset_spec_id' => (int) $source['asset_spec_id'],
                'source_checksum_sha256' => $sourceChecksum,
                'source_storage_reference' => (string) $source['storage_reference'],
                'approval_inherited' => false,
                'external_action_performed' => false,
                'generator_version' => defined('DIGIFORGE_VERSION') ? DIGIFORGE_VERSION : '',
            ],
        ], 'raster-revision-' . hash('sha256', $key));
        if (is_wp_error($revision)) { return $revision; }

        Logger::audit('approved_svg_raster_derived', [
            'source_revision_id' => $sourceRevisionId,
            'derived_asset_spec_id' => (int) $spec['id'],
            'derived_revision_id' => (int) $revision['id'],
            'approval_inherited' => false,
            'external_action_performed' => false,
        ], 'asset_revision', (string) $revision['id']);

        return [
            'source_revision_id' => $sourceRevisionId,
            'derived_spec' => $spec,
            'derived_revision' => $revision,
            'qa_required' => true,
            'approval_required' => true,
            'release_bundle_mutated' => false,
            'external_action_performed' => false,
        ];
    }

    private function error(string $code, string $message, int $status = 400): WP_Error
    {
        return new WP_Error('digiforge_raster_' . $code, __($message, 'digiforge'), ['status' => $status]);
    }
}
