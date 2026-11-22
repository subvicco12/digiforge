<?php

declare(strict_types=1);

namespace DigiForge\POD;

use DigiForge\Listings\Validator;
use DigiForge\Research\OpportunityDesignRepository;
use WP_Error;

/** Append-only local review versions. No provider, order, publication or transport dependency. */
final class ProductPackageRepository
{
    private const MAX_RECORD = 196608;

    /** Resolve confirmed Day 3 commercial criteria on the server, never caller approval flags. */
    public function prepareCommercial(string $shop, string $designHash, array $evidence, ?string $parent = null): array|WP_Error
    {
        $handoff = $this->commercial($shop, $designHash);
        if ($handoff instanceof WP_Error) {
            return $handoff;
        }
        if (isset($evidence['commercial_design_hash']) && $evidence['commercial_design_hash'] !== $designHash) {
            return self::error('Commercial design hash conflict.');
        }
        $evidence['commercial_design_hash'] = $designHash;
        return $this->prepare($shop, $handoff['artwork']['version_hash'], $evidence, $parent);
    }

    public function prepare(string $shop, string $artworkHash, array $evidence, ?string $parent = null): array|WP_Error
    {
        $package = ApprovedDesignListingPackage::fromApprovedAssetReference($shop, $artworkHash, $evidence);
        if ($package instanceof WP_Error) {
            return $package;
        }
        $commercial = null;
        if (isset($evidence['commercial_design_hash'])) {
            $handoff = $this->commercial($shop, $evidence['commercial_design_hash']);
            if ($handoff instanceof WP_Error) {
                return $handoff;
            }
            if ($handoff['artwork']['version_hash'] !== $artworkHash) {
                return self::error('Commercial design and artwork approval differ.');
            }
            $commercial = $handoff['design_metadata'];
            if (($commercial['geometry']['draft_reference'] ?? null) !== $package['provider']['product_id']) {
                return self::error('Commercial profile and target product differ.');
            }
        }
        try {
            $specification = ProductPackageSpecification::normalize(
                $evidence['specification'] ?? [],
                $package['source_approval']['record']['input']['artwork']['native_layers'],
                $commercial['specification']['layout']['native_regions'] ?? []
            );
        } catch (\Throwable) {
            return self::error('Valid text/photo/shipping specification required.');
        }
        if ($parent !== null) {
            $previous = $this->read($shop, $parent);
            if ($previous instanceof WP_Error) {
                return $previous;
            }
            if (
                $previous['record']['package']['fixture_id'] !== $package['fixture_id']
                || $previous['record']['package']['environment'] !== $package['environment']
            ) {
                return self::error('Parent fixture or environment conflict.');
            }
        }
        $record = ['schema' => 'held-product-package-v1', 'shop_key' => $shop, 'artwork_version_hash' => $artworkHash,
            'parent_hash' => $parent, 'package' => $package, 'evidence' => $evidence, 'specification' => $specification,
            'commercial_design_metadata' => $commercial,
            'created_by' => get_current_user_id(), 'created_at' => gmdate('c'),
            'release_hold' => true, 'external_execution_authorized' => false];
        $raw = Validator::canonicalJson($record);
        if (strlen($raw) > self::MAX_RECORD) {
            return self::error('Package review record exceeds its bound.');
        }
        $hash = hash('sha256', $raw);
        if (!add_option($this->name($hash), $raw, '', false)) {
            return self::error('Version exists or write uncertain; inspect exact hash.');
        }
        return $this->read($shop, $hash);
    }

