<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ExecutionAuthorizationStructureTest extends TestCase{
 public function testAuthorizationIsExplicitScopedAndNonExecuting():void{$s=file_get_contents(dirname(__DIR__,2).'/includes/POD/ExecutionAuthorization.php');self::assertIsString($s);foreach(["'HUMAN_APPROVED'","'ETSY_DRAFT_CREATE','PROVIDER_ORDER_SUBMIT'","'EXECUTION_AUTHORIZED'","'executed'=>false","60-900","authorization_hash"] as $x)self::assertStringContainsString($x,$s);self::assertStringNotContainsString('wp_remote_',$s);}
}
