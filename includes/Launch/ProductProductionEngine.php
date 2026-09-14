<?php

declare(strict_types=1);

namespace DigiForge\Launch;

use DigiForge\Core\Settings;
use DigiForge\Database\Tables;
use DigiForge\DigitalFactory\Repository as DigitalRepository;
use DigiForge\ProductFactory\Repository as ProductRepository;
use DigiForge\Production\Repository as ProductionRepository;
use DigiForge\Security\Logger;
use WP_Error;

/** Builds the actual local digital product package after research/product approval. */
final class ProductProductionEngine
{
    /** @return array<string,mixed>|WP_Error */
    public function produce(int $productVersionId, string $idempotencyKey): array|WP_Error
    {
        if (! Settings::is_enabled('ai') || ! Settings::is_enabled('product_development')) {
            return $this->error('switch_disabled', 'AI and Product Development must be effectively enabled before product production can run.', 409);
        }

        $products = new ProductRepository();
        $version = $products->find('product_version', $productVersionId);
        if ($version === null) {
            return $this->error('not_found', 'Product version not found.', 404);
        }
        $product = $products->find('product', (int) $version['product_id']);
        if ($product === null) {
            return $this->error('invalid_parent', 'Product for this version was not found.', 409);
        }

        $versionNotes = $this->decode((string) ($version['notes'] ?? ''));
        $shop = sanitize_key((string) ($versionNotes['shop'] ?? 'digital'));
        if ($shop !== 'digital') {
            return $this->error('unsupported_channel', 'This production engine currently creates local digital products only. POD production remains separately gated.', 409);
        }

        $spec = is_array($versionNotes['spec'] ?? null) ? $versionNotes['spec'] : [];
        $brief = $this->productionPrompt((string) $product['name'], (string) ($product['description'] ?? ''), $spec);
        $ai = (new OpenAIClient())->develop($brief);
        if (is_wp_error($ai)) {
            return $ai;
        }
        $payload = is_array($ai['payload'] ?? null) ? $ai['payload'] : [];

        $artifactResult = (new ProductArtifactBuilder())->build(
            (int) $product['id'],
            $productVersionId,
            (string) $product['name'],
            $payload
        );
        if (is_wp_error($artifactResult)) {
            return $artifactResult;
        }

        $digital = new DigitalRepository();
        $digitalProduct = $digital->create('digital_product', [
            'name' => (string) $product['name'],
            'category' => 'digital_download',
            'product_id' => (int) $product['id'],
            'product_version_id' => $productVersionId,
        ], $idempotencyKey . '-digital-product');
        if (is_wp_error($digitalProduct)) {
            return $digitalProduct;
        }

        $production = new ProductionRepository();
        $plan = $production->createPlan([
            'product_version_id' => $productVersionId,
            'plan_key' => 'digital-launch-' . $productVersionId,
            'version_label' => 'Launch 1.0',
            'channel' => 'digital',
            'production_type' => 'generated_download_bundle',
            'requirements_version' => 'v1',
            'notes' => 'Generated locally by DigiForge. Human product approval is required before listing work.',
        ], $idempotencyKey . '-plan');
        if (is_wp_error($plan)) {
            return $plan;
        }

        $records = [];
        $files = is_array($artifactResult['files'] ?? null) ? $artifactResult['files'] : [];
        foreach ($files as $index => $file) {
            if (! is_array($file)) {
                continue;
            }
            $persisted = $this->persistArtifact(
                $digital,
                $production,
                $digitalProduct,
                $plan,
                $productVersionId,
                $file,
                $idempotencyKey . '-artifact-' . $index
            );
            if (is_wp_error($persisted)) {
                return $persisted;
            }
            $records[] = $persisted;
        }

        if ($records === []) {
            return $this->error('no_artifacts', 'No product artifacts were persisted.', 500);
        }

        $plan = $this->advanceProduction($production, 'plan', (int) $plan['id'], ['DRAFT' => 'VALIDATED', 'VALIDATED' => 'REVIEW_REQUIRED']);
        if (is_wp_error($plan)) {
            return $plan;
        }

        $bundle = $production->createBundle([
            'production_plan_id' => (int) $plan['id'],
            'bundle_key' => 'digital-launch-' . $productVersionId,
            'version_label' => 'Launch 1.0',
        ], $idempotencyKey . '-release-bundle');
        if (is_wp_error($bundle)) {
            return $bundle;
        }
        $bundle = $this->advanceProduction($production, 'bundle', (int) $bundle['id'], ['DRAFT' => 'VALIDATED', 'VALIDATED' => 'REVIEW_REQUIRED']);
        if (is_wp_error($bundle)) {
            return $bundle;
        }

        $digitalProduct = $this->advanceDigital($digital, (int) $digitalProduct['id'], [
            'DRAFT' => 'FILES_PENDING',
            'FILES_PENDING' => 'QA_PENDING',
            'QA_PENDING' => 'QA_PASSED',
            'QA_PASSED' => 'CONTENT_REVIEW',
            'CONTENT_REVIEW' => 'VISUAL_REVIEW',
        ]);
        if (is_wp_error($digitalProduct)) {
            return $digitalProduct;
        }

        Logger::audit('launch_product_production_completed', [
            'product_id' => (int) $product['id'],
            'product_version_id' => $productVersionId,
            'digital_product_id' => (int) $digitalProduct['id'],
            'production_plan_id' => (int) $plan['id'],
            'release_bundle_id' => (int) $bundle['id'],
            'artifact_count' => count($records),
            'response_id' => (string) ($ai['response_id'] ?? ''),
        ], 'product_version', (string) $productVersionId);

        return [
            'product' => $product,
            'product_version' => $version,
            'digital_product' => $digitalProduct,
            'production_plan' => $plan,
            'release_bundle' => $bundle,
            'artifacts' => $records,
            'artifact_summary' => $artifactResult,
            'ai' => [
                'model' => $ai['model'] ?? '',
                'response_id' => $ai['response_id'] ?? '',
                'usage' => $ai['usage'] ?? [],
            ],
            'next_action' => 'Review the completed product files and listing images, then approve or reject the product bundle. Etsy remains gated.',
        ];
    }

