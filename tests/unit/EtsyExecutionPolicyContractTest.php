<?php
declare(strict_types=1);

use DigiForge\Listings\EtsyExecutionPolicy;
use PHPUnit\Framework\TestCase;

final class EtsyExecutionPolicyContractTest extends TestCase
{
    public function testPolicyDefinesOnlyControlledStages(): void
    {
        self::assertSame('READ', EtsyExecutionPolicy::OP_READ);
        self::assertSame('DRAFT', EtsyExecutionPolicy::OP_DRAFT);
        self::assertSame('PUBLISH', EtsyExecutionPolicy::OP_PUBLISH);
    }

    public function testSourceContainsNoExternalHttpExecution(): void
    {
        $source = file_get_contents(__DIR__ . '/../../includes/Listings/EtsyExecutionPolicy.php');
        self::assertIsString($source);
        foreach (['wp_remote_get(', 'wp_remote_post(', 'curl_exec(', 'file_get_contents(\'http'] as $needle) {
            self::assertStringNotContainsString($needle, $source);
        }
    }

    public function testDraftAndPublishRequireHumanApprovalByContract(): void
    {
        $source = file_get_contents(__DIR__ . '/../../includes/Listings/EtsyExecutionPolicy.php');
        self::assertIsString($source);
        self::assertStringContainsString('explicit_human_approval_required', $source);
        self::assertStringContainsString("'etsy_draft'", $source);
        self::assertStringContainsString("'etsy_publish'", $source);
        self::assertStringContainsString('Settings::safety_locked()', $source);
    }
}
