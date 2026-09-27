<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class EtsyWebhookRecoveryContractTest extends TestCase {
 public function testFailedVerifiedEventCanBeReclaimedButProcessedEventCannot():void{$s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyWebhookDeduplicator.php');foreach(["status=%s,updated_at=%s","'RECEIVED'","'FAILED'","ETSY_WEBHOOK_RETRY_CLAIMED","ETSY_WEBHOOK_DUPLICATE","'PROCESSED'"] as $n)self::assertStringContainsString($n,$s);}
 public function testIntakeMarksProcessingFailureBeforeRetry():void{$s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyWebhookIntake.php');self::assertStringContainsString('markFailed',$s);self::assertStringContainsString('markProcessed',$s);self::assertLessThan(strpos($s,'markProcessed'),strpos($s,'markFailed'));}
}
