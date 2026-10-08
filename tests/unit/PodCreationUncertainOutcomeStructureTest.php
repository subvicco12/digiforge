<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PodCreationUncertainOutcomeStructureTest extends TestCase
{
    public function testCreationConfirmationAndCommitUncertaintyDisableAutomaticRetryAndExternalExecution(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/includes/POD/BusinessScopeRepository.php');
        self::assertIsString($source);
        $start = strpos($source, 'public function createMapping(');
        self::assertNotFalse($start);
        $creation = substr($source, $start);
        foreach (['digiforge_scope_confirmation_unavailable', 'digiforge_scope_commit_unknown'] as $code) {
            $position = strpos($creation, "new WP_Error('" . $code . "'");
            self::assertNotFalse($position, $code);
            $expression = substr($creation, $position, strpos($creation, ']);', $position) - $position);
            self::assertStringContainsString("'retry_permitted'=>false", $expression, $code);
            self::assertStringContainsString("'external_execution_authorized'=>false", $expression, $code);
        }
    }
}
