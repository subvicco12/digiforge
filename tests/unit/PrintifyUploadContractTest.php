<?php
declare(strict_types=1);
namespace DigiForge\Tests;
use PHPUnit\Framework\TestCase;
use DigiForge\POD\PrintifyUploadContract;
require_once __DIR__.'/../../includes/POD/PrintifyUploadContract.php';
final class PrintifyUploadContractTest extends TestCase
{
 private function owned():array{return [['image_id'=>'ours','receipt_hash'=>str_repeat('a',64),'plan_hash'=>str_repeat('b',64)]];}
 public function testPaginationIsBoundedAndAccountWide():void{
  $r=PrintifyUploadContract::prepare('UPLOADS_READ','12',['page'=>2,'limit'=>3]);self::assertSame('/v1/uploads.json?page=2&limit=3',$r['endpoint']);self::assertFalse($r['external_execution_authorized']);
 }
 public function testPaginationRejectsProviderMaximumAndStringCoercion():void{
  foreach([['limit'=>100],['page'=>'1'],['limit'=>0],['page'=>2147483648],['image_id'=>'arbitrary']] as $input){try{PrintifyUploadContract::prepare('UPLOADS_READ','12',$input);self::fail('Invalid input admitted.');}catch(\InvalidArgumentException){self::assertTrue(true);}}
 }
 public function testAccountRowsAreFilteredWithoutExposingOtherUploadsOrLinks():void{
  $r=PrintifyUploadContract::prepare('UPLOADS_READ','12');$r['owned_images']=$this->owned();
  $row=['id'=>'ours','file_name'=>'image.png','height'=>20,'width'=>20,'size'=>50,'mime_type'=>'image/png','upload_time'=>'2026-01-01 00:00:00','preview_url'=>'https://example.invalid/secret'];
  $out=PrintifyUploadContract::project($r,['data'=>[['id'=>'other','secret'=>'do not expose'],$row],'current_page'=>1,'last_page'=>2,'per_page'=>10,'next_page_url'=>'https://evil.invalid']);
  self::assertCount(1,$out['data']);self::assertSame('ours',$out['data'][0]['id']);self::assertArrayNotHasKey('preview_url',$out['data'][0]);self::assertArrayNotHasKey('next_page_url',$out);self::assertFalse($out['other_upload_data_disclosed']);
 }
 public function testArchiveHasNoBodyAndAcceptsOnlyOwnedExactEmptySuccess():void{
  $r=PrintifyUploadContract::prepare('IMAGE_ARCHIVE','12',['image_id'=>'ours']);self::assertSame([],$r['body']);self::assertSame('/v1/uploads/ours/archive.json',$r['endpoint']);$r['owned_images']=$this->owned();self::assertTrue(PrintifyUploadContract::project($r,[])['archived']);
  $this->expectException(\InvalidArgumentException::class);PrintifyUploadContract::project($r,['id'=>'ours']);
 }
 public function testArbitraryArchiveIdCannotUseAnotherImagesSource():void{
  $r=PrintifyUploadContract::prepare('IMAGE_ARCHIVE','12',['image_id'=>'other']);$r['owned_images']=$this->owned();$this->expectException(\InvalidArgumentException::class);PrintifyUploadContract::project($r,[]);
 }
 public function testProjectionRejectsUnresolvedOwnership():void{
  $r=PrintifyUploadContract::prepare('UPLOADS_READ','12');$this->expectException(\InvalidArgumentException::class);PrintifyUploadContract::project($r,[]);
 }
}
