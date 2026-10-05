<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class IsolatedStagingSmokeContractTest extends TestCase
{
    public function testSmokeRunnerIsFailClosedAndContainsNoProviderDispatch(): void
    {
        $source=(string)file_get_contents(dirname(__DIR__,2).'/includes/Operations/IsolatedStagingSmoke.php');
        self::assertStringContainsString("digiforgestaging.converentis.com",$source);
        self::assertStringContainsString("Settings::get('stop_all', true) === true",$source);
        self::assertStringContainsString("Settings::get('activation_authorized', true) === false",$source);
        self::assertStringContainsString("Settings::get('automation_armed', true) === false",$source);
        self::assertStringContainsString("Settings::safety_locked()",$source);
        self::assertStringContainsString("CONFIRMED_SUCCESS",$source);
        self::assertStringContainsString("WHERE listing_id=%d AND approved_by>0 AND approved_at IS NOT NULL",$source);
        self::assertStringContainsString("SELECT id,approved_by,approved_at",$source);
        self::assertStringNotContainsString("draft_package_id",$source);
        self::assertStringNotContainsString("state='APPROVED'",$source);
        self::assertStringContainsString("new OrderRepository()",$source);
        self::assertStringContainsString("new FinanceRepository()",$source);
        self::assertStringContainsString("if((string)(\$order['state']??'')===\$state)continue;",$source);
        self::assertStringContainsString("if((string)(\$plan['state']??'')===\$state)continue;",$source);
        self::assertStringContainsString("'external_actions_performed'=>false",$source);
        self::assertStringContainsString("'commerce_execution_authorized'=>false",$source);
        self::assertStringNotContainsString('PrintifyClient',$source);
        self::assertStringNotContainsString('Gelato',$source);
        self::assertStringNotContainsString('wp_remote_',$source);
    }

    public function testEndpointRequiresExistingAutomationManagementPermission(): void
    {
        $source=(string)file_get_contents(dirname(__DIR__,2).'/includes/REST/Controller.php');
        self::assertStringContainsString("'/internal-smoke/p5'",$source);
        self::assertStringContainsString("'permission_callback' => [\$this, 'can_manage']",$source);
        self::assertStringContainsString('IsolatedStagingSmoke::run',$source);
    }
}
