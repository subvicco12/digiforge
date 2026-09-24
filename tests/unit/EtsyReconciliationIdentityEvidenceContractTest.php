<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class EtsyReconciliationIdentityEvidenceContractTest extends TestCase
{
 public function testLedgerHasAdditiveBoundedReconciliationReference():void{$s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Database/EtsyOperationSchema.php');self::assertStringContainsString('VERSION = 2',$s);self::assertStringContainsString('reconciliation_reference varchar(191)',$s);}
 public function testRepositoryPersistsIdentityAtomicallyWithoutNetwork():void{$s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyOperationRepository.php');foreach(['recordReconciliationReference','reconciliation_reference_conflict',"'reconciliation_reference'=>'"] as $n)self::assertStringContainsString($n,$s);foreach(['wp_remote_','curl_exec(','openapi.etsy'] as $n)self::assertStringNotContainsString($n,$s);}
 public function testPlanNeverInventsLookupIdentity():void{$s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyReconciliationPlan.php');self::assertStringContainsString("'lookup_reference' => \$lookupReference",$s);self::assertStringContainsString("'lookup_identity_available' => \$lookupReference !== ''",$s);}
}
