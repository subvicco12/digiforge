<?php
declare(strict_types=1);

namespace DigiForge\Operations;

use DigiForge\Core\Settings;
use DigiForge\Database\Tables;
use DigiForge\Finance\Repository as FinanceRepository;
use DigiForge\Orders\Repository as OrderRepository;
use DigiForge\Security\Logger;
use WP_Error;

/**
 * Deterministic internal-only P5 fixture runner.
 *
 * It never calls provider clients and is intentionally unavailable outside the
 * single governed isolated staging host.
 */
final class IsolatedStagingSmoke
{
    private const HOST = 'digiforgestaging.converentis.com';

    public static function allowed(): bool
    {
        $host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        return $host === self::HOST
            && Settings::get('stop_all', true) === true
            && Settings::get('activation_authorized', true) === false
            && Settings::get('automation_armed', true) === false
            && Settings::safety_locked();
    }

    /** @return array<string,mixed>|WP_Error */
    public static function run(int $listingId, string $operationKey): array|WP_Error
    {
        if (! self::allowed()) {
            return new WP_Error('digiforge_smoke_not_isolated', __('Internal smoke is restricted to the locked isolated staging site.', 'digiforge'), ['status'=>403]);
        }
        if ($listingId < 1 || ! preg_match('/^[a-zA-Z0-9._:-]{8,128}$/', $operationKey)) {
            return new WP_Error('digiforge_smoke_invalid', __('A listing id and bounded smoke operation key are required.', 'digiforge'), ['status'=>400]);
        }

        global $wpdb;
        $listing = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::listings().' WHERE id=%d LIMIT 1', $listingId), ARRAY_A);
        if (! is_array($listing) || (string)($listing['state'] ?? '') !== 'APPROVED') {
            return new WP_Error('digiforge_smoke_listing', __('Smoke requires an existing approved listing.', 'digiforge'), ['status'=>409]);
        }
        $versionId=(int)($listing['product_version_id']??0);
        $digital=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Tables::digital_products().' WHERE product_version_id=%d', $versionId));
        if ($digital !== 1) {
            return new WP_Error('digiforge_smoke_digital_authority', __('Smoke requires exactly one local digital-product authority record.', 'digiforge'), ['status'=>409]);
        }
        $package=$wpdb->get_row($wpdb->prepare("SELECT id,approved_by,approved_at FROM ".Tables::etsy_draft_packages()." WHERE listing_id=%d AND approved_by>0 AND approved_at IS NOT NULL ORDER BY id DESC LIMIT 1",$listingId),ARRAY_A);
        if(!is_array($package)) return new WP_Error('digiforge_smoke_draft_evidence',__('Smoke requires a human-approved local Etsy draft package bound to the listing.','digiforge'),['status'=>409]);
        $opTable=$wpdb->prefix.'digiforge_etsy_operations';
        $delivery=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $opTable WHERE draft_package_id=%d AND shop_reference=%s AND operation_type='UPLOAD_FILE' AND state='CONFIRMED_SUCCESS' AND external_reference REGEXP '^[1-9][0-9]*$' AND external_asset_reference REGEXP '^[1-9][0-9]*$' AND resource_reference=external_reference",(int)$package['id'],(string)$listing['shop_reference']));
        if($delivery<1) return new WP_Error('digiforge_smoke_delivery_evidence',__('Smoke requires preserved confirmed immutable digital delivery evidence.','digiforge'),['status'=>409]);

        $orders=new OrderRepository();
        $order=$orders->createOrder([
            'channel'=>'etsy','environment'=>(string)$listing['environment'],
            'external_order_reference'=>'P5-LOCAL-'.$operationKey,
            'shop_reference'=>(string)$listing['shop_reference'],'buyer_reference'=>'P5-LOCAL-NON-PII',
            'currency'=>(string)$listing['currency'],'subtotal_amount'=>(float)$listing['price_amount'],
            'shipping_amount'=>0,'tax_amount'=>0,'total_amount'=>(float)$listing['price_amount'],
            'personalization_required'=>false,'metadata'=>['fixture'=>'P5_INTERNAL_ONLY']
        ],$operationKey.':order');
        if($order instanceof WP_Error)return $order;
        $item=$orders->addLineItem([
            'order_id'=>(int)$order['id'],'listing_id'=>$listingId,'product_version_id'=>$versionId,
            'provider_mapping_id'=>0,'quantity'=>1,'unit_price_amount'=>(float)$listing['price_amount'],
            'currency'=>(string)$listing['currency'],'personalization_payload'=>[]
        ],$operationKey.':item');
        if($item instanceof WP_Error)return $item;
        foreach(['VALIDATED','REVIEW_REQUIRED','APPROVED'] as $state){
            if((string)($order['state']??'')===$state)continue;
            $order=$orders->transition('order',(int)$order['id'],$state);
            if($order instanceof WP_Error)return $order;
        }
        $readiness=$orders->readiness((int)$order['id']);
        if($readiness instanceof WP_Error||empty($readiness['ready'])) return $readiness instanceof WP_Error?$readiness:new WP_Error('digiforge_smoke_not_ready',__('Local smoke order did not pass readiness.','digiforge'),['status'=>409]);
        $plan=$orders->createPlan([
            'order_id'=>(int)$order['id'],'plan_version'=>'p5-local-v1','provider'=>'digital',
            'provider_mapping_snapshot'=>[],'print_area_snapshot'=>[],
            'shipping_method_metadata'=>['mode'=>'digital_download'],
            'cost_snapshot_metadata'=>['external_provider_cost'=>0,'fixture'=>'P5_INTERNAL_ONLY']
        ],$operationKey.':plan');
        if($plan instanceof WP_Error)return $plan;
        foreach(['VALIDATED','REVIEW_REQUIRED','APPROVED'] as $state){
            if((string)($plan['state']??'')===$state)continue;
            $plan=$orders->transition('plan',(int)$plan['id'],$state);
            if($plan instanceof WP_Error)return $plan;
        }

        $finance=new FinanceRepository();
        $ledger=$finance->createLedger([
            'environment'=>(string)$listing['environment'],'source_type'=>'order','source_id'=>(int)$order['id'],
            'entry_type'=>'REVENUE','currency'=>(string)$listing['currency'],'amount'=>(float)$listing['price_amount'],
            'base_currency'=>(string)$listing['currency'],'base_amount'=>(float)$listing['price_amount'],
            'effective_date'=>gmdate('Y-m-d'),'metadata'=>['fixture'=>'P5_INTERNAL_ONLY','external_execution_performed'=>false]
        ],$operationKey.':ledger');
        if($ledger instanceof WP_Error)return $ledger;
        $period=$finance->calculatePeriod([
            'environment'=>(string)$listing['environment'],'period_start'=>gmdate('Y-m-d'),
            'period_end'=>gmdate('Y-m-d'),'base_currency'=>(string)$listing['currency']
        ],$operationKey.':period');
        if($period instanceof WP_Error)return $period;

        $result=[
            'state'=>'P5_LOCAL_COMPLETE','operation_key_hash'=>hash('sha256',$operationKey),
            'listing_id'=>$listingId,'order_id'=>(int)$order['id'],'order_line_item_id'=>(int)$item['id'],
            'order_readiness_hash'=>(string)$readiness['hash'],'fulfillment_plan_id'=>(int)$plan['id'],
            'finance_ledger_id'=>(int)$ledger['id'],'finance_period_id'=>(int)$period['id'],
            'external_actions_performed'=>false,'commerce_execution_authorized'=>false,
        ];
        Logger::audit('p5_isolated_staging_smoke_completed',$result,'system','p5_smoke');
        return $result;
    }
}
