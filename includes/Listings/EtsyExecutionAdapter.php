<?php
declare(strict_types=1);

namespace DigiForge\Listings;

use DigiForge\POD\ExecutionAdapter;

/**
 * Etsy-specific contract for a future controlled adapter.
 *
 * This interface deliberately contains no HTTP implementation. A future
 * implementation must receive only a consumed ControlledExecutionGate permit.
 */
interface EtsyExecutionAdapter extends ExecutionAdapter
{
    public function execute(array $permit, array $payload): array|\WP_Error;
}
