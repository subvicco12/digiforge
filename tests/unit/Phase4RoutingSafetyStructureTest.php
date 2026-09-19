<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class Phase4RoutingSafetyStructureTest extends TestCase
{
    public function testPodRouterIsLocalAndFailClosed():void
    {
        $s=file_get_contents(dirname(__DIR__,2).'/includes/POD/ProviderRouter.php');
        self::assertIsString($s);
        self::assertStringContainsString("['printify','gelato']",$s);
        self::assertStringContainsString("'adapter_invoked'=>false",$s);
        self::assertStringContainsString("'external_execution_performed'=>false",$s);
        self::assertStringNotContainsString('wp_remote_',$s);
    }
    public function testFulfillmentRouterIsLocalAndFailClosed():void
    {
        $s=file_get_contents(dirname(__DIR__,2).'/includes/Orders/FulfillmentRouter.php');
        self::assertIsString($s);
        self::assertStringContainsString("'adapter_invoked'=>false",$s);
        self::assertStringContainsString("'external_execution_performed'=>false",$s);
        self::assertStringNotContainsString('wp_remote_',$s);
    }
    public function testPrintifyCatalogTransportRemainsGetOnly():void
    {
        $s=file_get_contents(dirname(__DIR__,2).'/includes/POD/PrintifyCatalogClient.php');
        self::assertIsString($s);
        self::assertStringContainsString('wp_remote_get',$s);
        self::assertStringNotContainsString('wp_remote_post',$s);
        self::assertStringNotContainsString('wp_remote_request',$s);
    }
}
