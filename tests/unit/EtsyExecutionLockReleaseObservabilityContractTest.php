<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class EtsyExecutionLockReleaseObservabilityContractTest extends TestCase
{
    public function testReleaseFailureIsAuditedWithoutChangingVoidCleanupContract(): void
    {
        $s=(string)file_get_contents(__DIR__.'/../../includes/Listings/EtsyOperationRepository.php');
        $start=strpos($s,'public function releaseExecutionLock(int $id): void');
        $end=strpos($s,'public function find(int $id)',$start);
        $method=substr($s,$start,$end-$start);
        self::assertStringContainsString("DigiForge\\Security\\Logger",$s);
        self::assertStringContainsString("Logger::audit('etsy_execution_lock_release_failed'",$method);
        self::assertStringContainsString("'external_outcome_preserved'=>true",$method);
        self::assertStringContainsString("'operator_attention_required'=>true",$method);
        self::assertStringContainsString("error_log('DigiForge Etsy execution lock release failed",$method);
        self::assertStringNotContainsString('return new WP_Error',$method);
        self::assertStringNotContainsString('throw ',$method);
    }

    public function testReleaseChecksDatabaseAndReleaseResult(): void
    {
        $s=(string)file_get_contents(__DIR__.'/../../includes/Listings/EtsyOperationRepository.php');
        $start=strpos($s,'public function releaseExecutionLock(int $id): void');
        $end=strpos($s,'public function find(int $id)',$start);
        $method=substr($s,$start,$end-$start);
        self::assertStringContainsString("\$wpdb->last_error='';",$method);
        self::assertStringContainsString('SELECT RELEASE_LOCK(%s)',$method);
        self::assertStringContainsString("(string)\$wpdb->last_error!==''||(int)\$released!==1",$method);
    }
}
