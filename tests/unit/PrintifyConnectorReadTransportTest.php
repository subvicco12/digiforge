<?php
declare(strict_types=1);
use DigiForge\POD\PrintifyCatalogClient;
use DigiForge\POD\PrintifyConnectorReadContract;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/../../includes/POD/PrintifyCatalogClient.php';
if (is_file(__DIR__.'/../../includes/POD/PrintifyConnectorReadContract.php')) require_once __DIR__.'/../../includes/POD/PrintifyConnectorReadContract.php';
if (!class_exists('WP_Error')) { class WP_Error { public function __construct(public string $code='',public string $message='',public array $data=[]) {} public function get_error_data():array{return $this->data;} } }
final class PrintifyConnectorReadTransportTest extends TestCase
{
    public function testContractAndTransportAreAvailable(): void { self::assertTrue(class_exists(PrintifyConnectorReadContract::class)); self::assertTrue(method_exists(PrintifyCatalogClient::class,'connectorRead')); }
    public function testAllowlistAndStrictInput(): void {
        foreach (['SHOPS_READ','BLUEPRINTS_READ','PROVIDERS_READ','UPLOADS_READ','PRODUCTS_READ','ORDERS_READ'] as $op) { $r=PrintifyConnectorReadContract::prepare($op,'123'); self::assertSame('GET',$r['method']); self::assertSame('123',$r['provider_shop_id']); }
        foreach ([['PRODUCT_PUBLISH',[]],['PRODUCTS_READ',['page'=>4]],['PRODUCTS_READ',['limit'=>'2']],['SHOPS_READ',['endpoint'=>'https://evil.test']],['PRODUCT_READ',['product_id'=>'../bad']]] as [$op,$input]) { try { PrintifyConnectorReadContract::prepare($op,'123',$input); self::fail('Unsafe input accepted'); } catch (InvalidArgumentException $e) { self::assertNotSame('',$e->getMessage()); } }
    }
    public function testAllDetailEndpointsAreFixedAndRevalidated(): void {
        $cases=[
            ['BLUEPRINT_READ',['blueprint_id'=>5],'/v1/catalog/blueprints/5.json'],
            ['PROVIDER_READ',['provider_id'=>7],'/v1/catalog/print_providers/7.json'],
            ['VARIANTS_READ',['blueprint_id'=>5,'provider_id'=>7],'/v1/catalog/blueprints/5/print_providers/7/variants.json'],
            ['SHIPPING_READ',['blueprint_id'=>5,'provider_id'=>7,'shipping_method'=>'express'],'/v2/catalog/blueprints/5/print_providers/7/shipping/express.json'],
            ['PRODUCT_READ',['product_id'=>'abc_123'],'/v1/shops/123/products/abc_123.json'],
            ['ORDER_READ',['order_id'=>'abc-123'],'/v1/shops/123/orders/abc-123.json'],
            ['UPLOAD_READ',['upload_id'=>'abc123'],'/v1/uploads/abc123.json'],
            ['UPLOADS_READ',['page'=>3,'limit'=>1],'/v1/uploads.json?page=3&limit=1'],
        ];
        foreach ($cases as [$op,$input,$endpoint]) { $request=PrintifyConnectorReadContract::prepare($op,'123',$input); self::assertSame($endpoint,$request['endpoint']); self::assertSame($request,PrintifyConnectorReadContract::validate($request)); }
    }
    public function testRevokedPostResponseAdmissionSuppressesResult(): void {
        $phases=[];$client=new PrintifyCatalogClient(fn()=>['response'=>['code'=>200],'body'=>'[]']);
        $result=$client->connectorRead('secret',PrintifyConnectorReadContract::prepare('SHOPS_READ','123'),function($phase)use(&$phases){$phases[]=$phase;return $phase==='before';});
        self::assertInstanceOf(WP_Error::class,$result);self::assertSame(['before','after'],$phases);
    }
    public function testOneBoundedAttemptWithAdmissionOnBothSides(): void {
        $calls=[]; $phases=[];
        $client=new PrintifyCatalogClient(function($url,$args)use(&$calls){$calls[]=[$url,$args];return ['response'=>['code'=>200],'headers'=>[],'body'=>'{"data":[]}'];});
        self::assertSame(['data'=>[]],$client->connectorRead('secret',PrintifyConnectorReadContract::prepare('PRODUCTS_READ','123'),function($phase)use(&$phases){$phases[]=$phase;return true;}));
        self::assertSame(['before','after'],$phases); self::assertCount(1,$calls); self::assertSame('https://api.printify.com/v1/shops/123/products.json?page=1&limit=10',$calls[0][0]); self::assertSame(0,$calls[0][1]['redirection']); self::assertSame(1048577,$calls[0][1]['limit_response_size']);
    }
    public function testDeniedAndTamperedRequestsNeverReachTransport(): void {
        $calls=0;$client=new PrintifyCatalogClient(function()use(&$calls){$calls++;return [];});$r=PrintifyConnectorReadContract::prepare('SHOPS_READ','123');
        self::assertInstanceOf(WP_Error::class,$client->connectorRead('secret',$r,fn()=>false));$r['endpoint']='https://evil.test';self::assertInstanceOf(WP_Error::class,$client->connectorRead('secret',$r,fn()=>true));self::assertSame(0,$calls);
    }
    public function testUnsafeResponsesAndExceptionsAreSanitized(): void {
        foreach (['secret','null','{"data":"bad"}',str_repeat('x',1048577)] as $body) { $client=new PrintifyCatalogClient(fn()=>['response'=>['code'=>200],'body'=>$body]); self::assertInstanceOf(WP_Error::class,$client->connectorRead('secret',PrintifyConnectorReadContract::prepare('PRODUCTS_READ','123'),fn()=>true)); }
        $client=new PrintifyCatalogClient(function(){throw new RuntimeException('secret');});self::assertInstanceOf(WP_Error::class,$client->connectorRead('secret',PrintifyConnectorReadContract::prepare('SHOPS_READ','123'),fn()=>true));
        foreach ([401,403,404,429,500] as $status) { $calls=0;$client=new PrintifyCatalogClient(function()use($status,&$calls){$calls++;return ['response'=>['code'=>$status],'headers'=>['retry-after'=>'999999'],'body'=>'{}'];});self::assertInstanceOf(WP_Error::class,$client->connectorRead('secret',PrintifyConnectorReadContract::prepare('SHOPS_READ','123'),fn()=>true));self::assertSame(1,$calls); }
    }
 public function testOversizedCredentialAndUnexpectedSuccessStatusDeny():void{$calls=0;$c=new PrintifyCatalogClient(function()use(&$calls){$calls++;return ['response'=>['code'=>201],'body'=>'[]'];});$r=PrintifyConnectorReadContract::prepare('SHOPS_READ','123');self::assertInstanceOf(WP_Error::class,$c->connectorRead(str_repeat('a',8193),$r,fn()=>true));self::assertSame(0,$calls);self::assertInstanceOf(WP_Error::class,$c->connectorRead('synthetic',$r,fn()=>true));}
 public function testJsonEscapedSlashTokenCannotEscapeResponseBoundary():void{$token='abc/def';$c=new PrintifyCatalogClient(fn()=>['response'=>['code'=>200],'body'=>json_encode(['id'=>'p1','title'=>$token])]);self::assertInstanceOf(WP_Error::class,$c->connectorRead($token,PrintifyConnectorReadContract::prepare('PRODUCT_READ','123',['product_id'=>'p1']),fn()=>true));}
}
