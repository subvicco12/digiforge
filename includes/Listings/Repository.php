<?php

declare(strict_types=1);

namespace DigiForge\Listings;

use DigiForge\Database\Tables;
use DigiForge\Security\Logger;
use WP_Error;

final class Repository
{
    public function createListing(array $input, ?string $key = null): array|WP_Error
    {
        try {
            $pv = absint($input['product_version_id'] ?? 0);
            if ($pv < 1 || ! $this->exists(Tables::product_versions(), $pv)) {
                return $this->error('invalid_parent', 'Valid product_version_id is required.');
            }
            $environment = Validator::environment(sanitize_key((string)($input['environment'] ?? '')));
            $channel = Validator::channel(sanitize_key((string)($input['channel'] ?? 'etsy')));
            $title = Validator::title((string)($input['title'] ?? ''));
            $currency = Validator::currency((string)($input['currency'] ?? 'USD'));
            $price = Validator::price($input['price_amount'] ?? 0);
            $taxonomy = Validator::structured((array)($input['taxonomy_metadata'] ?? []));
            $quantity = Validator::structured((array)($input['quantity_policy'] ?? []));
        } catch (\InvalidArgumentException $e) {
            return $this->error('validation', $e->getMessage());
        }

        return $this->insert(Tables::listings(), $key, [
            'product_version_id' => $pv,
            'channel' => $channel,
            'environment' => $environment,
            'shop_reference' => sanitize_text_field((string)($input['shop_reference'] ?? '')),
            'title' => $title,
            'description' => sanitize_textarea_field((string)($input['description'] ?? '')),
            'taxonomy_metadata' => Validator::canonicalJson($taxonomy),
            'price_amount' => $price,
            'currency' => $currency,
            'quantity_policy' => Validator::canonicalJson($quantity),
            'personalization_enabled' => ! empty($input['personalization_enabled']) ? 1 : 0,
            'state' => 'DRAFT',
            'approved_by' => 0,
            'approved_at' => null,
            'created_by' => get_current_user_id(),
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ], 'listing');
    }

    public function setSeo(array $input, ?string $key = null): array|WP_Error
    {
        $listingId = absint($input['listing_id'] ?? 0);
        if (! $this->exists(Tables::listings(), $listingId)) {
            return $this->error('invalid_parent', 'Valid listing_id is required.');
        }
        try {
            $parts = [];
            foreach (['tags','keywords','materials','attributes','audience_metadata','evidence'] as $field) {
                $parts[$field] = Validator::structured((array)($input[$field] ?? []), $field === 'tags' ? 20 : 100);
            }
        } catch (\InvalidArgumentException $e) {
            return $this->error('validation', $e->getMessage());
        }
        $hash = hash('sha256', Validator::canonicalJson($parts));
        return $this->insert(Tables::listing_seo(), $key, [
            'listing_id' => $listingId,
            'tags' => Validator::canonicalJson($parts['tags']),
            'keywords' => Validator::canonicalJson($parts['keywords']),
            'materials' => Validator::canonicalJson($parts['materials']),
            'attributes' => Validator::canonicalJson($parts['attributes']),
            'audience_metadata' => Validator::canonicalJson($parts['audience_metadata']),
            'evidence' => Validator::canonicalJson($parts['evidence']),
            'canonical_hash' => $hash,
            'created_by' => get_current_user_id(),
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ], 'listing_seo');
    }

    public function bindMedia(array $input, ?string $key = null): array|WP_Error
    {
        $listing = $this->find(Tables::listings(), absint($input['listing_id'] ?? 0));
        if (! is_array($listing)) { return $this->error('invalid_parent', 'Valid listing_id is required.'); }
        $assetRevisionId = absint($input['asset_revision_id'] ?? 0);
        $releaseBundleId = absint($input['release_bundle_id'] ?? 0);
        if ($assetRevisionId < 1 && $releaseBundleId < 1) { return $this->error('validation', 'An approved media source is required.'); }
        if ($releaseBundleId > 0) {
            $bundle = $this->find(Tables::release_bundles(), $releaseBundleId);
            if (! is_array($bundle) || (int)$bundle['product_version_id'] !== (int)$listing['product_version_id'] || ! in_array((string)$bundle['state'], ['APPROVED','RELEASE_READY'], true)) {
                return $this->error('invalid_media', 'Release bundle must belong to the listing product and be approved.', 409);
            }
        }
        if ($assetRevisionId > 0 && ! $this->exists(Tables::asset_revisions(), $assetRevisionId)) {
            return $this->error('invalid_media', 'Asset revision does not exist.', 409);
        }
        return $this->insert(Tables::listing_media(), $key, [
            'listing_id' => (int)$listing['id'],
            'asset_revision_id' => $assetRevisionId,
            'release_bundle_id' => $releaseBundleId,
            'media_role' => sanitize_key((string)($input['media_role'] ?? 'image')),
            'position_index' => min(100, absint($input['position_index'] ?? 0)),
            'state' => 'BOUND',
            'created_by' => get_current_user_id(),
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ], 'listing_media');
    }

