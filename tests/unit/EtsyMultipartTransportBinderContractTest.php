<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class EtsyMultipartTransportBinderContractTest extends TestCase {
 public function testBinderRequiresControlledImageTargetAndExactAuthorizedMetadata():void{$s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyMultipartTransportBinder.php');foreach(['ETSY_CONTROLLED_TRANSPORT_PREPARED','/images$','image_sha256','image_size','image_mime','hash_equals','multipart_bound'] as $n)self::assertStringContainsString($n,$s);foreach(['wp_remote_','CredentialVault','access_token'] as $n)self::assertStringNotContainsString($n,$s);}
 public function testUploadPlannerAuthorizesOnlyMetadataNotLocalPath():void{$s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyDraftListingOperations.php');foreach(['uploadImage','image_sha256','image_size','image_mime','ATTACH_IMAGE'] as $n)self::assertStringContainsString($n,$s);self::assertStringNotContainsString("'file_path'=>",$s);}
}
