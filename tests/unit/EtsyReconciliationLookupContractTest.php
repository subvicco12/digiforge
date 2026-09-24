<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class EtsyReconciliationLookupContractTest extends TestCase
{
 public function testLookupIsReadOnlyAndIdentityBound():void{$s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyReconciliationLookupPlan.php');foreach(["'method'=>'GET'","'/application/listings/'","'mutation_permitted'=>false","'external_retry_permitted'=>false","'automatic_retry_permitted'=>false"] as $n)self::assertStringContainsString($n,$s);foreach(['POST','PUT','DELETE','wp_remote_','CredentialVault'] as $n)self::assertStringNotContainsString($n,$s);}
 public function testLookupCannotClaimFailureFromAbsence():void{$s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyReconciliationLookupResponse.php');self::assertStringContainsString('listing_not_found_inconclusive',$s);self::assertStringContainsString('EtsyOperationLifecycle::UNKNOWN',$s);self::assertStringContainsString('EtsyOperationLifecycle::CONFIRMED_SUCCESS',$s);}
}
