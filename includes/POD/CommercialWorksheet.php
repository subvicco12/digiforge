<?php
declare(strict_types=1);
namespace DigiForge\POD;

use DigiForge\Listings\Validator;
use InvalidArgumentException;

/** Pure conditional USD worksheet. Source metadata is not provider or approval authority. */
final class CommercialWorksheet
{
    public const SCHEMA = 'DF_SHOP2_COMMERCIAL_WORKSHEET_1';
    private const MAX_MINOR = 100000000;
    private const REVENUE = ['item_price_minor','buyer_shipping_minor','buyer_tax_minor'];
    private const COSTS = ['base_cost_minor','supplier_shipping_minor','surcharge_minor','listing_fee_minor',
        'processing_fixed_minor','conversion_minor','regulatory_minor','merchant_tax_minor','other_cost_minor'];
    private const RATES = ['transaction_bps','processing_bps','ads_bps','refund_bps','discount_bps'];

    public static function assess(array $input, array $expectedBinding): array
    {
        Validator::structured($input);
        self::keys($input,['schema','currency','binding','as_of','sources','revenue','costs','rates','target_margin_bps']);
        if (($input['schema']??null)!==self::SCHEMA || ($input['currency']??null)!=='USD') self::deny('schema/currency');
        $binding=$input['binding']??null;
        if (!is_array($binding) || Validator::canonicalJson($binding)!==Validator::canonicalJson($expectedBinding)
            || ($binding['shop_key']??null)!=='personalized_pod'
            || !in_array($binding['environment']??null,['test','sandbox','production'],true)
            || !in_array($binding['fixture_id']??null,['FL-03','FL-04'],true)
            || !is_string($binding['artwork_sha256']??null)
            || !preg_match('/^[a-f0-9]{64}$/D',$binding['artwork_sha256'])) self::deny('identity');
        $products=['FL-03'=>'6ae98d61eb5d7e24b80ca0a6','FL-04'=>'6ae9913d9541969415088814'];
        if (($binding['product_id']??null)!==$products[$binding['fixture_id']]) self::deny('product');
        self::keys($binding,['shop_key','environment','fixture_id','product_id','artwork_sha256','template_fingerprint','blueprint_id','provider_id','variant_ids']);
        foreach(['blueprint_id','provider_id']as $id)if($binding[$id]!==null&&(!is_int($binding[$id])||$binding[$id]<1||$binding[$id]>2147483647))self::deny('provider identity');
        if($binding['template_fingerprint']!==null&&(!is_string($binding['template_fingerprint'])||!preg_match('/^[a-f0-9]{64}$/D',$binding['template_fingerprint'])))self::deny('template identity');
        if(!is_array($binding['variant_ids'])||!array_is_list($binding['variant_ids'])||count($binding['variant_ids'])>100||count(array_unique($binding['variant_ids'],SORT_REGULAR))!==count($binding['variant_ids']))self::deny('variant identity');
        foreach($binding['variant_ids']as $id)if(!is_int($id)||$id<1||$id>2147483647)self::deny('variant identity');
        $asOf=self::date($input['as_of']??null,true);
        $sources=$input['sources']??null;
        if (!is_array($sources)||$sources===[]||count($sources)>32) self::deny('sources');
        $blockers=['CURRENT_PROVIDER_SOURCE_AUTHORITY_REQUIRED','OWNER_COMMERCIAL_REVIEW_REQUIRED','REFRESH_BEFORE_COMMERCIAL_USE'];
        $stale=[];$expiries=[];
        foreach ($sources as $id=>$source) {
            if (!is_string($id)||!preg_match('/^[A-Za-z0-9_-]{1,80}$/D',$id)||!is_array($source)) self::deny('source');
            self::keys($source,['reference','sha256','classification','observed_at','valid_until']);
            $url=$source['reference']??null;
            if (!is_string($url)||strlen($url)>2048||!filter_var($url,FILTER_VALIDATE_URL)
                || parse_url($url,PHP_URL_SCHEME)!=='https'||parse_url($url,PHP_URL_USER)!==null||parse_url($url,PHP_URL_PASS)!==null
                ||!is_string($source['sha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$source['sha256'])) self::deny('source reference');
            $query=[];parse_str((string)(parse_url($url,PHP_URL_QUERY)??''),$query);Validator::rejectCredentials($query);
            foreach(array_keys($query)as $key)if(preg_match('/token|secret|password|api.?key|authorization|signature|credential|resourcekey/i',(string)$key))self::deny('source credential query');
            if (!in_array($source['classification']??null,['PUBLIC_POLICY','HISTORICAL_AUTHENTICATED_OBSERVATION','OPERATOR_ASSUMPTION','REPORTED_READ_ONLY_RECEIPT'],true)) self::deny('source classification');
            $observed=self::date($source['observed_at']??null);
            if ($observed>$asOf) self::deny('future observation');
            if (($source['valid_until']??null)===null) $blockers[]='SOURCE_FRESHNESS_UNKNOWN:'.$id;
            else {
                $expiry=self::date($source['valid_until'],true);
                if ($expiry<=$observed) self::deny('source expiry');
                $expiries[]=$source['valid_until'];
                if ($expiry<=$asOf) {$stale[$id]=true;$blockers[]='STALE_SOURCE:'.$id;}
            }
            if ($source['classification']==='HISTORICAL_AUTHENTICATED_OBSERVATION') $blockers[]='HISTORICAL_SOURCE:'.$id;
            if ($source['classification']==='OPERATOR_ASSUMPTION') $blockers[]='UNAPPROVED_ASSUMPTION:'.$id;
        }
        $values=[];$unknown=[];$usedStale=false;
        foreach (['revenue'=>self::REVENUE,'costs'=>self::COSTS,'rates'=>self::RATES] as $group=>$keys) {
            if (!is_array($input[$group]??null)) self::deny($group);
            self::keys($input[$group],$keys);
            foreach ($keys as $key) {
                $node=$input[$group][$key];
                if (!is_array($node)) self::deny($key);
                self::keys($node,['value','source']);$value=$node['value'];$source=$node['source'];
                if ($value===null) {if($source!==null&&!is_string($source))self::deny('unknown source');if($source!==null&&!isset($sources[$source]))self::deny('unknown source');$unknown[]=$key;$blockers[]='UNKNOWN:'.$key;}
                else {
                    if (!is_int($value)||$value<0||$value>($group==='rates'?10000:self::MAX_MINOR)
                        ||($key==='item_price_minor'&&$value===0)||($key==='discount_bps'&&$value===10000)
                        ||!is_string($source)||!isset($sources[$source])) self::deny($key);
                    if (isset($stale[$source])) $usedStale=true;
                }
                $values[$key]=$value;
            }
        }
        $target=$input['target_margin_bps'];
        if (!is_int($target)||$target<0||$target>=10000) self::deny('target margin');
        $ready=$unknown===[]&&!$usedStale;
        $calculation=$ready?self::calculate($values,$values['item_price_minor']):null;
        $minimum=null;
        if ($ready) {
            $factor=10000-$values['discount_bps'];
            $sumRates=$values['transaction_bps']+$values['processing_bps']+$values['ads_bps']+$values['refund_bps'];
            $slope=10000-$sumRates-$target;
            $firstGross=max(1,intdiv($factor,10000)+$values['buyer_shipping_minor']);
            $maxGross=intdiv(self::MAX_MINOR*$factor,10000)+$values['buyer_shipping_minor'];
            // Every fee repeats its rounding residue after 10,000 gross cents.
            // Enumerate residues, then jump whole periods using exact integer slope.
            // This is bounded and finds the true minimum despite one-cent rounding dips.
            for($offset=0;$offset<10000&&$firstGross+$offset<=$maxGross;$offset++){
                $gross=$firstGross+$offset;
                $item=max(1,intdiv(max(0,$gross-$values['buyer_shipping_minor'])*10000+$factor-1,$factor));
                $c=self::calculate($values,$item);
                $surplus=$c['contribution_minor']*10000-$c['gross_revenue_minor']*$target;
                if($surplus<0){
                    if($slope<=0)continue;
                    $periods=intdiv(-$surplus+10000*$slope-1,10000*$slope);
                    $gross+=$periods*10000;
                    if($gross>$maxGross)continue;
                    $item=max(1,intdiv(max(0,$gross-$values['buyer_shipping_minor'])*10000+$factor-1,$factor));
                }
                if($item<=self::MAX_MINOR&&($minimum===null||$item<$minimum))$minimum=$item;
            }
            if($minimum===null)$blockers[]='PRICE_TARGET_UNATTAINABLE';
        }
        sort($expiries,SORT_STRING);
        return ['schema'=>'DF_SHOP2_COMMERCIAL_ASSESSMENT_1','classification'=>'CONDITIONAL_REVIEW_NOT_ACCOUNT_PROFIT',
            'currency'=>'USD','as_of'=>$input['as_of'],'binding'=>$binding,'input_sha256'=>hash('sha256',Validator::canonicalJson($input)),
            'sources'=>$sources,'assumptions'=>['revenue'=>$input['revenue'],'costs'=>$input['costs'],'rates'=>$input['rates'],'target_margin_bps'=>$target],
            'valid_until'=>$expiries[0]??null,'freshness_scope'=>'AS_OF_SNAPSHOT_ONLY_REVALIDATE_BEFORE_COMMERCIAL_USE',
            'unknown_inputs'=>$unknown,'blockers'=>array_values(array_unique($blockers)),
            'conditional'=>array_merge($calculation??['discounted_item_minor'=>null,'gross_revenue_minor'=>null,'transaction_fee_minor'=>null,'processing_fee_minor'=>null,'ads_fee_minor'=>null,'refund_reserve_minor'=>null,'contribution_minor'=>null,'margin_percent'=>null],['minimum_modeled_item_price_minor'=>$minimum]),
            'rounding'=>'CEIL_EACH_MODELED_FEE_NOT_ACCOUNT_STATEMENT','conversion_basis'=>'ONE_ALL_IN_USD_CONVERSION_WITHDRAWAL_ALLOWANCE',
            'actual_contribution_minor'=>null,'minimum_approved_selling_price_minor'=>null,'commercial_approved'=>false,
            'supplier_certified'=>false,'external_execution_authorized'=>false,'release_hold'=>true];
    }

    /** Legacy display/handoff keys remain compatible; detailed evidence is authoritative here. */
    public static function forPackage(array $input,array $expectedBinding,array $legacy):array
    {
        $r=self::assess($input,$expectedBinding);
        self::keys($legacy,['currency','sale_price','base_cost','shipping_cost','seller_fees','ads_cost','fx_cost','refund_reserve','tax_cost']);
        $price=$legacy['sale_price'];$item=$input['revenue']['item_price_minor']['value'];
        if($legacy['currency']!=='USD'||(!is_int($price)&&!is_float($price))||!is_finite((float)$price)
            ||$item===null||abs($price*100-$item)>0.000001)self::deny('item price conflict');
        foreach($legacy as $key=>$value)if(!in_array($key,['currency','sale_price'],true)&&$value!==null)self::deny('duplicate legacy cost');
        $money=static fn(?int $value):?float=>$value===null?null:$value/100;
        $c=$r['conditional'];$costs=$input['costs'];
        $shipping=($costs['supplier_shipping_minor']['value']===null||$costs['surcharge_minor']['value']===null)?null:$costs['supplier_shipping_minor']['value']+$costs['surcharge_minor']['value'];
        $seller=($c['transaction_fee_minor']===null||$c['processing_fee_minor']===null||$costs['listing_fee_minor']['value']===null||$costs['regulatory_minor']['value']===null)?null:$c['transaction_fee_minor']+$c['processing_fee_minor']+$costs['listing_fee_minor']['value']+$costs['regulatory_minor']['value'];
        return ['classification'=>'CONDITIONAL_ESTIMATE_NOT_REALIZED_PROFIT','currency'=>'USD',
            'price_basis'=>'ITEM_PRICE_PLUS_SEPARATE_BUYER_SHIPPING_PRETAX',
            'assumptions'=>['sale_price'=>$money($item),'buyer_shipping'=>$money($input['revenue']['buyer_shipping_minor']['value']),
                'base_cost'=>$money($costs['base_cost_minor']['value']),'shipping_cost'=>$money($shipping),'seller_fees'=>$money($seller),
                'ads_cost'=>$money($c['ads_fee_minor']),'fx_cost'=>$money($costs['conversion_minor']['value']),
                'refund_reserve'=>$money($c['refund_reserve_minor']),'tax_cost'=>$money($costs['merchant_tax_minor']['value']),
                'other_cost'=>$money($costs['other_cost_minor']['value'])],
            'unknown_costs'=>array_merge($r['unknown_inputs'],['CURRENT_PROVIDER_AND_ACCOUNT_EVIDENCE_REQUIRED']),
            'modeled_contribution'=>$money($c['contribution_minor']),'modeled_margin_percent'=>$c['margin_percent'],
            'sensitivity'=>['ads_plus_15_percent'=>null,'shipping_plus_5'=>null,'fx_plus_3_percent'=>null],
            'sensitivity_basis'=>'PREPARE_SEPARATE_PINNED_WORKSHEET_SCENARIOS','manual_price_decision_required'=>true,'worksheet'=>$r];
    }

    private static function calculate(array $v,int $item):array
    {
        $discounted=intdiv($item*(10000-$v['discount_bps']),10000);$gross=$discounted+$v['buyer_shipping_minor'];
        $fee=static fn(int $base,int $bps):int=>intdiv($base*$bps+9999,10000);
        $transaction=$fee($gross,$v['transaction_bps']);$processing=$fee($gross+$v['buyer_tax_minor'],$v['processing_bps'])+$v['processing_fixed_minor'];
        $ads=$fee($gross,$v['ads_bps']);$reserve=$fee($gross,$v['refund_bps']);
        $fixed=array_sum(array_intersect_key($v,array_flip(self::COSTS)))-$v['processing_fixed_minor'];
        $contribution=$gross-$fixed-$transaction-$processing-$ads-$reserve;
        return ['discounted_item_minor'=>$discounted,'gross_revenue_minor'=>$gross,'transaction_fee_minor'=>$transaction,
            'processing_fee_minor'=>$processing,'ads_fee_minor'=>$ads,'refund_reserve_minor'=>$reserve,
            'contribution_minor'=>$contribution,'margin_percent'=>$gross>0?round($contribution/$gross*100,4):null];
    }
    private static function keys(array $value,array $keys):void
    {
        $actual=array_keys($value);sort($actual,SORT_STRING);sort($keys,SORT_STRING);if($actual!==$keys)self::deny('exact fields');
    }
    private static function date(mixed $value,bool $full=false):int
    {
        if(!is_string($value)||!preg_match($full?'/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/D':'/^[0-9]{4}-[0-9]{2}-[0-9]{2}(?:T[0-9]{2}:[0-9]{2}:[0-9]{2}Z)?$/D',$value))self::deny('date');$format=(!$full&&strlen($value)===10)?'Y-m-d':'Y-m-d\TH:i:s\Z';
        $date=\DateTimeImmutable::createFromFormat('!'.$format,$value,new \DateTimeZone('UTC'));
        if(!$date||$date->format($format)!==$value)self::deny('date');return $date->getTimestamp();
    }
    private static function deny(string $reason):never{throw new InvalidArgumentException('Conditional commercial worksheet invalid: '.$reason.'.');}
}
