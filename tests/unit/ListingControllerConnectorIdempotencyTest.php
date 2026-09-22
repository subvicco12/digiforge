<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ListingControllerConnectorIdempotencyTest extends TestCase
{
    public function testListingMutationsSupportAuthenticatedConnectorBodyKey(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/includes/REST/ListingController.php');
        self::assertIsString($source);
        self::assertStringContainsString("array_key_exists('_idempotency_key',\$params)", $source);
        self::assertStringContainsString("'Idempotency-Key header or _idempotency_key JSON field is required.'", $source);
        self::assertStringContainsString("strlen(\$header)>191", $source);
        self::assertStringContainsString("\$bodyKey = \$params['_idempotency_key'] ?? null;", $source);
    }
}
