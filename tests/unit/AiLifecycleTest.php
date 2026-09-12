<?php

declare(strict_types=1);

namespace DigiForge\Tests;

use DigiForge\AI\Lifecycle;
use PHPUnit\Framework\TestCase;

final class AiLifecycleTest extends TestCase
{
    public function testSafeGraphAndExecutionStatesRemainUnreachable(): void
    {
        self::assertTrue(Lifecycle::canTransition(Lifecycle::DRAFT, Lifecycle::VALIDATED));
        self::assertTrue(Lifecycle::canTransition(Lifecycle::VALIDATED, Lifecycle::REVIEW_REQUIRED));
        self::assertTrue(Lifecycle::canTransition(Lifecycle::REVIEW_REQUIRED, Lifecycle::APPROVED_FOR_EXECUTION));
        self::assertFalse(Lifecycle::canTransition(Lifecycle::DRAFT, Lifecycle::APPROVED_FOR_EXECUTION));
        self::assertFalse(Lifecycle::isAllowed('EXECUTING'));
        self::assertTrue(Lifecycle::executionState('EXECUTED'));
        self::assertTrue(Lifecycle::executionState('COMPLETED'));
    }
}
