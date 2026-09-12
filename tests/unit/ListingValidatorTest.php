<?php

declare(strict_types=1);

namespace DigiForge\Tests;

use DigiForge\Listings\Validator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ListingValidatorTest extends TestCase
{
    public function testEnumsAndBounds(): void
    {
        self::assertSame('etsy', Validator::channel('etsy'));
        self::assertSame('sandbox', Validator::environment('sandbox'));
        self::assertSame('USD', Validator::currency('usd'));
        self::assertSame(10.5, Validator::price('10.5'));
        $this->expectException(InvalidArgumentException::class);
        Validator::environment('live');
    }

    public function testRecursiveCredentialKeysAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validator::structured(['nested' => ['api_key' => 'secret-value']]);
    }

    public function testCanonicalJsonIsDeterministic(): void
    {
        self::assertSame(
            Validator::canonicalJson(['b' => 2, 'a' => 1]),
            Validator::canonicalJson(['a' => 1, 'b' => 2])
        );
    }
}
