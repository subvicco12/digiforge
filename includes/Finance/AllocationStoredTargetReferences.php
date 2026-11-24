<?php
declare(strict_types=1);

namespace DigiForge\Finance;

use DigiForge\Database\Tables;
use DigiForge\Database\QueryEvidence;
use DigiForge\Portal\ProductEvidenceScope;
use DigiForge\POD\BusinessScopeRepository;
use InvalidArgumentException;
use WP_Error;

/** Read-only exact current lineage. Caller hashes/flags never prove stored ownership. */
final class AllocationStoredTargetReferences implements AllocationTargetReferences
{
    public function resolve(array $source, array $target): array
    {
        $source=DeclarationContract::fromStored($source);
        $id=$target['order_line_item_id'] ?? null;
        if (!is_int($id) || $id < 1) self::fail('Exact positive stored order line identity required.');
        $first=$this->snapshot($source,$id);
        $second=$this->snapshot($source,$id);
        if ($first !== $second) self::fail('Current target lineage changed during resolution.');
        return $second;
    }

    private function snapshot(array $source, int $lineId): array
    {
        $scope=$source['scope'];$shop=$scope['shop_key'];$environment=$source['environment'];
        if (!in_array($shop,['digital','personalized_pod'],true)) self::fail('This shop has no current stored allocation ownership contract.');
        $line=$this->row(Tables::order_line_items(),$lineId);
        $order=$this->row(Tables::orders(),(int)$line['order_id']);
        $listing=$this->row(Tables::listings(),(int)$line['listing_id']);
        $version=$this->row(Tables::product_versions(),(int)$line['product_version_id']);
        $product=$this->row(Tables::products(),(int)$version['product_id']);
        $family=$this->row(Tables::product_families(),(int)$product['product_family_id']);
        $opportunity=$this->row(Tables::opportunities(),(int)$family['opportunity_id']);
        $business=$this->row(Tables::businesses(),$scope['business_id']);
        $store=$this->row(Tables::stores(),$scope['store_id']);
        if ($business['status'] !== 'ACTIVE' || $store['status'] !== 'ACTIVE'
            || (int)$store['business_id'] !== (int)$business['id']) self::fail('Active exact business/store ownership required.');
        foreach ([$line,$order,$listing] as $row) if ($row['environment'] !== $environment) self::fail('Stored line/order/listing environment differs.');
        if ($line['currency'] !== $source['currency'] || $order['currency'] !== $source['currency']
            || (int)$listing['product_version_id'] !== (int)$version['id']
            || $listing['channel'] !== $order['channel'] || $store['channel'] !== $order['channel']) self::fail('Exact line/order currency and listing product/channel required.');

        global $wpdb;
        $predicate=ProductEvidenceScope::forTable(Tables::product_versions(),$shop);
        if ($predicate === null) self::fail('Current product ancestry predicate is unavailable.');
        $owned=$this->rows($wpdb->prepare('SELECT id FROM '.Tables::product_versions().' WHERE id=%d AND '.$predicate.' LIMIT 2',(int)$version['id']));
        if (count($owned) !== 1) self::fail('Current product shop ancestry is missing or conflicting.');
        $ancestry=$this->rows($wpdb->prepare('SELECT * FROM '.Tables::research_candidates().' WHERE opportunity_id=%d ORDER BY id ASC LIMIT 201',(int)$opportunity['id']));
        if ($ancestry === [] || count($ancestry) > 200) self::fail('Complete bounded current shop ancestry required.');

        $mapping=[];$program=[];$variant='';$mappingId=(int)$line['provider_mapping_id'];
        if ($shop === 'digital') {
            if ($mappingId !== 0 || $source['provider'] !== 'etsy') self::fail('Digital allocation cannot use a POD mapping or an unbound provider.');
            $ownership=(new DigitalSourceBinding())->current((int)$business['id'],(int)$store['id'],$environment);
            if ($ownership instanceof WP_Error) self::fail('Current independently reviewed Digital source relation is unavailable.');
            $shops=['digital',$ownership['external_shop_reference']];
        } else {
            $mapping=$this->row(Tables::pod_mappings(),$mappingId);
            if ((int)$mapping['product_version_id'] !== (int)$version['id'] || $mapping['environment'] !== $environment
                || (in_array($source['provider'],['printify','gelato'],true) && $mapping['provider'] !== $source['provider'])) self::fail('Current provider mapping product/environment/provider differs.');
            $claims=$this->rows($wpdb->prepare('SELECT id FROM '.Tables::pod_business_mappings().' WHERE provider_mapping_id=%d LIMIT 2',$mappingId));
            if (count($claims) !== 1) self::fail('Current provider ownership is missing or ambiguous.');
            $ownership=(new BusinessScopeRepository())->assertActiveOwnershipForMapping($mappingId);
            if ($ownership instanceof WP_Error || $ownership['program_key'] !== 'PERSONALIZED_POD'
                || (int)$ownership['id'] !== (int)$claims[0]['id']
                || (int)$ownership['business_id'] !== (int)$business['id'] || (int)$ownership['store_id'] !== (int)$store['id']
                || (int)$ownership['product_version_id'] !== (int)$version['id']) self::fail('Current exact approved POD ownership differs.');
            $program=$this->row(Tables::product_programs(),(int)$ownership['product_program_id']);
            $variant=(string)$mapping['provider_variant_key'];$shops=['personalized_pod'];
        }
        if (!in_array($order['shop_reference'],$shops,true) || !in_array($listing['shop_reference'],$shops,true)) self::fail('Stored order/listing shop is outside the exact current ownership relation.');
        $resolved=['shop_key'=>$shop,'business_id'=>(int)$business['id'],'store_id'=>(int)$store['id'],
            'order_id'=>(int)$order['id'],'order_line_item_id'=>(int)$line['id'],'product_id'=>(int)$product['id'],
            'product_version_id'=>(int)$version['id'],'listing_id'=>(int)$listing['id'],'provider_mapping_id'=>$mappingId,
            'variant_ref'=>$variant,'environment'=>(string)$line['environment'],'currency'=>(string)$line['currency']];
        $snapshot=compact('line','order','listing','version','product','family','opportunity','business','store','ancestry','mapping','program','ownership');
        return ['target'=>$resolved,'reference_hash'=>Validator::hash($snapshot)];
    }

    private function row(string $table, int $id): array
    {
        if ($id < 1) self::fail('A required current stored reference is unspecified.');
        global $wpdb;
        $rows=$this->rows($wpdb->prepare('SELECT * FROM '.$table.' WHERE id=%d LIMIT 2',$id));
        if (count($rows) !== 1) self::fail('A required current stored reference is missing or ambiguous.');
        return $rows[0];
    }

    private function rows(string $sql): array
    {
        global $wpdb;$wpdb->last_error='';$rows=$wpdb->get_results($sql,ARRAY_A);
        if (QueryEvidence::failed() || !is_array($rows)) self::fail('Current stored allocation evidence could not be read.');
        return $rows;
    }

    private static function fail(string $message): never { throw new InvalidArgumentException($message); }
}
