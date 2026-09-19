<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class Phase4ProviderContractStructureTest extends TestCase
{
    public function testExecutionAdapterRemainsProviderNeutral():void
    {
        $s=file_get_contents(dirname(__DIR__,2).'/includes/POD/ExecutionAdapter.php');
        self::assertIsString($s);
        self::assertStringContainsString('interface ExecutionAdapter',$s);
        self::assertStringNotContainsString('wp_remote_',$s);
    }

    public function testProviderCatalogDeclaresPrintifyAndGelato():void
    {
        $s=file_get_contents(dirname(__DIR__,2).'/includes/Integrations/ProviderCatalog.php');
        self::assertIsString($s);
        self::assertStringContainsString("'printify'",$s);
        self::assertStringContainsString("'gelato'",$s);
    }

    public function testProviderIntentsRemainBlocked():void
    {
        $s=file_get_contents(dirname(__DIR__,2).'/includes/POD/Repository.php');
        self::assertIsString($s);
        self::assertStringContainsString("'state'=>'BLOCKED'",$s);
    }
}