    public function read(string $shop, string $hash): array|WP_Error
    {
        if (!$this->access($shop)) {
            return self::error('Authenticated personalized-POD reviewer required.', 403);
        }
        if (!self::hash($hash)) {
            return self::error('Exact package version hash required.');
        }
        $raw = $this->stored($this->name($hash));
        if (!is_string($raw) || strlen($raw) > self::MAX_RECORD || !hash_equals($hash, hash('sha256', $raw))) {
            return self::error('Package version missing, corrupt or unavailable.');
        }
        $record = json_decode($raw, true, 32);
        if (
            !is_array($record) || ($record['schema'] ?? null) !== 'held-product-package-v1'
            || ($record['shop_key'] ?? null) !== $shop || ($record['release_hold'] ?? null) !== true
            || ($record['external_execution_authorized'] ?? null) !== false
            || !is_int($record['created_by'] ?? null) || $record['created_by'] < 1
            || !is_array($record['evidence'] ?? null) || !is_array($record['package'] ?? null)
            || !is_string($record['artwork_version_hash'] ?? null)
        ) {
            return self::error('Stored package schema conflict.');
        }
        // Resolve current durable approval and every source byte again. Stored JSON is not authority.
        $current = ApprovedDesignListingPackage::fromApprovedAssetReference($shop, $record['artwork_version_hash'], $record['evidence']);
        if ($current instanceof WP_Error) {
            return $current;
        }
        if (Validator::canonicalJson($current) !== Validator::canonicalJson($record['package'])) {
            return self::error('Approved artwork, provider or package evidence changed.');
        }
        $commercial = null;
        if (isset($record['evidence']['commercial_design_hash'])) {
            $handoff = $this->commercial($shop, $record['evidence']['commercial_design_hash']);
            if ($handoff instanceof WP_Error) {
                return $handoff;
            }
            if ($handoff['artwork']['version_hash'] !== $record['artwork_version_hash']) {
                return self::error('Commercial artwork source conflict.');
            }
            $commercial = $handoff['design_metadata'];
            if (($commercial['geometry']['draft_reference'] ?? null) !== $current['provider']['product_id']) {
                return self::error('Commercial profile and target product differ.');
            }
        }
        if (Validator::canonicalJson($commercial ?? []) !== Validator::canonicalJson($record['commercial_design_metadata'] ?? [])) {
            return self::error('Commercial design evidence changed.');
        }
        try {
            $specification = ProductPackageSpecification::normalize(
                $record['evidence']['specification'] ?? [],
                $current['source_approval']['record']['input']['artwork']['native_layers'],
                $commercial['specification']['layout']['native_regions'] ?? []
            );
            if (Validator::canonicalJson($specification) !== Validator::canonicalJson($record['specification'] ?? [])) {
                return self::error('Native/shipping specification changed.');
            }
        } catch (\Throwable) {
            return self::error('Native/shipping specification invalid.');
        }
        $decisionRaw = $this->stored($this->name($hash) . '_decision');
        if ($decisionRaw instanceof WP_Error) {
            return $decisionRaw;
        }
        $decision = $decisionRaw === null ? null : json_decode($decisionRaw, true);
        if (
            $decisionRaw !== null && (!is_string($decisionRaw) || strlen($decisionRaw) > 16384
            || !is_array($decision) || ($decision['evidence_hash'] ?? null) !== $hash
            || !is_int($decision['approved_by'] ?? null) || $decision['approved_by'] < 1
            || !in_array($decision['decision'] ?? null, ['APPROVE','REJECT'], true)
            || ($decision['state'] ?? null) !== ($decision['decision'] === 'APPROVE' ? 'HUMAN_APPROVED' : 'HUMAN_REJECTED')
            || ($decision['publishing_enabled'] ?? null) !== false
            || ($decision['order_execution_enabled'] ?? null) !== false)
        ) {
            return self::error('Package decision evidence corrupt.');
        }
        return ['hash' => $hash, 'record' => $record, 'decision' => $decision];
    }

    public function decide(string $shop, string $hash, string $decision, string $note): array|WP_Error
    {
        $version = $this->read($shop, $hash);
        if ($version instanceof WP_Error) {
            return $version;
        }
        if ($version['decision'] !== null) {
            return self::error('Decision immutable; prepare a new version.');
        }
        if (trim($note) === '' || strlen($note) > 2000 || preg_match('//u', $note) !== 1) {
            return self::error('Bounded human review note required.');
        }
        $result = ApprovalRecord::decide(['state' => 'READY_FOR_HUMAN_APPROVAL', 'evidence_hash' => $hash,
            'publishing_enabled' => false, 'order_execution_enabled' => false], $decision, get_current_user_id(), $note);
        if ($result instanceof WP_Error) {
            return $result;
        }
        if (!add_option($this->name($hash) . '_decision', Validator::canonicalJson($result), '', false)) {
            return self::error('Decision exists or persistence uncertain; inspect before retry.');
        }
        $saved = $this->read($shop, $hash);
        return is_array($saved) && Validator::canonicalJson($saved['decision']) === Validator::canonicalJson($result)
            ? $saved : self::error('Decision confirmation uncertain; reconcile before retry.');
    }

    public function history(string $shop, string $hash): array|WP_Error
    {
        $history = [];
        $seen = [];
        while ($hash !== null) {
            if (count($history) >= 50 || isset($seen[$hash])) {
                return self::error('History bound or cycle.');
            }
            $seen[$hash] = true;
            $v = $this->read($shop, $hash);
            if ($v instanceof WP_Error) {
                return $v;
            }
            $history[] = ['hash' => $hash, 'artwork_version_hash' => $v['record']['artwork_version_hash'],
                'created_by' => $v['record']['created_by'], 'created_at' => $v['record']['created_at'], 'decision' => $v['decision']];
            $hash = $v['record']['parent_hash'];
        }
        return $history;
    }