    public function bindPod(array $input, ?string $key = null): array|WP_Error
    {
        $listing = $this->find(Tables::listings(), absint($input['listing_id'] ?? 0));
        $mapping = $this->find(Tables::pod_mappings(), absint($input['provider_mapping_id'] ?? 0));
        if (! is_array($listing) || ! is_array($mapping) || (int)$listing['product_version_id'] !== (int)$mapping['product_version_id']) {
            return $this->error('invalid_relationship', 'Listing and POD mapping must share a product version.', 409);
        }
        if ((string)$listing['environment'] !== (string)$mapping['environment'] || (string)$mapping['state'] !== 'APPROVED') {
            return $this->error('pod_not_ready', 'POD mapping must be approved in the same environment.', 409);
        }
        $schemaId = absint($input['personalization_schema_id'] ?? 0);
        if ($schemaId > 0) {
            $schema = $this->find(Tables::personalization_schemas(), $schemaId);
            if (! is_array($schema) || (int)$schema['product_version_id'] !== (int)$listing['product_version_id'] || (string)$schema['state'] !== 'APPROVED') {
                return $this->error('personalization_not_ready', 'Personalization schema must be approved for the same product.', 409);
            }
        }
        $readiness = (new \DigiForge\POD\Repository())->readiness((int)$mapping['id']);
        if (is_wp_error($readiness) || empty($readiness['ready'])) {
            return $this->error('pod_not_ready', 'POD readiness must pass before binding.', 409);
        }
        return $this->insert(Tables::listing_pod_bindings(), $key, [
            'listing_id' => (int)$listing['id'],
            'provider_mapping_id' => (int)$mapping['id'],
            'personalization_schema_id' => $schemaId,
            'readiness_hash' => hash('sha256', wp_json_encode($readiness) ?: ''),
            'created_by' => get_current_user_id(),
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ], 'listing_pod_binding');
    }

    public function createIntent(array $input, ?string $key = null): array|WP_Error
    {
        $listing = $this->find(Tables::listings(), absint($input['listing_id'] ?? 0));
        if (! is_array($listing)) { return $this->error('invalid_parent', 'Valid listing_id is required.'); }
        $type = strtoupper(sanitize_key((string)($input['intent_type'] ?? '')));
        if (! in_array($type, ['PREPARE_DRAFT','PREPARE_MEDIA','PREPARE_INVENTORY','PREPARE_PERSONALIZATION','PREPARE_UPDATE'], true)) {
            return $this->error('validation', 'Invalid Etsy intent type.');
        }
        try { $payload = Validator::structured((array)($input['input_payload'] ?? [])); }
        catch (\InvalidArgumentException $e) { return $this->error('validation', $e->getMessage()); }
        return $this->insert(Tables::etsy_intents(), $key, [
            'listing_id' => (int)$listing['id'],
            'draft_package_id' => absint($input['draft_package_id'] ?? 0),
            'environment' => (string)$listing['environment'],
            'intent_type' => $type,
            'input_payload' => Validator::canonicalJson($payload),
            'state' => 'BLOCKED',
            'created_by' => get_current_user_id(),
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ], 'etsy_intent');
    }

    public function transition(string $entity, int $id, string $to): array|WP_Error
    {
        $table = $entity === 'listing' ? Tables::listings() : ($entity === 'intent' ? Tables::etsy_intents() : '');
        if ($table === '') { return $this->error('validation', 'Unknown lifecycle entity.'); }
        $row = $this->find($table, $id);
        if (! is_array($row) || ! Lifecycle::can($entity, (string)$row['state'], $to)) {
            return $this->error('invalid_transition', 'Lifecycle transition is not permitted.', 409);
        }
        if (($to === 'APPROVED' || $to === 'APPROVED_INTENT') && get_current_user_id() < 1) {
            return $this->error('reviewer_required', 'Authenticated human reviewer required.', 403);
        }
        global $wpdb;
        $data = ['state'=>$to,'updated_at'=>$this->now()];
        if ($entity === 'listing' && $to === 'APPROVED') { $data['approved_by']=get_current_user_id(); $data['approved_at']=$this->now(); }
        $ok = $wpdb->update($table, $data, ['id'=>$id,'state'=>(string)$row['state']]);
        if ($ok !== 1) { return $this->error('transition_conflict', 'State changed concurrently or update failed.', 409); }
        Logger::audit('listing_state_changed', get_current_user_id(), $entity, (string)$id, ['from'=>$row['state'],'to'=>$to]);
        return $this->find($table, $id) ?: [];
    }

