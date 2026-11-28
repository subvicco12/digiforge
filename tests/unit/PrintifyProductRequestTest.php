<?php
declare(strict_types=1);
namespace DigiForge\Tests;
use DigiForge\POD\PrintifyProductRequest;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/../../includes/POD/PrintifyProductRequest.php';
final class PrintifyProductRequestTest extends TestCase
{
    private function product(): array
    {
        return ['title'=>'Personalized mug','description'=>'Approved fixed art with native text','blueprint_id'=>5,'print_provider_id'=>7,'variants'=>[['id'=>9,'price'=>1599,'is_enabled'=>true]],'print_areas'=>[['variant_ids'=>[9],'placeholders'=>[['position'=>'front','images'=>[['id'=>'image_1','x'=>0.5,'y'=>0.5,'scale'=>1.0,'angle'=>0]]]]]]];
    }
    private function items(): array { return [['field_id'=>'field_1','type'=>'textbox','input'=>['text'=>'Alex']]]; }
    private function png(): string
    {
        $chunk=static fn(string $type,string $data):string=>pack('N',strlen($data)).$type.$data.hash('crc32b',$type.$data,true);
        return "\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',1,1,8,6,0,0,0)).$chunk('IDAT',gzcompress("\0\xff\0\0\xff")).$chunk('IEND','');
    }
    public function testCreatesExactDocumentedProductRequestWithoutAuthority(): void
    {
        $body=$this->product();$r=PrintifyProductRequest::prepare('PRODUCT_CREATE','12',$body);
        self::assertSame('POST',$r['method']);self::assertSame('/v1/shops/12/products.json',$r['endpoint']);self::assertSame($body,$r['body']);self::assertSame('products.write',$r['required_scope']);self::assertFalse($r['external_execution_authorized']);self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D',$r['fingerprint']);
        self::assertSame($r['fingerprint'],PrintifyProductRequest::prepare('PRODUCT_CREATE','12',array_reverse($body,true))['fingerprint']);
        self::assertNotSame($r['fingerprint'],PrintifyProductRequest::prepare('PRODUCT_CREATE','13',$body)['fingerprint']);
    }
    public function testIntegrityHelperDetectsTamperedBodyEnvelopeAndAuthority(): void
    {
        $request=PrintifyProductRequest::prepare('PRODUCT_CREATE','12',$this->product());
        self::assertTrue(PrintifyProductRequest::intact($request));
        $tampered=$request;$tampered['body']['title']='Unreviewed title';self::assertFalse(PrintifyProductRequest::intact($tampered));
        $tampered=$request;$tampered['external_execution_authorized']=true;self::assertFalse(PrintifyProductRequest::intact($tampered));
        $tampered=$request;$tampered['fingerprint']='invalid';self::assertFalse(PrintifyProductRequest::intact($tampered));
        $tampered=$request;$tampered['approval']=['is_approved'=>true];self::assertFalse(PrintifyProductRequest::intact($tampered));
        $tampered=$request;unset($tampered['body']);self::assertFalse(PrintifyProductRequest::intact($tampered));
    }
    public function testUploadsOnlyCanonicalBoundedVerifiedPng(): void
    {
        $body=['file_name'=>'approved.png','contents'=>base64_encode($this->png())];$r=PrintifyProductRequest::prepare('IMAGE_UPLOAD','12',$body);
        self::assertSame('/v1/uploads/images.json',$r['endpoint']);self::assertSame($body,$r['body']);self::assertSame('uploads.write',$r['required_scope']);
    }
    public function testReadOperationsUseDocumentedRoutes(): void
    {
        foreach ([['IMAGE_READ',['image_id'=>'img_1'],'/v1/uploads/img_1.json','uploads.read'],['PRODUCT_READ',['product_id'=>'prod_1'],'/v1/shops/12/products/prod_1.json','products.read'],['PERSONALIZATION_OPTIONS',['product_id'=>'prod_1'],'/v1/shops/12/products/prod_1/personalization_options.json','products.read'],['PREVIEW_READ',['product_id'=>'prod_1','task_id'=>'task_1'],'/v1/shops/12/products/prod_1/personalization_previews/tasks/task_1.json','products.read']] as [$op,$input,$endpoint,$scope]) {
            $r=PrintifyProductRequest::prepare($op,'12',$input);self::assertSame('GET',$r['method']);self::assertSame($endpoint,$r['endpoint']);self::assertSame([],$r['body']);self::assertSame($scope,$r['required_scope']);self::assertFalse($r['external_execution_authorized']);
        }
    }
    public function testVariantUpdatesRequireCompleteKnownVariantSetAndStripMetadata(): void
    {
        $body=['product_id'=>'prod_1','variants'=>[['id'=>9,'price'=>1599,'is_enabled'=>true],['id'=>10,'price'=>1799,'is_enabled'=>false]],'known_variant_ids'=>[10,9]];
        $r=PrintifyProductRequest::prepare('PRODUCT_UPDATE','12',$body);self::assertSame('PUT',$r['method']);self::assertSame('/v1/shops/12/products/prod_1.json',$r['endpoint']);self::assertSame(['variants'=>$body['variants']],$r['body']);
        $r=PrintifyProductRequest::prepare('PRODUCT_UPDATE','12',['product_id'=>'prod_1','title'=>'Reviewed title']);self::assertSame(['title'=>'Reviewed title'],$r['body']);
    }
    public function testFullVariantUpdatePreservesDisabledZeroPriceButRejectsEnabledZeroPrice(): void
    {
        $variants=[['id'=>9,'price'=>1599,'is_enabled'=>true],['id'=>10,'price'=>0,'is_enabled'=>false]];
        $input=['product_id'=>'prod_1','variants'=>$variants,'known_variant_ids'=>[9,10]];
        $request=PrintifyProductRequest::prepare('PRODUCT_UPDATE','12',$input);
        self::assertSame($variants,$request['body']['variants']);
        $input['variants'][1]['is_enabled']=true;
        $this->expectException(\InvalidArgumentException::class);
        PrintifyProductRequest::prepare('PRODUCT_UPDATE','12',$input);
    }
    public function testPersonalizationAndPreviewsUseNativeTextItems(): void
    {
        $body=['product_id'=>'prod_1','variant_id'=>9,'items'=>$this->items(),'require_all_personalization_fields'=>true];$r=PrintifyProductRequest::prepare('PERSONALIZATION_CONFIGURE','12',$body);
        self::assertSame('POST',$r['method']);self::assertSame('/v1/shops/12/products/prod_1/personalization.json',$r['endpoint']);unset($body['product_id']);self::assertSame($body,$r['body']);
        $body=['product_id'=>'prod_1','external_id'=>'preview_1','variant_ids'=>[9],'personalization'=>$this->items(),'format'=>'png'];$r=PrintifyProductRequest::prepare('PREVIEW_CREATE','12',$body);
        self::assertSame('/v1/shops/12/products/prod_1/personalization_previews.json',$r['endpoint']);unset($body['product_id']);self::assertSame($body,$r['body']);
    }
    /** @dataProvider invalidInputs */
    public function testRejectsMalformedOrUnauthorizedOperation(string $operation,string $shop,array $input): void
    {
        $this->expectException(\InvalidArgumentException::class);PrintifyProductRequest::prepare($operation,$shop,$input);
    }
    public static function invalidInputs(): array
    {
        $variants=[['id'=>9,'price'=>1599,'is_enabled'=>true]];
        return [
            ['PUBLISH','12',[]],['ORDER_CREATE','12',[]],['PRODUCT_READ','../12',['product_id'=>'p']],['PRODUCT_READ','12',['product_id'=>'../p']],['PRODUCT_READ','12',['product_id'=>'p','endpoint'=>'https://evil.test']],
            ['IMAGE_UPLOAD','12',['file_name'=>'a.png','url'=>'https://evil.test/a.png']],['IMAGE_UPLOAD','12',['file_name'=>'../a.png','contents'=>'eA==']],['IMAGE_UPLOAD','12',['file_name'=>'a.png','contents'=>' eA==']],['IMAGE_UPLOAD','12',['file_name'=>'a.png','contents'=>base64_encode("\x89PNG\r\n\x1a\n".str_repeat('x',40))]],['IMAGE_UPLOAD','12',['file_name'=>'a.svg','contents'=>base64_encode('<svg/>')]],['IMAGE_UPLOAD','12',['file_name'=>'a.png','contents'=>str_repeat('A',2800000)]],
            ['PRODUCT_CREATE','12',['title'=>'t']],['PRODUCT_UPDATE','12',['product_id'=>'p']],['PRODUCT_UPDATE','12',['product_id'=>'p','visible'=>true]],['PRODUCT_UPDATE','12',['product_id'=>'p','variants'=>$variants]],['PRODUCT_UPDATE','12',['product_id'=>'p','variants'=>$variants,'known_variant_ids'=>[9,10]]],['PRODUCT_UPDATE','12',['product_id'=>'p','variants'=>$variants,'known_variant_ids'=>[9,9]]],['PRODUCT_UPDATE','12',['product_id'=>'p','variants'=>[['id'=>9,'price'=>15.99,'is_enabled'=>true]],'known_variant_ids'=>[9]]],['PRODUCT_UPDATE','12',['product_id'=>'p','variants'=>[['id'=>9,'price'=>1599,'is_enabled'=>1]],'known_variant_ids'=>[9]]],
            ['PERSONALIZATION_CONFIGURE','12',['product_id'=>'p','variant_id'=>9,'items'=>[['field_id'=>'f','type'=>'photo','input'=>['url'=>'https://evil.test']]],'require_all_personalization_fields'=>true]],['PERSONALIZATION_CONFIGURE','12',['product_id'=>'p','variant_id'=>9,'items'=>[['field_id'=>'f','type'=>'textbox','input'=>['text'=>'A']]],'require_all_personalization_fields'=>false]],['PREVIEW_CREATE','12',['product_id'=>'p','external_id'=>'x','variant_ids'=>[9],'personalization'=>[['field_id'=>'f','type'=>'textbox','input'=>['text'=>'A']]],'format'=>'svg']],['PREVIEW_READ','12',['product_id'=>'p','task_id'=>'%2fsecret']],
        ];
    }
    public function testRejectsVariantAreaMismatchAndInvalidGeometry(): void
    {
        $p=$this->product();$p['print_areas'][0]['variant_ids']=[10];$this->expectException(\InvalidArgumentException::class);PrintifyProductRequest::prepare('PRODUCT_CREATE','12',$p);
    }
    public function testRejectsNonfiniteGeometry(): void
    {
        $p=$this->product();$p['print_areas'][0]['placeholders'][0]['images'][0]['scale']=INF;$this->expectException(\InvalidArgumentException::class);PrintifyProductRequest::prepare('PRODUCT_CREATE','12',$p);
    }
    public function testRejectsPngCrcTampering(): void
    {
        $png=$this->png();$png[29]=chr(ord($png[29])^1);$this->expectException(\InvalidArgumentException::class);PrintifyProductRequest::prepare('IMAGE_UPLOAD','12',['file_name'=>'approved.png','contents'=>base64_encode($png)]);
    }
    public function testDocumentedImagePersonalizationUsesExactImageUrlInput(): void
    {
        $items=[['field_id'=>'photo_1','type'=>'image','input'=>['image_url'=>'https://images.printify.com/approved.png']]];
        $body=['product_id'=>'prod_1','variant_id'=>9,'items'=>$items,'require_all_personalization_fields'=>true];
        $request=PrintifyProductRequest::prepare('PERSONALIZATION_CONFIGURE','12',$body);
        self::assertSame($items,$request['body']['items']);self::assertFalse($request['external_execution_authorized']);
        $preview=PrintifyProductRequest::prepare('PREVIEW_CREATE','12',['product_id'=>'prod_1','external_id'=>'preview_1','variant_ids'=>[9],'personalization'=>$items,'format'=>'jpeg']);
        self::assertSame($items,$preview['body']['personalization']);
    }
    /** @dataProvider unsafePhotoInputs */
    public function testRejectsUnsafePhotoInputsAndApprovalFields(array $item,array $extra=[]): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PrintifyProductRequest::prepare('PERSONALIZATION_CONFIGURE','12',['product_id'=>'prod_1','variant_id'=>9,'items'=>[$item],'require_all_personalization_fields'=>true]+$extra);
    }
    public static function unsafePhotoInputs(): array
    {
        $rows=[];
        foreach (['http://images.printify.com/a.png','https://user:secret@images.printify.com/a.png','https://images.printify.com/a.png#fragment','https://images.printify.com/a.png?token=secret','https://localhost/a.png','https://sub.localhost/a.png','https://127.0.0.1/a.png','https://[::1]/a.png','https://2130706433/a.png','https://images.printify.com:8443/a.png','file:///tmp/a.png','data:image/png;base64,eA==','https://images.printify.com\\@127.0.0.1/a.png'] as $url) $rows[]=[['field_id'=>'photo_1','type'=>'image','input'=>['image_url'=>$url]]];
        $rows[]=[['field_id'=>'photo_1','type'=>'image','input'=>['image_url'=>'https://images.printify.com/a.png','text'=>'Unexpected']]];
        $rows[]=[['field_id'=>'photo_1','type'=>'image','input'=>['url'=>'https://images.printify.com/a.png']]];
        $rows[]=[['field_id'=>'photo_1','type'=>'image','input'=>['image_url'=>'https://images.printify.com/a.png']],['approval'=>['is_approved'=>true]]];
        return $rows;
    }
}