    public function export(string $shop, string $hash): string|WP_Error
    {
        $v = $this->read($shop, $hash);
        if ($v instanceof WP_Error) {
            return $v;
        }
        if (($v['decision']['state'] ?? null) !== 'HUMAN_APPROVED') {
            return self::error('Exact package human approval required for export.');
        }
        $p = $v['record']['package'];
        // Keep the original assembly snapshot unchanged; the separate exact-hash decision resolves only local package review.
        $export = ['schema' => 'printify-native-held-review-export-v1', 'shop_key' => $shop,
            'package_hash' => $hash, 'package' => $p, 'approval' => $v['decision'], 'specification' => $v['record']['specification'],
            'commercial_design_metadata' => $v['record']['commercial_design_metadata'],
            'commercial_design_review_confirmed' => $v['record']['commercial_design_metadata'] !== null,
            'remaining_gates' => array_values(array_unique(array_merge(
                array_diff($p['blockers'], ['OWNER_PACKAGE_APPROVAL_REQUIRED']),
                $v['record']['specification']['blockers']
            ))),
            'commercial_approved' => false, 'supplier_certified' => false, 'release_hold' => true,
            'external_execution_authorized' => false, 'external_execution_performed' => false,
            'handoff' => 'MANUAL_PRINTIFY_NATIVE_AFTER_SEPARATE_EXACT_LIVE_AUTHORIZATION'];
        if (!empty($v['record']['commercial_design_metadata']['raster_references'])) {
            $export['remaining_gates'][] = 'SUPPLEMENTAL_RASTER_COMPOSITION_REVIEW_REQUIRED';
        }
        try {
            Validator::structured($export);
        } catch (\InvalidArgumentException) {
            return self::error('Held export including sidecar metadata exceeds the 65536-byte budget.');
        }
        return Validator::canonicalJson($export);
    }

    /** Attachment bytes for an authenticated caller only; never an inline SVG/public URL. */
    public function artwork(string $shop, string $hash): array|WP_Error
    {
        $v = $this->read($shop, $hash);
        if ($v instanceof WP_Error) {
            return $v;
        }
        $a = (new OpportunityDesignRepository())->read($shop, $v['record']['artwork_version_hash']);
        if ($a instanceof WP_Error) {
            return $a;
        }
        $svg = $a['record']['input']['artwork']['svg'];
        $expected = $v['record']['package']['design']['asset']['sha256'];
        if (!is_string($svg) || strlen($svg) > 262144 || !hash_equals($expected, hash('sha256', $svg))) {
            return self::error('Artwork checksum mismatch.');
        }
        return ['mime' => 'image/svg+xml', 'bytes' => $svg, 'sha256' => $expected, 'attachment_only' => true,
            'external_execution_authorized' => false];
    }

