<?php

declare(strict_types=1);

namespace DigiForge\ProductFactory;

use DigiForge\Launch\ExecutionEngine;
use DigiForge\Launch\OpenAIClient;
use DigiForge\Production\Repository as ProductionRepository;
use DigiForge\Security\Logger;
use WP_Error;

/**
 * U3 approved-opportunity -> actual local assets -> QA -> Product Review orchestration.
 *
 * It deliberately does not call Etsy, Printify, Gelato, order, fulfillment or tax APIs,
 * and it never performs the Product Approval human gate on behalf of the operator.
 */
final class Orchestrator
{
    public function __construct(
        private ?ProductionRepository $production = null,
        private ?LocalAssetProducer $producer = null,
        private ?AutomatedQa $qa = null,
        private ?SemanticQa $semanticQa = null
    ) {
        $this->production ??= new ProductionRepository();
        $this->producer ??= new LocalAssetProducer();
        $this->qa ??= new AutomatedQa();
        $this->semanticQa ??= new SemanticQa();
    }

    /** @return array<string,mixed>|WP_Error */
    public function build(int $candidateId, array $input, string $key): array|WP_Error
    {
        $shop = sanitize_key((string) ($input['shop'] ?? 'digital'));
        if (! in_array($shop, ['digital', 'goods'], true)) {
            return $this->error('validation', 'shop must be digital or goods.');
        }

        // Reuse the existing approval-gated development path. A PENDING candidate stops here.
        $developed = (new ExecutionEngine())->develop($candidateId, ['shop' => $shop], $key . '-development');
        if (is_wp_error($developed)) {
            return $developed;
        }
        $version = (array) ($developed['product_version'] ?? []);
        $product = (array) ($developed['product'] ?? []);
        $productVersionId = (int) ($version['id'] ?? 0);
        if ($productVersionId < 1) {
            return $this->error('product_version', 'Development did not create a usable product version.', 500);
        }

        $spec = is_array($developed['spec'] ?? null) ? $developed['spec'] : [];
        $manifestAi = (new OpenAIClient())->develop($this->productionPrompt($developed, $shop));
        if (is_wp_error($manifestAi)) {
            return $manifestAi;
        }
        $manifest = is_array($manifestAi['payload'] ?? null) ? $manifestAi['payload'] : [];
        $productAssets = $this->assetDefinitions($manifest['product_assets'] ?? [], false);
        $marketingAssets = $this->assetDefinitions($manifest['marketing_assets'] ?? [], true);
        if ($productAssets === [] || $marketingAssets === []) {
            return $this->error('production_manifest', 'AI production manifest must include product and marketing assets.', 502);
        }
        $unique = $this->uniqueDefinitions(array_merge($productAssets, $marketingAssets));
        if (is_wp_error($unique)) {
            return $unique;
        }

        $plan = $this->production->createPlan([
            'product_version_id' => $productVersionId,
            'plan_key' => 'u3-launch',
            'version_label' => 'U3 Launch 1.0',
            'channel' => $shop === 'digital' ? 'digital' : 'pod',
            'production_type' => $shop === 'digital' ? 'automated_digital_product' : 'artwork_preproduction',
            'requirements_version' => 'u3-v1',
            'notes' => 'Automated local production. External publishing and fulfillment remain disabled.',
        ], $key . '-plan');
        if (is_wp_error($plan)) { return $plan; }

        $outputs = [];
        $qaPassed = true;
        $sequence = 1;
        foreach ([['group' => 'product', 'assets' => $productAssets], ['group' => 'marketing', 'assets' => $marketingAssets]] as $group) {
            foreach ($group['assets'] as $definition) {
                $result = $this->produceOne(
                    $productVersionId,
                    (int) $plan['id'],
                    (string) $group['group'],
                    $definition,
                    $key,
                    $sequence++
                );
                if (is_wp_error($result)) { return $result; }
                $outputs[] = $result;
                $qaPassed = $qaPassed && ($result['qa_passed'] ?? false) === true;
            }
        }

        $customerAssets = array_values(array_filter($outputs, static fn(array $asset): bool => ($asset['group'] ?? '') === 'product'));
        $packageInput = array_map(static fn(array $asset): array => (array) ($asset['file'] ?? []), $customerAssets);
        $package = $this->producer->package($productVersionId, $packageInput, 'digicraftify-product-' . $productVersionId . '.zip');
        if (is_wp_error($package)) { return $package; }
        $packageResult = $this->registerPackage($productVersionId, (int) $plan['id'], $package, $key, $sequence);
        if (is_wp_error($packageResult)) { return $packageResult; }
        $outputs[] = $packageResult;
        $qaPassed = $qaPassed && ($packageResult['qa_passed'] ?? false) === true;

        $semantic = $this->semanticQa->inspect($spec, $outputs);
        if (is_wp_error($semantic)) { return $semantic; }
        foreach ($semantic['checks'] as $index => $check) {
            $record = $this->production->addQa([
                'target_type' => 'plan',
                'target_id' => (int) $plan['id'],
                'check_type' => 'semantic_' . (string) $check['name'],
                'status' => ($check['passed'] ?? false) === true ? 'PASS' : 'FAIL',
                'details' => (array) ($check['details'] ?? []),
            ], $key . '-semantic-qa-' . $index);
            if (is_wp_error($record)) { return $record; }
        }
        $qaPassed = $qaPassed && $semantic['passed'];

        $planState = $this->production->transition('plan', (int) $plan['id'], 'VALIDATED');
        if (is_wp_error($planState)) { return $planState; }

        // Any deterministic or semantic QA failure is stopped before Product Approval.
        if (! $qaPassed) {
            Logger::audit('u3_product_factory_qa_failed', [
                'candidate_id' => $candidateId,
                'product_id' => (int) ($product['id'] ?? 0),
                'product_version_id' => $productVersionId,
                'production_plan_id' => (int) $plan['id'],
                'asset_count' => count($outputs),
                'semantic_qa_passed' => $semantic['passed'],
                'workflow_status' => Workflow::QA_FAILED,
                'external_actions' => false,
            ], 'product_version', (string) $productVersionId);

            return [
                'candidate_id' => $candidateId,
                'shop' => $shop,
                'product' => $product,
                'product_version' => $version,
                'specification' => $spec,
                'production_plan' => $planState,
                'release_bundle' => null,
                'assets' => $outputs,
                'semantic_qa' => $semantic,
                'qa_passed' => false,
                'workflow_status' => Workflow::QA_FAILED,
                'product_approval_required' => false,
                'external_actions_performed' => false,
                'next_action' => 'Resolve deterministic or semantic QA failures and create corrected production revisions before Product Approval.',
                'ai' => [
                    'production_model' => $manifestAi['model'] ?? '',
                    'production_response_id' => $manifestAi['response_id'] ?? '',
                    'production_usage' => $manifestAi['usage'] ?? [],
                    'qa_model' => $semantic['model'],
                    'qa_response_id' => $semantic['response_id'],
                    'qa_usage' => $semantic['usage'],
                ],
            ];
        }

        $planState = $this->production->transition('plan', (int) $plan['id'], 'REVIEW_REQUIRED');
        if (is_wp_error($planState)) { return $planState; }

        $bundle = $this->production->createBundle([
            'production_plan_id' => (int) $plan['id'],
            'bundle_key' => 'u3-product-review',
            'version_label' => 'Launch 1.0',
        ], $key . '-bundle');
        if (is_wp_error($bundle)) { return $bundle; }
        $bundleState = $this->production->transition('bundle', (int) $bundle['id'], 'VALIDATED');
        if (is_wp_error($bundleState)) { return $bundleState; }
        $bundleState = $this->production->transition('bundle', (int) $bundle['id'], 'REVIEW_REQUIRED');
        if (is_wp_error($bundleState)) { return $bundleState; }

        Logger::audit('u3_product_factory_completed', [
            'candidate_id' => $candidateId,
            'product_id' => (int) ($product['id'] ?? 0),
            'product_version_id' => $productVersionId,
            'production_plan_id' => (int) $plan['id'],
            'asset_count' => count($outputs),
            'qa_passed' => true,
            'semantic_qa_passed' => true,
            'workflow_status' => Workflow::PRODUCT_REVIEW_REQUIRED,
            'external_actions' => false,
        ], 'product_version', (string) $productVersionId);

        return [
            'candidate_id' => $candidateId,
            'shop' => $shop,
            'product' => $product,
            'product_version' => $version,
            'specification' => $spec,
            'production_plan' => $planState,
            'release_bundle' => $bundleState,
            'assets' => $outputs,
            'semantic_qa' => $semantic,
            'qa_passed' => true,
            'workflow_status' => Workflow::PRODUCT_REVIEW_REQUIRED,
            'product_approval_required' => true,
            'external_actions_performed' => false,
            'next_action' => 'Review the finished product in Approval Inbox. Listing production remains blocked until explicit Product Approval.',
            'ai' => [
                'production_model' => $manifestAi['model'] ?? '',
                'production_response_id' => $manifestAi['response_id'] ?? '',
                'production_usage' => $manifestAi['usage'] ?? [],
                'qa_model' => $semantic['model'],
                'qa_response_id' => $semantic['response_id'],
                'qa_usage' => $semantic['usage'],
            ],
        ];
    }

