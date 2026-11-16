<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/AI/ImageGenerationWireContract.php';
require_once __DIR__.'/../../includes/POD/RasterArtworkCodec.php';
use DigiForge\AI\ImageGenerationWireContract;
use DigiForge\POD\RasterArtworkCodec;
final class ImageGenerationWireContractTest extends PHPUnit\Framework\TestCase {
 public function testGenerationRequestIsBoundedFixedEndpointAndStable():void {
  self::assertTrue(class_exists(ImageGenerationWireContract::class));$a=ImageGenerationWireContract::request('gpt-image-1','Original fixed geometry; no buyer text','1024x1024','low',null);$b=ImageGenerationWireContract::request('gpt-image-1','Original fixed geometry; no buyer text','1024x1024','low',null);self::assertSame($a,$b);self::assertSame('https://api.openai.com/v1/images/generations',$a['url']);$body=json_decode($a['body'],true);self::assertSame(1,$body['n']);self::assertSame('png',$body['output_format']);self::assertSame('auto',$body['moderation']);self::assertSame(hash('sha256',$a['body']),$a['body_sha256']);
 }
 public function testEditRequestRetainsExactPngAndNoUrlInput():void {
  self::assertTrue(class_exists(ImageGenerationWireContract::class));$png=RasterArtworkCodec::encode(2,2,str_repeat("\x40\x80\xa0\xff",4));$a=ImageGenerationWireContract::request('gpt-image-1','A source-linked monochrome direction','1024x1024','low',$png);self::assertSame('https://api.openai.com/v1/images/edits',$a['url']);self::assertStringContainsString($png,$a['body']);self::assertStringContainsString('name="image"; filename="source.png"',$a['body']);self::assertSame(hash('sha256',$png),$a['source_sha256']);
 }
 public function testUnsupportedParametersAndMissingOrMalformedMeteringAreRejected():void {
  self::assertTrue(class_exists(ImageGenerationWireContract::class));foreach([['gpt-unknown','x','1024x1024','low',null],['gpt-image-1','x','auto','low',null],['gpt-image-1','x','1536x1024','low',null],['gpt-image-1','x','1024x1536','low',null],['gpt-image-1',str_repeat('a',32001),'1024x1024','low',null],['gpt-image-1','x','1024x1024','low','https://example.invalid/a.png']]as $args){try{ImageGenerationWireContract::request(...$args);self::fail('Unsupported request accepted');}catch(InvalidArgumentException){self::assertTrue(true);}}
  foreach(['{}','{"created":1,"data":[{"url":"https://example.invalid/x.png"}]}','{"created":1,"data":[],"usage":{"input_tokens":1,"output_tokens":1,"total_tokens":9}}']as $body){try{ImageGenerationWireContract::response($body,'req_synthetic','1024x1024',2097152);self::fail('Malformed response accepted');}catch(InvalidArgumentException){self::assertTrue(true);}}
 }
 public function testValidResponseKeepsObservedRequestIdentityAndExactUsage():void {
  self::assertTrue(class_exists(ImageGenerationWireContract::class));$png=RasterArtworkCodec::encode(1024,1024,str_repeat("\xff\xff\xff\xff",1024*1024));$body=json_encode(['created'=>time(),'data'=>[['b64_json'=>base64_encode($png)]],'usage'=>['input_tokens'=>10,'output_tokens'=>5,'total_tokens'=>15,'input_tokens_details'=>['text_tokens'=>4,'image_tokens'=>6]]]);$r=ImageGenerationWireContract::response($body,'req_synthetic','1024x1024',2097152);self::assertSame('req_synthetic',$r['request_id']);self::assertSame(hash('sha256',$png),$r['image_sha256']);self::assertSame(hash('sha256',$body),$r['provider_response_sha256']);self::assertSame(15,$r['usage']['total_tokens']);self::assertFalse($r['editable_vector']);
 }
}