    /** Record a human-supplied readback only; this never calls Etsy or Printify. */
    public function reconcile(string $shop, string $hash, array $receipt): array|WP_Error
    {
        $v = $this->read($shop, $hash);
        if ($v instanceof WP_Error) {
            return $v;
        }
        if (($v['decision']['state'] ?? null) !== 'HUMAN_APPROVED') {
            return self::error('Approved exact package required before reconciliation.');
        }
        try {
            Validator::structured($receipt);
            $keys = ['printify_product_id','etsy_listing_id','etsy_state','source_reference','source_sha256',
                'observed_at','shipping_profile_id','processing_profile_id'];
            if (array_diff(array_keys($receipt), $keys) || array_diff($keys, array_keys($receipt))) {
                throw new \InvalidArgumentException();
            }
            $p = $v['record']['package'];
            if (
                $receipt['printify_product_id'] !== $p['provider']['product_id']
                || !in_array($receipt['etsy_state'], ['Draft','Inactive','UNKNOWN'], true)
                || !is_string($receipt['etsy_listing_id']) || !preg_match('/^[1-9][0-9]{0,24}$/D', $receipt['etsy_listing_id'])
                || ($p['reconciliation']['etsy_listing_id'] !== null && $p['reconciliation']['etsy_listing_id'] !== $receipt['etsy_listing_id'])
            ) {
                throw new \InvalidArgumentException();
            }
            foreach (['shipping_profile_id','processing_profile_id'] as $key) {
                if ($receipt[$key] !== null && (!is_string($receipt[$key]) || !preg_match('/^[1-9][0-9]{0,24}$/D', $receipt[$key]))) {
                    throw new \InvalidArgumentException();
                }
            }
            if (
                !is_string($receipt['source_reference']) || strlen($receipt['source_reference']) > 512
                || !preg_match('#^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_.-]+)+$#D', $receipt['source_reference'])
                || str_contains($receipt['source_reference'], '..') || !is_string($receipt['source_sha256'])
                || !self::hash($receipt['source_sha256']) || !is_string($receipt['observed_at'])
            ) {
                throw new \InvalidArgumentException();
            }
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $receipt['observed_at'], new \DateTimeZone('UTC'));
            if (!$date || $date->format('Y-m-d\TH:i:s\Z') !== $receipt['observed_at'] || $date->getTimestamp() > time()) {
                throw new \InvalidArgumentException();
            }
        } catch (\Throwable) {
            return self::error('Exact scoped non-active manual readback evidence required.');
        }
        $record = ['schema' => 'manual-package-reconciliation-v1','shop_key' => $shop,'package_hash' => $hash,
            'receipt' => $receipt,'created_by' => get_current_user_id(),'created_at' => gmdate('c'),
            'provenance' => 'MANUAL_REPORTED_UNVERIFIED','shipping_processing_approved' => false,
            'shared_profile_effects' => 'REQUIRES_OWNER_REVIEW','release_hold' => true,'external_execution_authorized' => false];
        $raw = Validator::canonicalJson($record);
        $receiptHash = hash('sha256', $raw);
        if (!add_option('digiforge_package_reconciliation_' . $receiptHash, $raw, '', false)) {
            return self::error('Receipt exists or write uncertain.');
        }
        return $this->reconciliation($shop, $receiptHash);
    }

    public function reconciliation(string $shop, string $hash): array|WP_Error
    {
        if (!$this->access($shop)) {
            return self::error('Authenticated reviewer required.', 403);
        }
        if (!self::hash($hash)) {
            return self::error('Exact reconciliation hash required.');
        }
        $raw = $this->stored('digiforge_package_reconciliation_' . $hash);
        if (!is_string($raw) || strlen($raw) > 8192 || !hash_equals($hash, hash('sha256', $raw))) {
            return self::error('Receipt evidence missing or corrupt.');
        }
        $record = json_decode($raw, true, 16);
        if (
            !is_array($record) || ($record['shop_key'] ?? null) !== $shop || !is_string($record['package_hash'] ?? null)
            || ($record['schema'] ?? null) !== 'manual-package-reconciliation-v1' || ($record['release_hold'] ?? null) !== true
            || ($record['external_execution_authorized'] ?? null) !== false
        ) {
            return self::error('Receipt scope or schema conflict.');
        }
        $v = $this->read($shop, $record['package_hash']);
        if ($v instanceof WP_Error) {
            return $v;
        }
        if (($v['decision']['state'] ?? null) !== 'HUMAN_APPROVED') {
            return self::error('Current approved package evidence required.');
        }
        return ['hash' => $hash,'record' => $record];
    }

    private function access(string $shop): bool
    {
        return \DigiForge\Security\PersonalizedPodAccess::allows() && $shop === 'personalized_pod' && get_current_user_id() > 0
            && current_user_can('manage_digiforge_pod') && current_user_can('manage_digiforge_research');
    }

    private function commercial(string $shop, mixed $hash): array|WP_Error
    {
        if (!$this->access($shop)) {
            return self::error('Authenticated commercial reviewer required.', 403);
        }
        if (!is_string($hash) || !self::hash($hash) || !class_exists(\DigiForge\Research\DesignFactoryRepository::class)) {
            return self::error('Confirmed Day 3 design handoff unavailable.');
        }
        $handoff = (new \DigiForge\Research\DesignFactoryRepository())->handoff($shop, $hash);
        if ($handoff instanceof WP_Error) {
            return $handoff;
        }
        if (
            ($handoff['design_metadata']['design_hash'] ?? null) !== $hash
            || ($handoff['design_metadata']['human_review']['decision'] ?? null) !== 'APPROVE'
            || ($handoff['design_metadata']['release_hold'] ?? null) !== true
        ) {
            return self::error('Confirmed commercial review evidence required.');
        }
        return $handoff;
    }
    private function name(string $hash): string
    {
        return 'digiforge_product_package_' . $hash;
    }
    private function stored(string $name): string|WP_Error|null
    {
        global $wpdb;
        $wpdb->last_error = '';
        $raw = $wpdb->get_var($wpdb->prepare('SELECT option_value FROM ' . $wpdb->options . ' WHERE BINARY option_name=BINARY %s', $name));
        return $wpdb->last_error === '' && ($raw === null || is_string($raw)) ? $raw : self::error('Durable evidence unavailable.', 503);
    }
    private static function hash(string $hash): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', $hash) === 1;
    }
    private static function error(string $message, int $status = 409): WP_Error
    {
        return new WP_Error('product_package_denied', $message, ['status' => $status,
            'release_hold' => true, 'retry_permitted' => false, 'external_execution_authorized' => false]);
    }
}