    /** @param array<string,mixed> $definition @return array<string,mixed>|WP_Error */
    private function produceOne(int $versionId, int $planId, string $group, array $definition, string $key, int $sequence): array|WP_Error
    {
        $assetKey = sanitize_key((string) ($definition['asset_key'] ?? ($group . '-' . $sequence)));
        $format = strtolower(sanitize_key((string) ($definition['format'] ?? 'txt')));
        $filename = sanitize_file_name((string) ($definition['filename'] ?? ($assetKey . '.' . $format)));
        $purpose = sanitize_text_field((string) ($definition['purpose'] ?? $group));
        $spec = $this->production->createSpec([
            'product_version_id' => $versionId,
            'asset_key' => $assetKey,
            'asset_type' => $group === 'marketing' ? 'marketing_asset' : 'product_asset',
            'purpose' => $purpose,
            'format' => $format,
            'content_requirements' => ['filename' => $filename, 'group' => $group],
            'design_constraints' => ['generated_locally' => true, 'external_publish' => false],
        ], $key . '-spec-' . $sequence);
        if (is_wp_error($spec)) { return $spec; }
        $linked = $this->production->linkAsset($planId, (int) $spec['id'], true, $sequence);
        if (is_wp_error($linked)) { return $linked; }
        $specified = $this->production->transition('spec', (int) $spec['id'], 'SPECIFIED');
        if (is_wp_error($specified)) { return $specified; }

        $file = $this->producer->write($versionId, $filename, $format, $definition['content'] ?? ($definition['pages'] ?? ''));
        if (is_wp_error($file)) { return $file; }
        $revision = $this->production->addRevision([
            'asset_spec_id' => (int) $spec['id'],
            'revision_label' => 'U3-1.0',
            'storage_reference' => (string) $file['storage_reference'],
            'checksum_sha256' => (string) $file['checksum_sha256'],
            'mime_type' => (string) $file['mime_type'],
            'byte_size' => (int) $file['byte_size'],
            'provenance' => ['generator' => 'digiforge_u3', 'group' => $group, 'external_publish' => false],
        ], $key . '-revision-' . $sequence);
        if (is_wp_error($revision)) { return $revision; }

        $qa = $this->qa->inspect($file);
        foreach ($qa['checks'] as $index => $check) {
            $record = $this->production->addQa([
                'target_type' => 'revision',
                'target_id' => (int) $revision['id'],
                'check_type' => (string) $check['name'],
                'status' => ($check['passed'] ?? false) === true ? 'PASS' : 'FAIL',
                'details' => (array) ($check['details'] ?? []),
            ], $key . '-qa-' . $sequence . '-' . $index);
            if (is_wp_error($record)) { return $record; }
        }
        $revisionState = $this->production->transition('revision', (int) $revision['id'], $qa['passed'] ? 'QA_PASSED' : 'QA_FAILED');
        if (is_wp_error($revisionState)) { return $revisionState; }
        return ['group' => $group, 'spec' => $specified, 'revision' => $revisionState, 'file' => $file, 'qa' => $qa['checks'], 'qa_passed' => $qa['passed']];
    }

