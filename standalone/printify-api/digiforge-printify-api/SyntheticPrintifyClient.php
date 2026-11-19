<?php
declare(strict_types=1);

namespace DigiForgePrintifyApi;

use DigiForgePrintifyApi\Engine\PrintifyCatalogReadContract;
use DigiForgePrintifyApi\Engine\POD\{PrintifyApiOutcome, PrintifyApiReadEvidence, PrintifyApiRequest, PrintifyLifecycleEvidence, PrintifyLifecycleRequest, PrintifyOrderEvidence, PrintifyProductRequest, PrintifyUploadContract};

if (!\defined('ABSPATH')) {
    exit;
}

/** Typed request planning and projection over the sealed, in-memory fixture transport. */
final class SyntheticPrintifyClient
{
    private const MODULES = [
        'PrintifyCatalogReadContract.php', 'Listings/Validator.php', 'POD/PrintifyProductRequest.php',
        'POD/PrintifyApiRequest.php', 'POD/PrintifyApiReadEvidence.php', 'POD/PrintifyApiOutcome.php',
        'POD/PrintifyOrderEvidence.php', 'POD/PrintifyLifecycleRequest.php', 'POD/PrintifyLifecycleEvidence.php',
        'POD/PrintifyUploadContract.php',
    ];

    private const CATALOG = [
        'BLUEPRINTS_READ'=>'blueprints', 'BLUEPRINT_READ'=>'blueprint',
        'BLUEPRINT_PROVIDERS_READ'=>'providers', 'BLUEPRINT_PROVIDER_READ'=>'provider',
        'VARIANTS_READ'=>'variants', 'VARIANT_READ'=>'variant', 'SIZE_GUIDE_READ'=>'size_guide',
        'CATALOG_SHIPPING_READ'=>'shipping', 'CATALOG_SHIPPING_METHOD_READ'=>'shipping_method',
    ];

    private const OTHER = [
        'SHOPS_READ','PROVIDERS_READ','PROVIDER_READ','IMAGE_UPLOAD','IMAGE_READ','UPLOADS_READ',
        'PRODUCT_CREATE','PRODUCT_READ','PRODUCTS_READ','PRODUCT_UPDATE','ORDER_SHIPPING_QUOTE',
        'ORDER_CREATE','ORDER_READ','ORDERS_READ','ORDER_CANCEL','ORDER_SEND_TO_PRODUCTION','WEBHOOKS_READ',
        'WEBHOOK_CREATE','WEBHOOK_UPDATE','WEBHOOK_DELETE',
    ];

    public function __construct(private readonly string $shop, private readonly SyntheticPrintifyTransport $transport)
    {
        if (preg_match('/^[1-9][0-9]{0,18}$/D',$shop)!==1) {
            throw new \InvalidArgumentException('Exact synthetic shop required.');
        }
    }

    public static function available(): bool
    {
        foreach (self::MODULES as $module) {
            if (!is_readable(__DIR__.'/Engine/'.$module)) {
                return false;
            }
        }
        return true;
    }

    /** Execute one in-memory attempt. Outcomes never grant operational permission. */
    public function run(string $operation,array $input=[]): array
    {
        if ((!isset(self::CATALOG[$operation]) && !in_array($operation,self::OTHER,true)) || !self::available()) {
            return self::result('DENIED',0,'OPERATION_UNAVAILABLE');
        }
        try {
            foreach (self::MODULES as $module) {
                require_once __DIR__.'/Engine/'.$module;
            }
            $plan=$this->prepare($operation,$input);
        } catch (\Throwable) {
            return self::result('DENIED',0,'REQUEST_VALIDATION');
        }
        $write=$plan['method']!=='GET';
        try {
            $response=$this->transport->request($plan);
            if (($response['synthetic']??null)!==true || !is_int($response['status']??null)
                || $response['status']<0 || $response['status']>599 || !is_array($response['body']??null)) {
                return self::result($write?'UNKNOWN':'DENIED',0,'RESPONSE_VALIDATION');
            }
            $status=$response['status'];
            $outcome=PrintifyApiOutcome::classify($status,$response['headers']??[],1,$plan['method'],1767225600);
            if ($outcome['category']!=='SUCCESS') {
                return self::result($outcome['category'],$status,$outcome['reason_code'])
                    + ['bounded_retry_after'=>$outcome['bounded_retry_after']];
            }
            $body=$response['body'];
            if (strlen(json_encode($body,JSON_THROW_ON_ERROR,24))>262144) {
                throw new \InvalidArgumentException('Response exceeds fixture bounds.');
            }
            return self::result('SYNTHETIC_OBSERVED',$status,'NONE')
                + ['data'=>$this->project($operation,$input,$plan,$body)];
        } catch (\Throwable) {
            return self::result($write?'UNKNOWN':'DENIED',0,'RESPONSE_VALIDATION');
        }
    }

