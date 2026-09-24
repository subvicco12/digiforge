<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class EtsyReconciliationLookupExecutorContractTest extends TestCase
{
 public function testExecutorIsGetOnlyAndFailClosed():void{$s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyReconciliationLookupExecutor.php');foreach(["'method'=>'GET'","Settings::safety_locked()","Settings::get('stop_all',true)!==false","'/application/listings/","'mutation_performed'=>false","'external_retry_performed'=>false"] as $n)self::assertStringContainsString($n,$s);foreach(["'method'=>'POST'","'method'=>'PUT'","'method'=>'DELETE'"] as $n)self::assertStringNotContainsString($n,$s);}
 public function testExecutorUsesScopedCredentialAndBoundedTransport():void{$s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyReconciliationLookupExecutor.php');foreach(['EtsyScopedCredentialRetriever','https://openapi.etsy.com/v3','timeout\'=>15','redirection\'=>0','sslverify\'=>true','EtsyReconciliationLookupResponse::normalize'] as $n)self::assertStringContainsString($n,$s);}
}