    /** @return array<string,mixed>|WP_Error */
    public function approve(int $productVersionId, string $decision): array|WP_Error
    {
        $decision = strtoupper(sanitize_key($decision));
        if (! in_array($decision, ['APPROVED', 'REJECTED'], true)) {
            return $this->error('validation', 'Product decision must be APPROVED or REJECTED.');
        }

        global $wpdb;
        $plan = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . Tables::production_plans() . ' WHERE product_version_id=%d ORDER BY id DESC LIMIT 1',
            $productVersionId
        ), ARRAY_A);
        $digitalProduct = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . Tables::digital_products() . ' WHERE product_version_id=%d ORDER BY id DESC LIMIT 1',
            $productVersionId
        ), ARRAY_A);
        if (! is_array($plan) || ! is_array($digitalProduct)) {
            return $this->error('not_found', 'Production review records were not found.', 404);
        }
        $bundle = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . Tables::release_bundles() . ' WHERE production_plan_id=%d ORDER BY id DESC LIMIT 1',
            (int) $plan['id']
        ), ARRAY_A);
        if (! is_array($bundle)) {
            return $this->error('not_found', 'Release bundle was not found.', 404);
        }

        $production = new ProductionRepository();
        if ($decision === 'REJECTED') {
            $planResult = $production->transition('plan', (int) $plan['id'], 'REJECTED');
            if (is_wp_error($planResult)) { return $planResult; }
            $bundleResult = $production->transition('bundle', (int) $bundle['id'], 'REJECTED');
            if (is_wp_error($bundleResult)) { return $bundleResult; }
            Logger::audit('launch_product_review_rejected', ['product_version_id' => $productVersionId], 'product_version', (string) $productVersionId);
            return ['decision' => 'REJECTED', 'production_plan' => $planResult, 'release_bundle' => $bundleResult];
        }

        $planResult = $production->transition('plan', (int) $plan['id'], 'APPROVED');
        if (is_wp_error($planResult)) { return $planResult; }
        $bundleResult = $production->transition('bundle', (int) $bundle['id'], 'APPROVED');
        if (is_wp_error($bundleResult)) { return $bundleResult; }
        $validatedBundle = $production->validateBundle((int) $bundle['id']);
        if (is_wp_error($validatedBundle)) { return $validatedBundle; }

        $digital = new DigitalRepository();
        $digitalResult = $digital->transition((int) $digitalProduct['id'], 'COMMERCIAL_REVIEW');
        if (is_wp_error($digitalResult)) { return $digitalResult; }

        Logger::audit('launch_product_review_approved', [
            'product_version_id' => $productVersionId,
            'release_bundle_id' => (int) $bundle['id'],
        ], 'product_version', (string) $productVersionId);

        return [
            'decision' => 'APPROVED',
            'production_plan' => $planResult,
            'release_bundle' => $validatedBundle,
            'digital_product' => $digitalResult,
            'next_action' => 'Build the Etsy listing package and run commercial, IP, policy and profitability review before any Etsy draft is created.',
        ];
    }

    /** @return array<string,mixed>|WP_Error */
    private function persistArtifact(
        DigitalRepository $digital,
        ProductionRepository $production,
        array $digitalProduct,
        array $plan,
        int $productVersionId,
        array $file,
        string $key
    ): array|WP_Error {
        $reference = sanitize_text_field((string) ($file['storage_reference'] ?? ''));
        $checksum = strtolower(sanitize_text_field((string) ($file['checksum_sha256'] ?? '')));
        $role = sanitize_key((string) ($file['role'] ?? 'asset'));
        $name = sanitize_text_field((string) ($file['name'] ?? basename($reference)));
        $mime = sanitize_text_field((string) ($file['mime_type'] ?? 'application/octet-stream'));
        $extension = sanitize_key((string) pathinfo($reference, PATHINFO_EXTENSION));
        if ($reference === '' || preg_match('/^[a-f0-9]{64}$/', $checksum) !== 1) {
            return $this->error('artifact_validation', 'Generated artifact metadata is incomplete.', 500);
        }

        $spec = $production->createSpec([
            'product_version_id' => $productVersionId,
            'digital_product_id' => (int) $digitalProduct['id'],
            'asset_key' => sanitize_key($role . '-' . basename($reference, '.' . $extension)),
            'asset_type' => $role,
            'purpose' => $name,
            'format' => $extension !== '' ? $extension : 'bin',
            'width_px' => absint($file['width_px'] ?? 0),
            'height_px' => absint($file['height_px'] ?? 0),
            'dpi' => 0,
            'color_space' => 'srgb',
            'orientation' => 'auto',
            'variant_key' => 'launch',
            'locale' => 'en',
            'content_requirements' => ['storage_reference' => $reference, 'checksum_sha256' => $checksum],
            'design_constraints' => ['source' => 'digiforge_generated'],
        ], $key . '-spec');
        if (is_wp_error($spec)) { return $spec; }
        $spec = $this->advanceProduction($production, 'spec', (int) $spec['id'], ['DRAFT' => 'SPECIFIED', 'SPECIFIED' => 'APPROVED']);
        if (is_wp_error($spec)) { return $spec; }

        $required = $role !== 'product_source';
        $linked = $production->linkAsset((int) $plan['id'], (int) $spec['id'], $required, 10);
        if (is_wp_error($linked)) { return $linked; }

        $revision = $production->addRevision([
            'asset_spec_id' => (int) $spec['id'],
            'revision_label' => 'Launch 1.0',
            'storage_reference' => $reference,
            'checksum_sha256' => $checksum,
            'mime_type' => $mime,
            'byte_size' => absint($file['byte_size'] ?? 0),
            'width_px' => absint($file['width_px'] ?? 0),
            'height_px' => absint($file['height_px'] ?? 0),
            'provenance' => ['generator' => 'DigiForge', 'role' => $role],
        ], $key . '-revision');
        if (is_wp_error($revision)) { return $revision; }

        $qa = $production->addQa([
            'target_type' => 'revision',
            'target_id' => (int) $revision['id'],
            'check_type' => 'checksum_integrity',
            'check_version' => 'v1',
            'status' => 'PASS',
            'details' => ['checksum_sha256' => $checksum, 'byte_size' => absint($file['byte_size'] ?? 0)],
        ], $key . '-qa');
        if (is_wp_error($qa)) { return $qa; }
        $revision = $this->advanceProduction($production, 'revision', (int) $revision['id'], ['PENDING_QA' => 'QA_PASSED', 'QA_PASSED' => 'APPROVED']);
        if (is_wp_error($revision)) { return $revision; }

        $digitalRecord = null;
        if (in_array($role, ['customer_file', 'product_source'], true)) {
            $digitalRecord = $digital->create('digital_file', [
                'name' => $name,
                'file_type' => $extension !== '' ? $extension : 'file',
                'mime_type' => $mime,
                'storage_reference' => $reference,
                'checksum_sha256' => $checksum,
                'status' => 'READY',
                'digital_product_id' => (int) $digitalProduct['id'],
                'product_version_id' => $productVersionId,
            ], $key . '-digital-file');
            if (is_wp_error($digitalRecord)) { return $digitalRecord; }
            $fileVersion = $digital->create('digital_file_version', [
                'version_label' => 'Launch 1.0',
                'digital_file_id' => (int) $digitalRecord['id'],
                'storage_reference' => $reference,
                'checksum_sha256' => $checksum,
                'byte_size' => absint($file['byte_size'] ?? 0),
                'status' => 'READY',
            ], $key . '-digital-file-version');
            if (is_wp_error($fileVersion)) { return $fileVersion; }
            $check = $digital->create('digital_download_check', [
                'digital_product_id' => (int) $digitalProduct['id'],
                'target_type' => 'file',
                'target_id' => (int) $digitalRecord['id'],
                'check_type' => 'checksum',
                'validation_result' => 'PASS',
                'review_status' => 'APPROVED',
                'details' => ['checksum_sha256' => $checksum],
            ], $key . '-digital-check');
            if (is_wp_error($check)) { return $check; }
        } elseif ($role === 'customer_package') {
            $digitalRecord = $digital->create('digital_package', [
                'name' => $name,
                'digital_product_id' => (int) $digitalProduct['id'],
                'product_version_id' => $productVersionId,
                'manifest' => ['storage_reference' => $reference],
                'checksum_sha256' => $checksum,
                'byte_size' => absint($file['byte_size'] ?? 0),
                'generation_status' => 'READY',
            ], $key . '-digital-package');
            if (is_wp_error($digitalRecord)) { return $digitalRecord; }
            $check = $digital->create('digital_download_check', [
                'digital_product_id' => (int) $digitalProduct['id'],
                'target_type' => 'package',
                'target_id' => (int) $digitalRecord['id'],
                'check_type' => 'package_generation',
                'validation_result' => 'PASS',
                'review_status' => 'APPROVED',
                'details' => ['checksum_sha256' => $checksum],
            ], $key . '-digital-check');
            if (is_wp_error($check)) { return $check; }
        }

        return [
            'role' => $role,
            'name' => $name,
            'storage_reference' => $reference,
            'asset_spec' => $spec,
            'asset_revision' => $revision,
            'digital_record' => $digitalRecord,
        ];
    }

    /** @return array<string,mixed>|WP_Error */
    private function advanceProduction(ProductionRepository $repo, string $entity, int $id, array $path): array|WP_Error
    {
        $current = null;
        foreach ($path as $from => $to) {
            $table = match ($entity) {
                'spec' => Tables::asset_specs(),
                'plan' => Tables::production_plans(),
                'revision' => Tables::asset_revisions(),
                'bundle' => Tables::release_bundles(),
                default => '',
            };
            if ($table === '') { return $this->error('validation', 'Unknown production entity.'); }
            global $wpdb;
            $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $table . ' WHERE id=%d', $id), ARRAY_A);
            if (! is_array($row)) { return $this->error('not_found', 'Production entity not found.', 404); }
            if ((string) $row['state'] !== $from) {
                $current = $row;
                continue;
            }
            $current = $repo->transition($entity, $id, $to);
            if (is_wp_error($current)) { return $current; }
        }
        return is_array($current) ? $current : $this->error('not_found', 'Production entity not found.', 404);
    }

    /** @return array<string,mixed>|WP_Error */
    private function advanceDigital(DigitalRepository $repo, int $id, array $path): array|WP_Error
    {
        $current = $repo->find('digital_product', $id);
        if ($current === null) { return $this->error('not_found', 'Digital product not found.', 404); }
        foreach ($path as $from => $to) {
            if ((string) ($current['state'] ?? '') !== $from) {
                continue;
            }
            $current = $repo->transition($id, $to);
            if (is_wp_error($current)) { return $current; }
        }
        return $current;
    }

    /** @param array<string,mixed> $spec */
    private function productionPrompt(string $name, string $description, array $spec): string
    {
        $specJson = wp_json_encode($spec, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return "You are DigiForge Digital Product Production. Return ONLY one JSON object, no markdown.\n"
            . "Create the actual customer-facing content for the approved Etsy digital product.\nProduct: {$name}\nDescription: {$description}\nApproved specification: {$specJson}\n"
            . 'Required keys: pages (8-14 page objects; each with title, subtitle, sections array; each section has heading, body, bullets), customer_instructions, license_text, listing_images (6 objects with headline and subheadline). '
            . 'Write polished original copy suitable for US and European buyers. Keep destination-specific facts as clearly editable placeholders instead of inventing travel facts. Avoid trademarks, copyrighted character references, medical/legal claims, and unsupported factual claims. The content must be complete enough to sell as a finished download after human visual review.';
    }

    /** @return array<string,mixed> */
    private function decode(string $json): array
    {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function error(string $code, string $message, int $status = 400): WP_Error
    {
        return new WP_Error('digiforge_production_' . $code, __($message, 'digiforge'), ['status' => $status]);
    }
}
