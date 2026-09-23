<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/Listings/EtsyOperationLifecycle.php';

use DigiForge\Listings\EtsyOperationLifecycle;
use PHPUnit\Framework\TestCase;

final class EtsyOperationLifecycleTest extends TestCase
{
    public function testCanonicalStatesAreStable(): void
    {
        self::assertSame([
            'NOT_SENT','SENT','CONFIRMED_SUCCESS','CONFIRMED_FAILURE',
            'UNKNOWN','RECONCILIATION','RECONCILED'
        ], EtsyOperationLifecycle::states());
    }

    public function testUnknownMustReconcileBeforeTerminalOutcome(): void
    {
        self::assertFalse(EtsyOperationLifecycle::canTransition('UNKNOWN', 'CONFIRMED_SUCCESS'));
        self::assertFalse(EtsyOperationLifecycle::canTransition('UNKNOWN', 'CONFIRMED_FAILURE'));
        self::assertTrue(EtsyOperationLifecycle::canTransition('UNKNOWN', 'RECONCILIATION'));
        self::assertTrue(EtsyOperationLifecycle::reconciliationRequired('UNKNOWN'));
    }

    public function testRetryIsFailClosedUntilConfirmedFailure(): void
    {
        foreach (['NOT_SENT','SENT','UNKNOWN','RECONCILIATION','RECONCILED','CONFIRMED_SUCCESS'] as $state) {
            self::assertFalse(EtsyOperationLifecycle::retryPermitted($state), $state);
        }
        self::assertTrue(EtsyOperationLifecycle::retryPermitted('CONFIRMED_FAILURE'));
    }

    public function testTerminalSuccessCannotTransition(): void
    {
        foreach (EtsyOperationLifecycle::states() as $state) {
            self::assertFalse(EtsyOperationLifecycle::canTransition('CONFIRMED_SUCCESS', $state));
        }
    }
}
