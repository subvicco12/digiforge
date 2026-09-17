<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PodAdminScopePolicyStructureTest extends TestCase
{
    public function testAdminPolicyIsCapabilityAndScopeGated(): void
    {
        $source=file_get_contents(dirname(__DIR__,2).'/includes/POD/AdminScopePolicy.php');
        self::assertIsString($source);
        self::assertStringContainsString("current_user_can('manage_digiforge_pod')",$source);
        self::assertStringContainsString('BusinessScope::resolveConfigured($input)',$source);
        self::assertStringContainsString('ORIGINAL_DESIGN_POD',$source);
        self::assertStringContainsString('DIGICRAFTIFY_GOODS',$source);
        self::assertStringNotContainsString('wp_remote_',$source);
        self::assertStringNotContainsString('publish',$source);
        self::assertStringNotContainsString('createOrder',$source);
    }
}
