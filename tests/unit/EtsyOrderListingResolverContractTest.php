<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class EtsyOrderListingResolverContractTest extends TestCase {
 public function testResolverRequiresConfirmedUniqueApprovedIdentity():void{$s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyOrderListingResolver.php');foreach(["operation_type='CREATE_DRAFT'","state='CONFIRMED_SUCCESS'","i.state='APPROVED_INTENT'","l.state='APPROVED'","BINARY o.external_reference=BINARY %s","BINARY o.shop_reference=BINARY %s","v.state IN ('APPROVED','RELEASED')","BINARY l.environment=BINARY 'production'","BINARY i.environment=BINARY l.environment","EtsyStoredShopScope::matches",'LIMIT 2','count($rows)!==1'] as $n)self::assertStringContainsString($n,$s);}
 public function testResolverCannotMutateOrCallProvider():void{$s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyOrderListingResolver.php');foreach(['wp_remote_','curl_','INSERT ','UPDATE ','DELETE ','CredentialVault'] as $n)self::assertStringNotContainsString($n,$s);}
}
