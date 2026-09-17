<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PrintifyCatalogSyncServiceStructureTest extends TestCase
{
    public function testSyncIsCredentialVaultedAndFailClosed(): void
    {
        $source=file_get_contents(dirname(__DIR__,2).'/includes/POD/PrintifyCatalogSyncService.php');
        self::assertIsString($source);
        self::assertStringContainsString("['provider']??'')!=='printify'",$source);
        self::assertStringContainsString("['status']??'')!=='CONFIGURED'",$source);
        self::assertStringContainsString("empty(\$integration['enabled'])",$source);
        self::assertStringContainsString('CredentialVault::decrypt',$source);
        self::assertStringContainsString('personal_access_token',$source);
        self::assertStringContainsString('access_token',$source);
        self::assertStringContainsString('PrintifyCatalogClient',$source);
    }

    public function testSyncRemainsReadOnly(): void
    {
        $source=file_get_contents(dirname(__DIR__,2).'/includes/POD/PrintifyCatalogSyncService.php');
        self::assertStringContainsString('blueprint($blueprintId)',$source);
        self::assertStringContainsString('providers($blueprintId)',$source);
        self::assertStringNotContainsString('createOrder',$source);
        self::assertStringNotContainsString('publishProduct',$source);
        self::assertStringNotContainsString('wp_remote_post',$source);
        self::assertStringNotContainsString('wp_remote_put',$source);
        self::assertStringNotContainsString('wp_remote_delete',$source);
    }
}
