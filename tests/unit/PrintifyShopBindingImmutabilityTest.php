<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/Connector/Printify/CredentialReference.php';
require_once __DIR__.'/../../includes/Connector/Printify/ShopBinding.php';
use DigiForge\Connector\Printify\{ShopBinding,CredentialReference};
use PHPUnit\Framework\TestCase;
final class PrintifyShopBindingImmutabilityTest extends TestCase
{
 public function testReferencedCallerImagesCannotMutateReviewedScopeOrFingerprint():void
 {
  $first='image_a';$second='image_b';$ids=[&$first,&$second];$scope=new ShopBinding(2,'personalized_pod',3,'28930593','test',4,new CredentialReference(3),str_repeat('a',64),2000000000,$ids);
  self::assertSame('{"shopId":2,"shopKey":"personalized_pod","integrationId":3,"providerShopId":"28930593","environment":"test","revision":4,"credential":{"integrationId":3},"bindingHash":"'.str_repeat('a',64).'","expiresAt":2000000000,"ownedUploads":["image_a","image_b"]}',json_encode($scope));
  $fingerprint=$scope->fingerprint();self::assertSame(hash('sha256','[2,"personalized_pod",3,"28930593","test",4,3,"'.str_repeat('a',64).'",2000000000,["image_a","image_b"]]'),$fingerprint);
  $first='changed_a';$second='changed_b';self::assertSame(['image_a','image_b'],$scope->ownedUploads,'The immutable reviewed scope must detach caller-owned reference cells.');self::assertSame($fingerprint,$scope->fingerprint());self::assertSame(['changed_a','changed_b'],$ids);
 }
 public function testMalformedOwnedImagesRemainDenied():void
 {
  $this->expectException(InvalidArgumentException::class);new ShopBinding(2,'personalized_pod',3,'28930593','test',4,new CredentialReference(3),str_repeat('a',64),2000000000,['same','same']);
 }
 public function testDistinctNumericLookingImageIdsRemainDistinct():void
 {
  $ids=['0e'.str_repeat('1',62),'0e'.str_repeat('2',62)];try{$scope=new ShopBinding(2,'personalized_pod',3,'28930593','test',4,new CredentialReference(3),str_repeat('a',64),2000000000,$ids);}catch(InvalidArgumentException){self::fail('Distinct literal image identities must not compare as numeric zero.');}self::assertSame($ids,$scope->ownedUploads);
 }

}
