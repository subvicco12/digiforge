<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class V6ProductionLifecycleHistoryTest extends TestCase{
 public function testUnifiedHistoryIsReadOnlyAndNeverAuthorizesRetry():void{$s=file_get_contents(__DIR__.'/../../includes/POD/ProductionLifecycleHistoryReadModel.php');foreach(['CONSUMED_AWAITING_OUTCOME','EXECUTION_UNKNOWN_RECONCILIATION_REQUIRED','EXECUTION_UNKNOWN_REVIEW_REQUIRED','EXECUTION_UNKNOWN_ACKNOWLEDGED','LIFECYCLE_CLOSED',"'retry_permitted'=>false","'external_execution_authorized'=>false","'read_only'=>true"] as $n)self::assertStringContainsString($n,$s);self::assertStringNotContainsString('->insert(',$s);self::assertStringNotContainsString('->update(',$s);}
 public function testPackageBindingUsesDurableClosureRatherThanInventedAuthorizationColumn():void{$s=file_get_contents(__DIR__.'/../../includes/POD/ProductionLifecycleHistoryReadModel.php');self::assertStringContainsString("WHERE id=%d LIMIT 1",$s);self::assertStringNotContainsString("pod_authorization_packages().' WHERE authorization_hash",$s);}
}