    /** @return array<string,mixed>|WP_Error */
    private function registerPackage(int $versionId, int $planId, array $file, string $key, int $sequence): array|WP_Error
    {
        $spec = $this->production->createSpec([
            'product_version_id' => $versionId,
            'asset_key' => 'customer-package',
            'asset_type' => 'product_package',
            'purpose' => 'Customer delivery ZIP',
            'format' => 'zip',
            'content_requirements' => ['filename' => (string) $file['filename']],
            'design_constraints' => ['generated_locally' => true],
        ], $key . '-package-spec');
        if (is_wp_error($spec)) { return $spec; }
        $linked = $this->production->linkAsset($planId, (int) $spec['id'], true, $sequence);
        if (is_wp_error($linked)) { return $linked; }
        $specified = $this->production->transition('spec', (int) $spec['id'], 'SPECIFIED');
        if (is_wp_error($specified)) { return $specified; }
        $revision = $this->production->addRevision([
            'asset_spec_id' => (int) $spec['id'],
            'revision_label' => 'U3-1.0',
            'storage_reference' => (string) $file['storage_reference'],
            'checksum_sha256' => (string) $file['checksum_sha256'],
            'mime_type' => (string) $file['mime_type'],
            'byte_size' => (int) $file['byte_size'],
            'provenance' => ['generator' => 'digiforge_u3_package'],
        ], $key . '-package-revision');
        if (is_wp_error($revision)) { return $revision; }
        $qa = $this->qa->inspect($file);
        foreach ($qa['checks'] as $index => $check) {
            $record = $this->production->addQa([
                'target_type' => 'revision',
                'target_id' => (int) $revision['id'],
                'check_type' => (string) $check['name'],
                'status' => ($check['passed'] ?? false) === true ? 'PASS' : 'FAIL',
                'details' => (array) ($check['details'] ?? []),
            ], $key . '-package-qa-' . $index);
            if (is_wp_error($record)) { return $record; }
        }
        $revisionState = $this->production->transition('revision', (int) $revision['id'], $qa['passed'] ? 'QA_PASSED' : 'QA_FAILED');
        if (is_wp_error($revisionState)) { return $revisionState; }
        return ['group' => 'product', 'spec' => $specified, 'revision' => $revisionState, 'file' => $file, 'qa' => $qa['checks'], 'qa_passed' => $qa['passed']];
    }

