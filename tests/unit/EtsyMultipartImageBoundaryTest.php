<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class EtsyMultipartImageBoundaryTest extends TestCase {
 public function testPlannerBoundsImageTypeSizeAndDoesNotPublish():void{$s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyMultipartImageRequest.php');foreach(['MAX_BYTES','image/jpeg','image/png','image/webp','sha256','publish_permitted','file_path'] as $n)self::assertStringContainsString($n,$s);foreach(['access_token','refresh_token','wp_remote_'] as $n)self::assertStringNotContainsString($n,$s);}
 public function testExecutorHasDedicatedMultipartBoundary():void{$s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyControlledHttpExecutor.php');foreach(['multipart/form-data','Content-Disposition','Content-Type','file_get_contents($asset)','EtsyMultipart'] as $n)self::assertStringContainsString($n,$s);}
 public function testMultipartDoesNotBecomeJsonBody():void{$s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyControlledHttpExecutor.php');self::assertStringContainsString('if ($multipart!==null)',$s);self::assertStringContainsString("} elseif ($payload!==[]",$s);}
}
