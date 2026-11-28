<?php
declare(strict_types=1);

namespace DigiForgePrintifyApi;

use DigiForgePrintifyApi\Engine\PrintifyCatalogReadContract;
use DigiForgePrintifyApi\Engine\POD\{PrintifyApiOutcome, PrintifyApiReadEvidence, PrintifyApiRequest, PrintifyLifecycleEvidence, PrintifyLifecycleRequest, PrintifyOrderEvidence, PrintifyProductRequest, PrintifyProductResponse, PrintifySupportContract, PrintifyUploadContract};

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
        'POD/PrintifyUploadContract.php', 'POD/PrintifySupportContract.php', 'POD/PrintifyProductResponse.php',
    ];

    private const CATALOG = [
        'BLUEPRINTS_READ'=>'blueprints', 'BLUEPRINT_READ'=>'blueprint',
        'BLUEPRINT_PROVIDERS_READ'=>'providers', 'BLUEPRINT_PROVIDER_READ'=>'provider',
        'VARIANTS_READ'=>'variants', 'VARIANT_READ'=>'variant', 'SIZE_GUIDE_READ'=>'size_guide',
        'CATALOG_SHIPPING_READ'=>'shipping', 'CATALOG_SHIPPING_METHOD_READ'=>'shipping_method',
    ];

    private const OTHER = [
        'SHOPS_READ','PROVIDERS_READ','PROVIDER_READ','IMAGE_UPLOAD','IMAGE_READ','UPLOADS_READ','IMAGE_ARCHIVE',
        'PRODUCT_CREATE','PRODUCT_READ','MOCKUP_SELECTION_READ','MOCKUP_SELECTION_UPDATE','PRODUCTS_READ','PRODUCT_UPDATE','PRODUCT_DELETE','PRODUCT_PUBLISH','PRODUCT_GPSR_READ','ORDER_SHIPPING_QUOTE',
        'ORDER_CREATE','ORDER_READ','ORDERS_READ','ORDER_CANCEL','LINE_ITEM_CANCEL','ORDER_SEND_TO_PRODUCTION','WEBHOOKS_READ',
        'WEBHOOK_CREATE','WEBHOOK_UPDATE','WEBHOOK_DELETE',
        'PRODUCT_PUBLISH_SUCCEEDED','PRODUCT_PUBLISH_FAILED','PRODUCT_UNPUBLISH_NOTIFY','CATALOG_SHIPPING_V1_READ',
        'SUPPORT_LIST','SUPPORT_DETAIL','SUPPORT_REFUND','SUPPORT_REPRINT','SUPPORT_ADDRESS_CHANGE',
        'PERSONALIZATION_OPTIONS','PERSONALIZATION_CONFIGURE','PREVIEW_CREATE','PREVIEW_READ','ORDER_ADDRESS_CHANGE',
        'SHOP_DISCONNECT','ORDER_EXPRESS_CREATE','WEBHOOK_SIMULATE',
    ];

    public function __construct(private readonly string $shop, private readonly SyntheticPrintifyTransport $transport)
    {
        if (preg_match('/^[1-9][0-9]{0,18}$/D',$shop)!==1) {
            throw new \InvalidArgumentException('Exact synthetic shop required.');
        }
    }

    public static function operations(): array
    {
        return array_merge(array_keys(self::CATALOG), self::OTHER);
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
            if ($status === 422 && in_array($operation, ['ORDER_SHIPPING_QUOTE','ORDER_CREATE','ORDER_EXPRESS_CREATE'], true)
                && ($response['body']['code'] ?? null) === 'shipping_unavailable') {
                return self::result('UNAVAILABLE', 422, 'FIXTURE_SHIPPING_UNAVAILABLE');
            }
            $outcome=PrintifyApiOutcome::classify($status,$response['headers']??[],1,$plan['method'],1767225600);
            if ($outcome['category']!=='SUCCESS') {
                return self::result($outcome['category'],$status,$outcome['reason_code'])
                    + ['bounded_retry_after'=>$outcome['bounded_retry_after']];
            }
            $body=$response['body'];
            if (strlen(json_encode($body,JSON_THROW_ON_ERROR,24))>262144) {
                throw new \InvalidArgumentException('Response exceeds fixture bounds.');
            }
            $projection = $this->project($operation,$input,$plan,$body,$status);
            if ($operation === 'ORDER_SHIPPING_QUOTE') {
                $method = [1=>'standard',2=>'priority',3=>'printify_express',4=>'economy'][$input['order']['shipping_method']];
                if ($projection['rates_minor'][$method] === null) {
                    return self::result('UNAVAILABLE', $status, 'FIXTURE_SHIPPING_METHOD_UNAVAILABLE') + ['data'=>$projection];
                }
            }
            return self::result('SYNTHETIC_OBSERVED',$status,'NONE') + ['data'=>$projection];
        } catch (\Throwable) {
            return self::result($write?'UNKNOWN':'DENIED',0,'RESPONSE_VALIDATION');
        }
    }

    private function prepare(string $operation,array $input): array
    {
        $root='/v1/shops/'.$this->shop.'/orders';
        if (in_array($operation,['MOCKUP_SELECTION_READ','MOCKUP_SELECTION_UPDATE'],true)) {
            $id=$input['product_id']??null;
            if (!self::identity($id)) throw new \InvalidArgumentException('Exact mockup product required.');
            $endpoint='/v1/shops/'.$this->shop.'/products/'.$id.'/mockup-selection.json';
            if ($operation==='MOCKUP_SELECTION_READ' && count($input)===1) {
                return ['method'=>'GET','endpoint'=>$endpoint,'body'=>[],'provider_shop_id'=>$this->shop,'operation'=>$operation];
            }
            require_once __DIR__.'/ProtectedGet.php';
            $body=$input['selection']??null;
            if ($operation!=='MOCKUP_SELECTION_UPDATE' || count($input)!==4 || !is_int($input['expected_version']??null)
                || !is_array($body) || ($input['selection_sha256']??null)!==ProtectedGet::digest($body)) {
                throw new \InvalidArgumentException('Exact mockup replacement digest and revision required.');
            }
            return ['method'=>'PATCH','endpoint'=>$endpoint,'body'=>$body,'expected_version'=>$input['expected_version'],
                'provider_shop_id'=>$this->shop,'operation'=>$operation];
        }
        if (isset(self::CATALOG[$operation])) {
            if (array_key_exists('kind',$input)) {
                throw new \InvalidArgumentException('Catalog selector must use its typed route.');
            }
            $plan=['method'=>'GET','endpoint'=>PrintifyCatalogReadContract::endpoint(['kind'=>self::CATALOG[$operation]]+$input),'body'=>[]];
        } elseif ($operation==='CATALOG_SHIPPING_V1_READ') {
            if (array_key_exists('kind',$input)) {
                throw new \InvalidArgumentException('Catalog selector must use its typed route.');
            }
            $v2=PrintifyCatalogReadContract::endpoint(['kind'=>'shipping']+$input);
            $plan=['method'=>'GET','endpoint'=>'/v1/'.substr($v2,4),'body'=>[]];
        } elseif ($operation==='ORDER_ADDRESS_CHANGE') {
            $validated=PrintifySupportContract::prepare('SUPPORT_ADDRESS_CHANGE',$this->shop,$input);
            $plan=['method'=>'POST','endpoint'=>$root.'/'.$validated['scope']['order_id'].'/address_change.json',
                'body'=>$validated['body']];
        } elseif (str_starts_with($operation,'SUPPORT_')) {
            $plan=PrintifySupportContract::prepare($operation,$this->shop,$input);
        } elseif ($operation==='SHOP_DISCONNECT' && $input===[]) {
            $plan=['method'=>'DELETE','endpoint'=>'/v1/shops/'.$this->shop.'/connection.json','body'=>[]];
        } elseif ($operation==='ORDER_EXPRESS_CREATE') {
            self::validateExpress($input);
            $plan=['method'=>'POST','endpoint'=>$root.'/express.json','body'=>$input];
        } elseif ($operation==='ORDER_CREATE') {
            PrintifyOrderEvidence::validate($input);
            $plan=['method'=>'POST','endpoint'=>$root.'.json','body'=>$input];
        } elseif ($operation==='ORDER_SEND_TO_PRODUCTION') {
            if (array_keys($input)!==['order_id'] || !self::identity($input['order_id'])) {
                throw new \InvalidArgumentException('Exact order required.');
            }
            $plan=['method'=>'POST','endpoint'=>$root.'/'.$input['order_id'].'/send_to_production.json','body'=>[]];
        } elseif (in_array($operation,['ORDER_SHIPPING_QUOTE','ORDER_CANCEL','LINE_ITEM_CANCEL'],true)) {
            $plan=PrintifyLifecycleRequest::prepare($operation==='ORDER_SHIPPING_QUOTE'?'SHIPPING_QUOTE':$operation,$this->shop,$input);
        } elseif (in_array($operation,['WEBHOOK_CREATE','WEBHOOK_UPDATE','WEBHOOK_DELETE','WEBHOOK_SIMULATE'],true)) {
            $plan=$this->prepareWebhook($operation,$input);
        } elseif (in_array($operation,['UPLOADS_READ','IMAGE_ARCHIVE'],true)) {
            $plan=PrintifyUploadContract::prepare($operation,$this->shop,$input);
        } elseif (in_array($operation,['PRODUCT_UPDATE','PRODUCT_DELETE','PRODUCT_PUBLISH','PRODUCT_PUBLISH_SUCCEEDED','PRODUCT_PUBLISH_FAILED','PRODUCT_UNPUBLISH_NOTIFY'],true)) {
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
        $endpoint.='/'.$input['webhook_id'];
        if ($operation==='WEBHOOK_SIMULATE' && count($input)===1) {
            return ['method'=>'POST','endpoint'=>$endpoint.'/simulate','body'=>[]];
        }
        $endpoint.='.json';
        if ($operation==='WEBHOOK_DELETE'&&count($input)===1) {
            return ['method'=>'DELETE','endpoint'=>$endpoint.'?host=webhooks.example.invalid','body'=>[],'input'=>$input];
        }
        if ($operation==='WEBHOOK_UPDATE'&&count($input)===2&&in_array($input['url']??null,SyntheticPrintifyTransport::WEBHOOK_URLS,true)) {
            return ['method'=>'PUT','endpoint'=>$endpoint,'body'=>['url'=>$input['url']],'input'=>$input];
        }
        throw new \InvalidArgumentException('Exact synthetic webhook request required.');
    }

    /** The fixture requires an external ID for durable deduplication. */
    public static function validateExpress(array $input): void
    {
        if (array_diff(array_keys($input),['external_id','label','line_items','shipping_method',
            'send_shipping_notification','address_to'])!==[]
            || ($input['shipping_method']??3)!==3 || ($input['send_shipping_notification']??false)!==false) {
            throw new \InvalidArgumentException('Unsupported synthetic Express shipping options.');
        }
        PrintifyOrderEvidence::validate($input+['shipping_method'=>3,'is_printify_express'=>true,
            'is_economy_shipping'=>false,'send_shipping_notification'=>false]);
    }

    private function projectExpress(array $input,array $body): array
    {
        $rows=$body['data']??null;
        if (array_key_exists('shop_id',$body) && !self::matchesShop($body['shop_id'],$this->shop)) {
            throw new \InvalidArgumentException('Mismatched Express response envelope shop.');
        }
        if (!is_array($rows)||!array_is_list($rows)||$rows===[]||count($rows)>2) {
            throw new \InvalidArgumentException('Malformed Express order response.');
        }
        $remaining=$input['line_items'];
        $result=[];
        $types=[];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_array($row['attributes'] ?? null)) {
                throw new \InvalidArgumentException('Malformed Express order response member.');
            }
            $id=$row['id']??null;
            $attributes=$row['attributes'];
            foreach ([$row, $attributes] as $part) {
                if (array_key_exists('shop_id', $part) && !in_array($part['shop_id'], [$this->shop, (int)$this->shop], true)) {
                    throw new \InvalidArgumentException('Mismatched Express response shop.');
                }
                self::assertOptionalEcho($part, $input, ['external_id']);
            }
            $type=$attributes['fulfilment_type']??null;
            $lines=$attributes['line_items']??null;
            if (($row['type']??null)!=='order'||!self::identity($id)||isset($result[$id])
                ||!in_array($type,['express','ordinary'],true)||isset($types[$type])||!is_array($lines)||!array_is_list($lines)||$lines===[]) {
                throw new \InvalidArgumentException('Unexpected Express order identity or response.');
            }
            $types[$type]=true;
            $projected=[];
            $lineIds=[];
            foreach ($lines as $line) {
                if (!is_array($line) || (array_key_exists('id',$line)
                    && (!self::identity($line['id']) || isset($lineIds[$line['id']])))) {
                    throw new \InvalidArgumentException('Missing or conflicting Express line identity.');
                }
                if (isset($line['id'])) {
                    $lineIds[$line['id']]=true;
                }
                $identity=array_intersect_key($line,array_flip(['product_id','variant_id','quantity']));
                $match=false;
                foreach ($remaining as $index=>$expected) {
                    if (($identity['product_id']??null)===$expected['product_id']
                        && ($identity['variant_id']??null)===$expected['variant_id']
                        && ($identity['quantity']??null)===$expected['quantity']) {
                        $match=$index;
                        break;
                    }
                }
                if ($match===false||($line['status']??null)!=='pending'
                    || !is_int($line['cost']??null)||$line['cost']<0
                    || !is_int($line['shipping_cost']??null)||$line['shipping_cost']<0) {
                    throw new \InvalidArgumentException('Unexpected Express order line.');
                }
                unset($remaining[$match]);
                $projected[]=$identity+array_intersect_key($line,array_flip(['id','status','cost','shipping_cost']));
            }
            $result[$id]=['id'=>$id,'fulfilment_type'=>$type,'line_items'=>$projected];
        }
        if ($remaining!==[]) {
            throw new \InvalidArgumentException('Express lines are missing.');
        }
        return ['orders'=>array_values($result)];
    }

    private function project(string $operation,array $input,array $plan,array $body,int $status): array
    {
        if (($plan['method'] ?? null) === 'GET' && $status !== 200) {
            throw new \InvalidArgumentException('Unexpected successful read status.');
        }
        if ($operation==='MOCKUP_SELECTION_READ') {
            if ($status!==200) throw new \InvalidArgumentException('Expected mockup selection.');
            require_once __DIR__.'/ProtectedGet.php';
            return ['product_id'=>$input['product_id'],'shop_id'=>$this->shop,'mockups'=>ProtectedGet::mockupRows($body)];
        }
        if ($operation==='MOCKUP_SELECTION_UPDATE') {
            if ($status!==204 || $body!==[]) throw new \InvalidArgumentException('Expected empty mockup acknowledgment.');
            return ['product_id'=>$input['product_id'],'selection_sha256'=>$input['selection_sha256']];
        }
        if ($operation==='ORDER_EXPRESS_CREATE' && $status===200) {
            return $this->projectExpress($input,$body);
        }
        if (in_array($operation,['SHOP_DISCONNECT','WEBHOOK_SIMULATE'],true)
            && $status===($operation==='SHOP_DISCONNECT'?200:204) && $body===[]) {
            return $operation==='SHOP_DISCONNECT'?['disconnected'=>true]:['webhook_id'=>$input['webhook_id']];
        }
        if (in_array($operation,['SHOP_DISCONNECT','WEBHOOK_SIMULATE','ORDER_EXPRESS_CREATE'],true)) {
            throw new \InvalidArgumentException('Unexpected synthetic acknowledgement.');
        }
        if (isset(self::CATALOG[$operation])) {
            return PrintifyCatalogReadContract::select($body,['kind'=>self::CATALOG[$operation]]+$input);
        }
        if (in_array($operation,['PERSONALIZATION_OPTIONS','PERSONALIZATION_CONFIGURE','PREVIEW_CREATE','PREVIEW_READ'],true)) {
            $expectedStatus=match($operation){'PERSONALIZATION_CONFIGURE'=>201,'PREVIEW_CREATE'=>202,default=>200};
            if ($status!==$expectedStatus||in_array($operation,['PREVIEW_CREATE','PREVIEW_READ'],true)
                && (!in_array($body['status']??null,$operation==='PREVIEW_CREATE'?['pending']:['pending','completed','failed','corrupt'],true)
                    ||($body['mockups']??[])!==[])) {
                throw new \InvalidArgumentException('Unexpected synthetic personalization response.');
            }
            $local=[];
            if ($operation==='PREVIEW_READ' && ($body['status']??null)==='completed') {
                $encoded=$body['synthetic_svg']??null;
                $svg=is_string($encoded)&&strlen($encoded)<=32768?base64_decode($encoded,true):false;
                if ($svg===false || !str_starts_with($svg,'<svg xmlns="http://www.w3.org/2000/svg"')) {
                    throw new \InvalidArgumentException('Missing local preview artifact.');
                }
                $local=['synthetic_svg'=>$encoded,'synthetic_svg_sha256'=>hash('sha256',$svg)];
                $raster=$body['synthetic_raster']??null;
                if ($raster!==null) {
                    $sample=$raster['base64']??null;
                    $bytes=is_string($sample)&&strlen($sample)<=43692?base64_decode($sample,true):false;
                    $image=$bytes===false?false:@getimagesizefromstring($bytes);
                    if ($image===false || $image[0]!==480 || $image[1]!==250
                        || !in_array($image['mime'],['image/png','image/jpeg'],true) || $image['mime']!==($raster['mime']??null)) {
                        throw new \InvalidArgumentException('Malformed synthetic raster preview.');
                    }
                    $local['synthetic_raster']=$raster+['sha256'=>hash('sha256',$bytes)];
                }
            }
            return PrintifyProductResponse::project(['request'=>$plan,'options'=>[]],$body) + $local
                + ['buyer_approval_verified'=>false,'provider_configuration_verified'=>false];
        }
        if ($operation==='ORDER_ADDRESS_CHANGE') {
            $validated=PrintifySupportContract::prepare('SUPPORT_ADDRESS_CHANGE',$this->shop,$input);
            if ($status!==200) {
                throw new \InvalidArgumentException('Unexpected synthetic address change evidence.');
            }
            $projection=PrintifySupportContract::project($validated,$body,$status);
            $projection['operation']=$operation;
            unset($projection['request_fingerprint']);
            return $projection;
        }
        if (str_starts_with($operation,'SUPPORT_')) {
            unset($plan['provider_shop_id']);
            require_once __DIR__.'/ProtectedGet.php';
            return ProtectedGet::checkedSupportProjection(PrintifySupportContract::project($plan,$body,$status));
        }
        if ($operation==='CATALOG_SHIPPING_V1_READ') {
            return self::projectLegacyShipping($body);
        }
        $rows=match($operation){
            'ORDERS_READ','PRODUCTS_READ'=>$body['data']??null,
            'ORDER_READ','PRODUCT_READ','ORDER_CREATE','ORDER_CANCEL','ORDER_SEND_TO_PRODUCTION','PRODUCT_CREATE','PRODUCT_UPDATE','PRODUCT_DELETE','PRODUCT_PUBLISH'=>[$body],
            default=>[],
        };
        if (!is_array($rows)) {
            throw new \InvalidArgumentException('Malformed scoped response.');
        }
        if (in_array($operation,['ORDERS_READ','PRODUCTS_READ'],true)
            && array_key_exists('shop_id',$body) && !self::matchesShop($body['shop_id'],$this->shop)) {
            throw new \InvalidArgumentException('Mismatched response envelope shop.');
        }
        foreach ($rows as $row) {
            if (!is_array($row)||array_key_exists('shop_id',$row)
                && !self::matchesShop($row['shop_id'],$this->shop)) {
                throw new \InvalidArgumentException('Mismatched response shop.');
            }
        }
        if (PrintifyApiReadEvidence::supported($operation)) {
            $projection=PrintifyApiReadEvidence::project($operation,$body,$this->shop,$input);
            if ($operation === 'PRODUCT_READ' && array_key_exists('synthetic_version', $body)) {
                $version = $body['synthetic_version'];
                $pending = array_key_exists('synthetic_publish_pending', $body) ? $body['synthetic_publish_pending'] : false;
                $state = array_key_exists('synthetic_publication_state', $body)
                    ? $body['synthetic_publication_state'] : ($pending ? 'PENDING' : 'UNPUBLISHED');
                $visibilityConflicts = array_key_exists('visible',$body) && (!is_bool($body['visible'])
                    || (array_key_exists('synthetic_publication_state',$body) && $body['visible'] !== ($state === 'PUBLISHED')));
                if (!is_int($version) || $version < 1 || !is_bool($pending)
                    || !in_array($state, ['PENDING','PUBLISHED','UNPUBLISHED','FAILED'], true)
                    || $pending !== ($state === 'PENDING') || $visibilityConflicts) {
                    throw new \InvalidArgumentException('Inconsistent synthetic publication state.');
                }
                $projection += ['synthetic_version'=>$version,'publication_pending'=>$pending,'publication_state'=>$state];
                foreach (['title'=>200,'description'=>10000] as $field=>$limit) {
                    if (!array_key_exists($field,$body)) continue;
                    if (!is_string($body[$field]) || strlen($body[$field]) > $limit) {
                        throw new \InvalidArgumentException('Malformed synthetic product text.');
                    }
                    $projection[$field]=$body[$field];
                }
            }
            if ($operation === 'ORDER_READ') {
                self::bindFixtureShipmentLines($projection, $body);
            } elseif ($operation === 'ORDERS_READ') {
                foreach ($projection['data'] as $index=>&$order) {
                    self::bindFixtureShipmentLines($order, $rows[$index]);
                }
                unset($order);
            }
            return $projection;
        }
        if ($operation==='ORDER_SHIPPING_QUOTE' && $status!==200) {
            throw new \InvalidArgumentException('Unexpected shipping quotation status.');
        }
        if ($operation==='ORDER_CANCEL' && ($status!==200 || ($body['sent_to_production_at']??null)!==null
            || !empty($body['shipments']))) {
            throw new \InvalidArgumentException('Unexpected whole-order cancellation status.');
        }
        if (in_array($operation,['ORDER_SHIPPING_QUOTE','ORDER_CANCEL','WEBHOOKS_READ','WEBHOOK_CREATE','WEBHOOK_UPDATE','WEBHOOK_DELETE'],true)) {
            $plan['operation']=match($operation){'ORDER_SHIPPING_QUOTE'=>'SHIPPING_QUOTE','WEBHOOKS_READ'=>'WEBHOOK_READ',default=>$operation};
            return PrintifyLifecycleEvidence::project($plan,$body);
        }
        if ($operation==='LINE_ITEM_CANCEL') {
            return PrintifyLifecycleEvidence::emptyCancellation($plan,$status,$body===[]?'':'nonempty');
        }
        if ($operation==='IMAGE_ARCHIVE' || $operation==='PRODUCT_DELETE') {
            if ($status!==200 || $body!==[]) {
                throw new \InvalidArgumentException('Malformed synthetic deletion acknowledgement.');
            }
            return $operation==='IMAGE_ARCHIVE'
                ? ['id'=>$input['image_id'],'archived'=>true]
                : ['id'=>$input['product_id'],'deleted'=>true];
        }
        if ($operation==='PRODUCT_PUBLISH') {
            if ($status!==200 || $body!==[]) {
                throw new \InvalidArgumentException('Malformed synthetic publish acknowledgement.');
            }
            return ['id'=>$input['product_id'],'publication_pending'=>true];
        }
        if (in_array($operation,['PRODUCT_PUBLISH_SUCCEEDED','PRODUCT_PUBLISH_FAILED','PRODUCT_UNPUBLISH_NOTIFY'],true)) {
            $expected = $operation==='PRODUCT_UNPUBLISH_NOTIFY' ? [] : ['product_id'=>$input['product_id']];
            if ($status!==200 || $body!==$expected) {
                throw new \InvalidArgumentException('Malformed or mismatched synthetic publication callback acknowledgement.');
            }
            return ['id'=>$input['product_id'],'publication_state'=>match($operation){
                'PRODUCT_PUBLISH_SUCCEEDED'=>'PUBLISHED',
                'PRODUCT_PUBLISH_FAILED'=>'FAILED',
                default=>'UNPUBLISHED',
            }];
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
            if ($operation === 'IMAGE_UPLOAD') {
                if ($status !== 201 || $image['file_name'] !== $input['file_name']) {
                    throw new \InvalidArgumentException('Mismatched image upload response.');
                }
                PrintifyProductResponse::project(['request'=>$plan,'options'=>[]], $body);
            }
            return $image;
        }
        if (in_array($operation, ['PRODUCT_CREATE','PRODUCT_UPDATE'], true)) {
            $version = $body['synthetic_version'] ?? null;
            $expectedVersion = $operation === 'PRODUCT_CREATE' ? 1 : (($plan['expected_version'] ?? 0) + 1);
            if ($status !== ($operation === 'PRODUCT_CREATE' ? 201 : 200) || !is_int($version)
                || $version < 1 || (isset($plan['expected_version']) || $operation === 'PRODUCT_CREATE') && $version !== $expectedVersion) {
                throw new \InvalidArgumentException('Mismatched synthetic product revision.');
            }
            foreach (array_intersect_key($plan['body'], ['title'=>true,'description'=>true]) as $field=>$value) {
                if (($body[$field] ?? null) !== $value) {
                    throw new \InvalidArgumentException('Mismatched synthetic product text.');
                }
            }
            $product = PrintifyApiReadEvidence::project('PRODUCTS_READ', [
                'data'=>[$body],'current_page'=>1,'per_page'=>10,'last_page'=>1,
            ], $this->shop, [])['data'][0];
            foreach (array_intersect_key($plan['body'], array_flip(['blueprint_id','print_provider_id','variants','print_areas'])) as $field=>$value) {
                if ($product[$field] != $value) {
                    throw new \InvalidArgumentException('Mismatched synthetic product configuration.');
                }
            }
        }
        if ($operation === 'ORDER_CREATE' || $operation === 'ORDER_SEND_TO_PRODUCTION') {
            $order = PrintifyOrderEvidence::project($body, $body['id']);
            if ($operation === 'ORDER_CREATE') {
                self::assertOptionalEcho($body, $input, ['shipping_method','is_printify_express','is_economy_shipping','send_shipping_notification']);
                if ($status !== 201 || $order['status'] !== 'on-hold' || $order['external_id'] !== $input['external_id']
                    || $order['shipments'] !== [] || $order['sent_to_production_at'] !== null
                    || self::orderLineIdentity($order['line_items']) !== self::orderLineIdentity($input['line_items'])) {
                    throw new \InvalidArgumentException('Mismatched synthetic order creation response.');
                }
            } elseif ($order['status'] !== 'in-production' || $order['sent_to_production_at'] === null) {
                throw new \InvalidArgumentException('Missing synthetic production observation.');
            }
        }
        $projection=['id'=>$body['id']];
        if (is_int($body['synthetic_version']??null)&&$body['synthetic_version']>0) {
            $projection['synthetic_version']=$body['synthetic_version'];
        }
        return $projection;
    }

    private static function matchesShop(mixed $value,string $shop): bool
    {
        return (is_int($value) || is_string($value)) && (string)$value === $shop;
    }

    /** Fixture-specific linkage. Keep provider shipment semantics with the shared normalizer. */
    private static function bindFixtureShipmentLines(array &$projection,array $order): void
    {
        $eligible = [];
        foreach ($order['line_items'] as $line) {
            if (is_string($line['id'] ?? null) && ($line['status'] ?? null) !== 'canceled') {
                $eligible[$line['id']] = true;
            }
        }
        foreach ($order['shipments'] ?? [] as $index=>$shipment) {
            if (!array_key_exists('line_item_ids',$shipment)) continue;
            $ids = $shipment['line_item_ids'];
            if (!is_array($ids) || !array_is_list($ids) || $ids === [] || count($ids) > count($eligible)) {
                throw new \InvalidArgumentException('Malformed fixture shipment lineage.');
            }
            $seen = [];
            foreach ($ids as $id) {
                if (!is_string($id) || !isset($eligible[$id]) || isset($seen[$id])) {
                    throw new \InvalidArgumentException('Mismatched fixture shipment line.');
                }
                $seen[$id] = true;
            }
            $projection['shipments'][$index]['line_item_ids'] = $ids;
        }
    }

    private static function assertOptionalEcho(array $response, array $request, array $fields): void
    {
        foreach ($fields as $field) {
            if (array_key_exists($field,$response)
                && (!array_key_exists($field,$request) || $response[$field] !== $request[$field])) {
                throw new \InvalidArgumentException('Conflicting optional lifecycle response field.');
            }
        }
    }

    private static function orderLineIdentity(array $lines): array
    {
        return array_map(static fn(array $line): array => [$line['product_id'], $line['variant_id'], $line['quantity']], $lines);
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

    private static function projectLegacyShipping(array $body): array
    {
        $time=$body['handling_time']??null;
        $profiles=$body['profiles']??null;
        if (!is_array($time)||count($time)!==2||($time['unit']??null)!=='day'
            ||!is_int($time['value']??null)||$time['value']<0||$time['value']>365
            ||!is_array($profiles)||!array_is_list($profiles)||count($profiles)>50||count($body)!==2) {
            throw new \InvalidArgumentException('Malformed synthetic legacy shipping catalog.');
        }
        $normalized=[];
        $associations=[];
        foreach ($profiles as $row) {
            if (!is_array($row)||count($row)!==4
                ||!is_array($row['variant_ids']??null)||!array_is_list($row['variant_ids'])||$row['variant_ids']===[]
                ||count($row['variant_ids'])>100||count(array_unique($row['variant_ids'],SORT_REGULAR))!==count($row['variant_ids'])
                ||!is_array($row['countries']??null)||!array_is_list($row['countries'])||$row['countries']===[]
                ||count($row['countries'])>250||count(array_unique($row['countries'],SORT_REGULAR))!==count($row['countries'])) {
                throw new \InvalidArgumentException('Malformed synthetic shipping profile.');
            }
            foreach ($row['variant_ids'] as $id) {
                if (!is_int($id)||$id<1) {
                    throw new \InvalidArgumentException('Malformed synthetic shipping variant.');
                }
            }
            foreach ($row['countries'] as $country) {
                if (!is_string($country)||preg_match('/^(?:[A-Z]{2}|REST_OF_THE_WORLD)$/D',$country)!==1) {
                    throw new \InvalidArgumentException('Malformed synthetic shipping country.');
                }
            }
            foreach ($row['variant_ids'] as $variant) {
                foreach ($row['countries'] as $country) {
                    $key = $variant.':'.$country;
                    if (isset($associations[$key])) {
                        throw new \InvalidArgumentException('Ambiguous synthetic shipping coverage.');
                    }
                    $associations[$key] = true;
                }
            }
            foreach (['first_item','additional_items'] as $key) {
                $cost=$row[$key]??null;
                if (!is_array($cost)||count($cost)!==2||!is_int($cost['cost']??null)
                    ||$cost['cost']<0||$cost['cost']>100000000||!is_string($cost['currency']??null)
                    ||preg_match('/^[A-Z]{3}$/D',$cost['currency'])!==1) {
                    throw new \InvalidArgumentException('Malformed synthetic shipping money.');
                }
            }
            if ($row['first_item']['currency']!==$row['additional_items']['currency']) {
                throw new \InvalidArgumentException('Mismatched synthetic shipping currency.');
            }
            $normalized[]=$row;
        }
        return ['handling_time'=>$time,'profiles'=>$normalized,'quote_is_guarantee'=>false];
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