    private function prepare(string $operation,array $input): array
    {
        $root='/v1/shops/'.$this->shop.'/orders';
        if (isset(self::CATALOG[$operation])) {
            if (array_key_exists('kind',$input)) {
                throw new \InvalidArgumentException('Catalog selector must use its typed route.');
            }
            $plan=['method'=>'GET','endpoint'=>PrintifyCatalogReadContract::endpoint(['kind'=>self::CATALOG[$operation]]+$input),'body'=>[]];
        } elseif ($operation==='ORDER_CREATE') {
            PrintifyOrderEvidence::validate($input);
            $plan=['method'=>'POST','endpoint'=>$root.'.json','body'=>$input];
        } elseif ($operation==='ORDER_SEND_TO_PRODUCTION') {
            if (array_keys($input)!==['order_id'] || !self::identity($input['order_id'])) {
                throw new \InvalidArgumentException('Exact order required.');
            }
            $plan=['method'=>'POST','endpoint'=>$root.'/'.$input['order_id'].'/send_to_production.json','body'=>[]];
        } elseif ($operation==='ORDER_SHIPPING_QUOTE'||$operation==='ORDER_CANCEL') {
            $plan=PrintifyLifecycleRequest::prepare($operation==='ORDER_SHIPPING_QUOTE'?'SHIPPING_QUOTE':'ORDER_CANCEL',$this->shop,$input);
        } elseif (in_array($operation,['WEBHOOK_CREATE','WEBHOOK_UPDATE','WEBHOOK_DELETE'],true)) {
            $plan=$this->prepareWebhook($operation,$input);
        } elseif ($operation==='UPLOADS_READ') {
            $plan=PrintifyUploadContract::prepare($operation,$this->shop,$input);
        } elseif ($operation==='PRODUCT_UPDATE') {
            $version=$input['expected_version']??null;
            if (array_key_exists('expected_version',$input) && (!is_int($version)||$version<1||$version>1000)) {
                throw new \InvalidArgumentException('Bounded synthetic revision required.');
            }
            unset($input['expected_version']);
            $plan=PrintifyProductRequest::prepare($operation,$this->shop,$input);
            if ($version!==null) {
                $plan['expected_version']=$version;
            }
        } else {
            $plan=PrintifyApiRequest::prepare($operation,$this->shop,$input);
        }
        $plan['operation']=$operation;
        $plan['provider_shop_id']=$this->shop;
        if (strlen(json_encode($plan['body'],JSON_THROW_ON_ERROR,24))>($operation==='IMAGE_UPLOAD'?3000000:262144)) {
            throw new \InvalidArgumentException('Request exceeds fixture bounds.');
        }
        return $plan;
    }

    private function prepareWebhook(string $operation,array $input): array
    {
        $endpoint='/v1/shops/'.$this->shop.'/webhooks';
        $body=[];
        if ($operation==='WEBHOOK_CREATE') {
            if (count($input)!==2||!in_array($input['topic']??null,SyntheticPrintifyTransport::WEBHOOK_TOPICS,true)
                ||!in_array($input['url']??null,SyntheticPrintifyTransport::WEBHOOK_URLS,true)) {
                throw new \InvalidArgumentException('Exact synthetic webhook topic and destination required.');
            }
            $body=['topic'=>$input['topic'],'url'=>$input['url']];
            return ['method'=>'POST','endpoint'=>$endpoint.'.json','body'=>$body,'input'=>$input];
        }
        if (!self::identity($input['webhook_id']??null)) {
            throw new \InvalidArgumentException('Exact synthetic webhook identity required.');
        }
        $endpoint.='/'.$input['webhook_id'].'.json';
        if ($operation==='WEBHOOK_DELETE'&&count($input)===1) {
            return ['method'=>'DELETE','endpoint'=>$endpoint.'?host=webhooks.example.invalid','body'=>[],'input'=>$input];
        }
        if ($operation==='WEBHOOK_UPDATE'&&count($input)===2&&in_array($input['url']??null,SyntheticPrintifyTransport::WEBHOOK_URLS,true)) {
            return ['method'=>'PUT','endpoint'=>$endpoint,'body'=>['url'=>$input['url']],'input'=>$input];
        }
        throw new \InvalidArgumentException('Exact synthetic webhook request required.');
    }

