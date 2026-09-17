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
        self::assertStringContainsString('canonical ACTIVE ownership',$source);
        self::assertStringContainsString('PERSONALIZED_POD-only',$source);
        self::assertStringNotContainsString("sanitize_key((string)(\$scope['business_id']",$source);
        self::assertStringNotContainsString('wp_remote_',$source);
        self::assertStringNotContainsString('createOrder',$source);
    }
}
