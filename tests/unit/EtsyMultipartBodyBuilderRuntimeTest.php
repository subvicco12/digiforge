<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class EtsyMultipartBodyBuilderRuntimeTest extends TestCase {
 public function testDigitalFileBodyContainsExactBinaryAndRequiredParts():void{
  require_once dirname(__DIR__,2).'/includes/Listings/EtsyMultipartBodyBuilder.php';
  $bytes="PK\x03\x04\x00binary\r\nbytes";
  $m=['field'=>'file','filename'=>'customer.zip','mime_type'=>'application/zip','rank'=>1];
  $r=\DigiForge\Listings\EtsyMultipartBodyBuilder::build($m,$bytes,'DigiForgeEtsy0123456789abcdef',true);
  self::assertIsArray($r); self::assertSame(strlen($r['body']),$r['content_length']);
  self::assertStringContainsString('name="name"'."\r\n\r\ncustomer.zip",$r['body']);
  self::assertStringContainsString('name="rank"'."\r\n\r\n1",$r['body']);
  self::assertStringContainsString('name="file"; filename="customer.zip"',$r['body']);
  self::assertSame(1,substr_count($r['body'],$bytes));
  self::assertStringEndsWith("--DigiForgeEtsy0123456789abcdef--\r\n",$r['body']);
 }
 public function testExecutorUsesBuilderAndResetsContentHeaders():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyControlledHttpExecutor.php');
  foreach(['EtsyMultipartBodyBuilder::build',"unset(\$headers['content-type'],\$headers['Content-Type'],\$headers['content-length'],\$headers['Content-Length'])","'Content-Length'"] as $n)self::assertStringContainsString($n,$s);
 }
}