    /** @param mixed $value @return list<array<string,mixed>> */
    private function assetDefinitions($value, bool $marketing): array
    {
        if (! is_array($value)) { return []; }
        $out = [];
        foreach (array_slice($value, 0, $marketing ? 6 : 10) as $asset) {
            if (! is_array($asset)) { continue; }
            $format = strtolower(sanitize_key((string) ($asset['format'] ?? '')));
            if ($marketing && $format !== 'svg') { continue; }
            if (! $marketing && ! in_array($format, ['txt','html','csv','json','svg','pdf'], true)) { continue; }
            if (empty($asset['content']) && empty($asset['pages'])) { continue; }
            $asset['asset_key'] = sanitize_key((string) ($asset['asset_key'] ?? ''));
            $asset['filename'] = sanitize_file_name((string) ($asset['filename'] ?? ''));
            if ($asset['asset_key'] === '' || $asset['filename'] === '') { continue; }
            $out[] = $asset;
        }
        return $out;
    }

    /** @param list<array<string,mixed>> $definitions @return true|WP_Error */
    private function uniqueDefinitions(array $definitions): true|WP_Error
    {
        $keys = [];
        $files = [];
        foreach ($definitions as $definition) {
            $key = (string) ($definition['asset_key'] ?? '');
            $file = strtolower((string) ($definition['filename'] ?? ''));
            if (isset($keys[$key]) || isset($files[$file])) {
                return $this->error('duplicate_asset', 'Generated asset keys and filenames must be unique.', 502);
            }
            $keys[$key] = true;
            $files[$file] = true;
        }
        return true;
    }

    /** @param array<string,mixed> $developed */
    private function productionPrompt(array $developed, string $shop): string
    {
        $spec = wp_json_encode($developed['spec'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $name = sanitize_text_field((string) (($developed['product']['name'] ?? '') ?: 'DigiForge product'));
        $channel = $shop === 'digital' ? 'digital download' : 'POD artwork preproduction';
        return "You are DigiForge U3 Production. Return ONLY one compact JSON object, no markdown.\n"
            . "Product: {$name}. Channel: {$channel}. Approved specification: {$spec}\n"
            . "Create ACTUAL customer/product file contents and separate Etsy marketing graphics. Do not merely describe files. "
            . "Required keys: product_assets and marketing_assets. product_assets is 2-6 objects with unique asset_key, unique filename, format, purpose and either content or pages. "
            . "Allowed product formats: pdf, txt, csv, json, html, svg. For pdf, pages must be an array of complete page text. "
            . "marketing_assets is 3-6 SVG objects with unique asset_key, unique filename ending .svg, format='svg', purpose and content containing a complete self-contained SVG. "
            . "Marketing assets must never be labeled or treated as customer production files. Keep copy accurate to the approved specification. "
            . "Do not include secrets, external scripts, remote image URLs, trademarked character art, or unsafe/prohibited content.";
    }

    private function error(string $code, string $message, int $status = 400): WP_Error
    {
        return new WP_Error('digiforge_u3_' . $code, __($message, 'digiforge'), ['status' => $status]);
    }
}
