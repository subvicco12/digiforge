<?php

declare(strict_types=1);

namespace DigiForge\Tests;

use DigiForge\Orders\Validator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class OrderValidatorTest extends TestCase
{
    public function testOrderBoundsAndEnums(): void
    {
        self::assertSame('etsy', Validator::channel('etsy'));
        self::assertSame('production', Validator::environment('production'));
        self::assertSame('EUR', Validator::currency('eur'));
        self::assertSame(2, Validator::quantity('2'));
        self::assertSame(12.5, Validator::amount('12.5'));
    }

    public function testRecursiveCredentialKeysAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validator::structured(['personalization' => ['access_token' => 'secret']]);
    }

    public function testCanonicalHashIsDeterministic(): void
    {
        self::assertSame(
            Validator::hash(['b' => 2, 'a' => 1]),
            Validator::hash(['a' => 1, 'b' => 2])
        );
    }
}
