<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ApprovalRecordStructureTest extends TestCase{
 public function testApprovalNeverUnlocksExecution():void{$s=file_get_contents(dirname(__DIR__,2).'/includes/POD/ApprovalRecord.php');self::assertIsString($s);foreach(["'READY_FOR_HUMAN_APPROVAL'","'APPROVE','REJECT'","'HUMAN_APPROVED'","'HUMAN_REJECTED'","'publishing_enabled'=>false","'order_execution_enabled'=>false","evidence_hash"] as $x)self::assertStringContainsString($x,$s);self::assertStringNotContainsString('wp_remote_',$s);}
}