    private function project(string $operation,array $input,array $plan,array $body): array
    {
        if (isset(self::CATALOG[$operation])) {
            return PrintifyCatalogReadContract::select($body,['kind'=>self::CATALOG[$operation]]+$input);
        }
        $rows=match($operation){
            'ORDERS_READ','PRODUCTS_READ'=>$body['data']??null,
            'ORDER_READ','PRODUCT_READ','ORDER_CREATE','ORDER_CANCEL','ORDER_SEND_TO_PRODUCTION','PRODUCT_CREATE','PRODUCT_UPDATE'=>[$body],
            default=>[],
        };
        if (!is_array($rows)) {
            throw new \InvalidArgumentException('Malformed scoped response.');
        }
        foreach ($rows as $row) {
            if (!is_array($row)||array_key_exists('shop_id',$row)
                && $row['shop_id']!==$this->shop && (!is_int($row['shop_id'])||(string)$row['shop_id']!==$this->shop)) {
                throw new \InvalidArgumentException('Mismatched response shop.');
            }
        }
        if (PrintifyApiReadEvidence::supported($operation)) {
            $projection=PrintifyApiReadEvidence::project($operation,$body,$this->shop,$input);
            if ($operation==='PRODUCT_READ' && is_int($body['synthetic_version']??null) && $body['synthetic_version']>0) {
                $projection['synthetic_version']=$body['synthetic_version'];
            }
            return $projection;
        }
        if (in_array($operation,['ORDER_SHIPPING_QUOTE','ORDER_CANCEL','WEBHOOKS_READ','WEBHOOK_CREATE','WEBHOOK_UPDATE','WEBHOOK_DELETE'],true)) {
            $plan['operation']=match($operation){'ORDER_SHIPPING_QUOTE'=>'SHIPPING_QUOTE','WEBHOOKS_READ'=>'WEBHOOK_READ',default=>$operation};
            return PrintifyLifecycleEvidence::project($plan,$body);
        }
        if ($operation==='UPLOADS_READ') {
            return self::projectUploadPage($plan['input'],$body);
        }
        if ($operation==='ORDER_SEND_TO_PRODUCTION' && $body===[]) {
            return ['id'=>$input['order_id'],'accepted'=>true];
        }
        $expected=match($operation){'IMAGE_READ'=>$input['image_id'],'PRODUCT_UPDATE'=>$input['product_id'],'ORDER_SEND_TO_PRODUCTION'=>$input['order_id'],default=>null};
        if (!self::identity($body['id']??null)||$expected!==null&&$body['id']!==$expected) {
            throw new \InvalidArgumentException('Mismatched response identity.');
        }
        if ($operation==='IMAGE_UPLOAD'||$operation==='IMAGE_READ') {
            $image=self::projectImage($body);
            if ($operation==='IMAGE_UPLOAD'&&$image['file_name']!==$input['file_name']) {
                throw new \InvalidArgumentException('Mismatched image filename.');
            }
            return $image;
        }
        $projection=['id'=>$body['id']];
        if (is_int($body['synthetic_version']??null)&&$body['synthetic_version']>0) {
            $projection['synthetic_version']=$body['synthetic_version'];
        }
        return $projection;
    }

    /** Only the private in-memory transport's own synthetic images are returned. */
    private static function projectUploadPage(array $request,array $body): array
    {
        $page=$request['page'];
        $limit=$request['limit'];
        $last=$body['last_page']??null;
        if (!is_array($body['data']??null)||!array_is_list($body['data'])||count($body['data'])>$limit
            ||($body['current_page']??null)!==$page||($body['per_page']??null)!==$limit
            ||!is_int($last)||$last<$page||$last>2147483647) {
            throw new \InvalidArgumentException('Malformed synthetic upload page.');
        }
        $seen=[];
        $rows=[];
        foreach ($body['data'] as $row) {
            $image=self::projectImage($row);
            if (isset($seen[$image['id']])) {
                throw new \InvalidArgumentException('Duplicate synthetic image identity.');
            }
            $seen[$image['id']]=true;
            $rows[]=$image;
        }
        return ['data'=>$rows,'current_page'=>$page,'last_page'=>$last,'per_page'=>$limit,
            'next_page'=>$page<$last?['page'=>$page+1,'limit'=>$limit]:null,'pagination_followed'=>false];
    }

    private static function projectImage(array $body): array
    {
        if (!self::identity($body['id']??null)
            ||!is_string($body['file_name']??null)||preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,119}\.png$/D',$body['file_name'])!==1
            ||!is_int($body['width']??null)||$body['width']<1||$body['width']>10000
            ||!is_int($body['height']??null)||$body['height']<1||$body['height']>10000) {
            throw new \InvalidArgumentException('Malformed image metadata.');
        }
        return array_intersect_key($body,array_flip(['id','file_name','width','height']));
    }

    private static function identity(mixed $value): bool
    {
        return is_string($value)&&preg_match('/^[A-Za-z0-9_-]{1,160}$/D',$value)===1;
    }

    private static function result(string $state,int $status,string $reason): array
    {
        return ['state'=>$state,'status'=>$status,'reason'=>$reason,'evidence_class'=>'SYNTHETIC_PRINTIFY',
            'synthetic'=>true,'provider_execution_authorized'=>false,'provider_outcome_confirmed'=>false,
            'retry_permitted'=>false];
    }
}
