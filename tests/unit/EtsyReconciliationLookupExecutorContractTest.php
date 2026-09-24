<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class EtsyReconciliationLookupExecutorContractTest extends TestCase
{
 public function testExecutorDelegatesToAuditedBoundaryOnly():void{$s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyReconciliationLookupExecutor.php');foreach(['EtsyControlledHttpExecutor','->execute($prepared,$authorized)',"'method']??'')!=='GET'","'mutation_performed'=>false","'external_retry_performed'=>false"] as $n)self::assertStringContainsString($n,$s);foreach(['wp_remote_','curl_','CredentialVault','EtsyScopedCredentialRetriever','Bearer '] as $n)self::assertStringNotContainsString($n,$s);}
 public function testLookupRequiresExactPreparedEndpointBinding():void{$s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyReconciliationLookupExecutor.php');self::assertStringContainsString("hash_equals((string)(\$plan['endpoint']??''),(string)(\$request['endpoint']??''))",$s);self::assertStringContainsString('lookup_inconclusive',$s);}
}
