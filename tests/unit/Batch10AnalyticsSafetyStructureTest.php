<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class Batch10AnalyticsSafetyStructureTest extends TestCase
{
    public function testAnalyticsModulesCannotPerformExternalExecution():void
    {
        foreach(['KpiSnapshot.php','AlertAssessment.php'] as $file){
            $s=file_get_contents(dirname(__DIR__,2).'/includes/Analytics/'.$file);self::assertIsString($s);
            self::assertStringContainsString("'external_execution_performed'=>false",$s);
            self::assertStringNotContainsString('wp_remote_',$s);
        }
    }
    public function testAlertsCannotNotifyOrChangeControls():void
    {
        $s=file_get_contents(dirname(__DIR__,2).'/includes/Analytics/AlertAssessment.php');self::assertIsString($s);
        self::assertStringContainsString("'notification_sent'=>false",$s);
        self::assertStringContainsString("'control_changed'=>false",$s);
    }
}