    public function readiness(int $listingId): array|WP_Error
    {
        $listing = $this->find(Tables::listings(), $listingId);
        if (! is_array($listing)) { return $this->error('not_found', 'Listing not found.', 404); }
        global $wpdb;
        $seo = (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Tables::listing_seo().' WHERE listing_id=%d', $listingId));
        $media = (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Tables::listing_media().' WHERE listing_id=%d', $listingId));
        $podRequired = (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Tables::listing_pod_bindings().' WHERE listing_id=%d', $listingId));
        $checks = [
            'listing_approved' => (string)$listing['state'] === 'APPROVED',
            'seo_present' => $seo > 0,
            'approved_media_bound' => $media > 0,
            'pod_binding_valid' => $podRequired >= 0,
            'human_approval' => (int)$listing['approved_by'] > 0 && ! empty($listing['approved_at']),
        ];
        $ready = ! in_array(false, $checks, true);
        $payload = ['listing_id'=>$listingId,'ready'=>$ready,'checks'=>$checks];
        $payload['hash'] = hash('sha256', Validator::canonicalJson($payload));
        return $payload;
    }

    public function createDraftPackage(array $input, ?string $key = null): array|WP_Error
    {
        $listingId = absint($input['listing_id'] ?? 0);
        $listing = $this->find(Tables::listings(), $listingId);
        if (! is_array($listing)) { return $this->error('invalid_parent', 'Valid listing_id is required.'); }
        $readiness = $this->readiness($listingId);
        if (is_wp_error($readiness) || empty($readiness['ready'])) { return $this->error('not_ready', 'Listing is not release-ready.', 409); }
        $seo = $this->firstBy(Tables::listing_seo(), 'listing_id', $listingId);
        $payload = ['listing'=>$listing,'seo'=>$seo ?: [],'readiness'=>$readiness];
        $canonical = Validator::canonicalJson($payload);
        return $this->insert(Tables::etsy_draft_packages(), $key, [
            'listing_id' => $listingId,
            'package_version' => sanitize_text_field((string)($input['package_version'] ?? 'v1')),
            'canonical_payload' => $canonical,
            'payload_hash' => hash('sha256', $canonical),
            'readiness' => Validator::canonicalJson($readiness),
            'readiness_hash' => (string)$readiness['hash'],
            'approved_by' => get_current_user_id(),
            'approved_at' => $this->now(),
            'created_by' => get_current_user_id(),
            'created_at' => $this->now(),
        ], 'etsy_draft_package');
    }

    public function list(string $entity, int $page = 1, int $perPage = 20): array
    {
        $tables = ['listings'=>Tables::listings(),'seo'=>Tables::listing_seo(),'media'=>Tables::listing_media(),'pod'=>Tables::listing_pod_bindings(),'packages'=>Tables::etsy_draft_packages(),'intents'=>Tables::etsy_intents()];
        $table = $tables[$entity] ?? '';
        if ($table === '') { return ['items'=>[],'pagination'=>['total_items'=>0,'total_pages'=>0]]; }
        global $wpdb; $offset = ($page-1)*$perPage;
        $items = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table ORDER BY id DESC LIMIT %d OFFSET %d", $perPage, $offset), ARRAY_A) ?: [];
        $total = (int)$wpdb->get_var("SELECT COUNT(*) FROM $table");
        return ['items'=>$items,'pagination'=>['total_items'=>$total,'total_pages'=>(int)ceil($total/max(1,$perPage))]];
    }

    private function insert(string $table, ?string $key, array $data, string $objectType): array|WP_Error
    {
        global $wpdb;
        if ($key !== null && $key !== '') {
            $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE idempotency_key=%s LIMIT 1", $key), ARRAY_A);
            if (is_array($existing)) { return $existing; }
            $data['idempotency_key'] = $key;
        }
        if ($wpdb->insert($table, $data) !== 1) { return $this->error('database_error', 'Unable to persist listing record.', 500); }
        $id = (int)$wpdb->insert_id;
        Logger::audit($objectType.'_created', get_current_user_id(), $objectType, (string)$id, []);
        return $this->find($table, $id) ?: [];
    }

    private function exists(string $table, int $id): bool { global $wpdb; return $id > 0 && (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE id=%d",$id)) === 1; }
    private function find(string $table, int $id): ?array { global $wpdb; $r=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d LIMIT 1",$id),ARRAY_A); return is_array($r)?$r:null; }
    private function firstBy(string $table, string $field, int $id): ?array { global $wpdb; $r=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE $field=%d ORDER BY id DESC LIMIT 1",$id),ARRAY_A); return is_array($r)?$r:null; }
    private function now(): string { return current_time('mysql', true); }
    private function error(string $code,string $message,int $status=400): WP_Error { return new WP_Error('digiforge_'.$code,$message,['status'=>$status]); }
}
