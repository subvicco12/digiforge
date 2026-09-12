<?php

declare(strict_types=1);

namespace DigiForge\Tests;

use DigiForge\Queue\JobState;
use PHPUnit\Framework\TestCase;

final class JobStateTest extends TestCase
{
    public function testOnlyLegalTransitionsAreAccepted(): void
    {
        self::assertTrue(JobState::canTransition('BLOCKED', 'QUEUED'));
        self::assertTrue(JobState::canTransition('QUEUED', 'RUNNING'));
        self::assertTrue(JobState::canTransition('RUNNING', 'RETRY'));
        self::assertTrue(JobState::canTransition('RETRY', 'DEAD_LETTER'));
        self::assertFalse(JobState::canTransition('BLOCKED', 'RUNNING'));
        self::assertFalse(JobState::canTransition('SUCCESS', 'RUNNING'));
        self::assertFalse(JobState::canTransition('UNKNOWN', 'QUEUED'));
    }

    public function testDeadLetterIsTerminal(): void
    {
        self::assertTrue(JobState::valid('DEAD_LETTER'));
        self::assertTrue(JobState::terminal('DEAD_LETTER'));
    }
}
