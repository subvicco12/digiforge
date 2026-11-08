<?php
declare(strict_types=1);
namespace DigiForge\Tests;
use DigiForge\POD\PrintifyApiRequest;
use DigiForge\POD\PrintifyOrderEvidence;
use DigiForge\POD\PrintifyWebhookEvidence;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/../../includes/POD/PrintifyApiRequest.php';
require_once __DIR__.'/../../includes/POD/PrintifyApiReadEvidence.php';
require_once __DIR__.'/../../includes/POD/PrintifyOrderEvidence.php';
require_once __DIR__.'/../../includes/POD/PrintifyWebhookEvidence.php';
final class PrintifyApiBoundaryTest extends TestCase
{
    public function testAccountReadProjectionDisclosesOnlyBoundShop(): void
    {
        $rows=[['id'=>12,'title'=>'Bound','sales_channel'=>'custom'],['id'=>13,'title'=>'Other','sales_channel'=>'etsy']];
        $r=\DigiForge\POD\PrintifyApiReadEvidence::project('SHOPS_READ',$rows,'12',[]);
        self::assertCount(1,$r['shops']);self::assertSame(12,$r['shops'][0]['id']);
        self::assertFalse($r['other_shop_data_disclosed']);
    }
    public function testAbsentConfiguredShopFailsReadback(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        \DigiForge\POD\PrintifyApiReadEvidence::project('SHOPS_READ',[],'12',[]);
    }
    public function testDuplicateOrderEvidenceRejected(): void
    {
        $o=['id'=>'o1','status'=>'pending','line_items'=>[]];
        $this->expectException(\InvalidArgumentException::class);
        \DigiForge\POD\PrintifyApiReadEvidence::project('ORDERS_READ',['current_page'=>1,'data'=>[$o,$o]],'12',[]);
    }
    public function testUnknownInputCannotPersistCredentials(): void
    {
        $this->expectException(\InvalidArgumentException::class);PrintifyApiRequest::prepare('SHOPS_READ','12',['token'=>'never-store']);
    }
    public function testNestedDeliveryMetadataCannotLeakThroughProjection(): void
    {
        $order=['id'=>'o1','status'=>'fulfilled','line_items'=>[],'shipments'=>[['carrier'=>'Fixture','number'=>'fixture','url'=>'https://example.test/track','delivered_at'=>['email'=>'never-store']]]];
        $this->expectException(\InvalidArgumentException::class);PrintifyOrderEvidence::project($order,'o1');
    }
    public function testMissingCatalogCostCannotBecomeUsdZero(): void
    {
        require_once __DIR__.'/../../includes/POD/PrintifyCatalogContract.php';
        $this->expectException(\InvalidArgumentException::class);
        \DigiForge\POD\PrintifyCatalogContract::normalizeCatalogVariant(['blueprint_id'=>1,'print_provider_id'=>2,'variant_id'=>3]);
    }
    public function testOfficialReadContractsAreBoundedAndHaveNoAuthority(): void
    {
        foreach (['SHOPS_READ'=>'/v1/shops.json','PROVIDERS_READ'=>'/v1/catalog/print_providers.json','PROVIDER_READ'=>'/v1/catalog/print_providers/7.json','PRODUCTS_READ'=>'/v1/shops/12/products.json?page=1&limit=10','ORDERS_READ'=>'/v1/shops/12/orders.json?page=1&limit=10','ORDER_READ'=>'/v1/shops/12/orders/order_1.json','WEBHOOKS_READ'=>'/v1/shops/12/webhooks.json'] as $op=>$endpoint) {
            $request=PrintifyApiRequest::prepare($op,'12',$op==='PROVIDER_READ'?['provider_id'=>7]:($op==='ORDER_READ'?['order_id'=>'order_1']:[]));
            self::assertSame($endpoint,$request['endpoint']);self::assertSame('GET',$request['method']);self::assertFalse($request['external_execution_authorized']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D',$request['fingerprint']);
        }
    }
    public function testReadPageCannotBecomeArbitraryUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);PrintifyApiRequest::prepare('ORDERS_READ','12',['page'=>'https://evil.test']);
    }
    public function testOrderListRejectsUnsupportedPageSize(): void
    {
        $this->expectException(\InvalidArgumentException::class);PrintifyApiRequest::prepare('ORDERS_READ','12',['limit'=>11]);
    }
    public function testProviderMappingsSeparateShopsWithoutEnablingInactiveShop(): void
    {
        self::assertFalse(PrintifyApiRequest::mapping('standard_pod','13',['personalized_pod'=>'12'])['execution_authorized']);
        $this->expectException(\InvalidArgumentException::class);PrintifyApiRequest::mapping('standard_pod','12',['personalized_pod'=>'12']);
    }
    public function testPublicationIsExplicitAndNotAuthorizedByPreparation(): void
    {
        $request=PrintifyApiRequest::prepare('PRODUCT_PUBLISH','12',['product_id'=>'p1','title'=>true,'images'=>false]);
        self::assertSame('POST',$request['method']);self::assertFalse($request['external_execution_authorized']);
        $this->expectException(\InvalidArgumentException::class);PrintifyApiRequest::prepare('PRODUCT_PUBLISH','12',['product_id'=>'p1','title'=>'yes']);
    }
    private function order(): array
    {
        return ['external_id'=>'local-order-1','line_items'=>[['product_id'=>'existing_1','variant_id'=>7,'quantity'=>1]],'shipping_method'=>1,'is_printify_express'=>false,'is_economy_shipping'=>false,'send_shipping_notification'=>false,'address_to'=>['first_name'=>'Test','last_name'=>'Fixture','email'=>'fixture@example.test','phone'=>'','country'=>'US','region'=>'CA','address1'=>'Fixture address','address2'=>'','city'=>'Fixture','zip'=>'00000']];
    }
    public function testValidOrderRequiresNoFabricatedShippingOrImplicitProductCreation(): void
    {
        PrintifyOrderEvidence::validate($this->order());self::assertTrue(true);
        $order=$this->order();$order['line_items'][0]['print_areas']=[];
        $this->expectException(\InvalidArgumentException::class);PrintifyOrderEvidence::validate($order);
    }
    public function testConflictingShippingFlagsRejected(): void
    {
        $order=$this->order();$order['is_economy_shipping']=true;
        $this->expectException(\InvalidArgumentException::class);PrintifyOrderEvidence::validate($order);
    }
    public function testNegativeQuantityRejected(): void
    {
        $order=$this->order();$order['line_items'][0]['quantity']=-1;
        $this->expectException(\InvalidArgumentException::class);PrintifyOrderEvidence::validate($order);
    }
    public function testReadbackOmitsCustomerDataAndPreservesUnknownCosts(): void
    {
        $e=PrintifyOrderEvidence::project(['id'=>'o1','status'=>'pending','line_items'=>[],'address_to'=>['email'=>'private@example.test']],'o1');
        self::assertNull($e['cost']);self::assertNull($e['shipping_cost']);self::assertArrayNotHasKey('address_to',$e);self::assertFalse($e['production_certified']);
    }
    public function testOrderIdentityMismatchRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);PrintifyOrderEvidence::project(['id'=>'other','status'=>'pending','line_items'=>[]],'o1');
    }
    private function event(array $changes=[]): string
    {
        return json_encode(array_replace_recursive(['id'=>'e1b792a1-cac9-4e8f-a4af-15e39fcbeaf1','type'=>'order:created','created_at'=>'2026-11-08 10:00:00+00:00','resource'=>['id'=>'o1','type'=>'order','data'=>null]],$changes),JSON_THROW_ON_ERROR);
    }
    private function verify(string $raw, ?string $signature=null): array
    {
        $secret=str_repeat('s',32);return PrintifyWebhookEvidence::verify($raw,$signature??'sha256='.hash_hmac('sha256',$raw,$secret),$secret,'12',strtotime('2026-11-08 10:01:00 UTC'));
    }
    public function testSignedWebhookIsEvidenceOnlyAndReplayRequiresDurableClaim(): void
    {
        $e=$this->verify($this->event());self::assertTrue($e['durable_replay_claim_required']);self::assertFalse($e['business_transition_authorized']);
        self::assertSame('DUPLICATE_NO_TRANSITION',PrintifyWebhookEvidence::replayDisposition($e,$e));
        $different=$e;$different['payload_sha256']=str_repeat('a',64);self::assertSame('CONFLICT_REJECT',PrintifyWebhookEvidence::replayDisposition($e,$different));
    }
    public function testInvalidSignatureRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);$this->verify($this->event(),'sha256='.str_repeat('0',64));
    }
    public function testCrossShopWebhookRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);$this->verify($this->event(['resource'=>['data'=>['shop_id'=>13]]]));
    }
    public function testExpiredWebhookRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);$this->verify($this->event(['created_at'=>'2026-11-06 10:00:00+00:00']));
    }
    public function testResourceTypeMismatchRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);$this->verify($this->event(['resource'=>['type'=>'product']]));
    }
    public function testSignedMalformedJsonRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);$this->verify('{');
    }
}
