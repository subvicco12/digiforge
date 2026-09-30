<?php

declare(strict_types=1);

namespace DigiForge\Operations;

/** In-process registry for explicitly bootstrapped recovery provider adapters. */
final class RecoveryProviderRegistry
{
    /** @var array<string,RecoveryProviderAdapter> */
    private static array $providers = [];

    public static function register(RecoveryProviderAdapter $adapter): void
    {
        $slug = sanitize_key($adapter->providerSlug());
        if ($slug === '') {
            return;
        }
        self::$providers[$slug] = $adapter;
    }

    public static function get(string $slug): ?RecoveryProviderAdapter
    {
        return self::$providers[sanitize_key($slug)] ?? null;
    }

    /** @return array<string,array{available:bool,reason:string}> */
    public static function capabilities(): array
    {
        $out = [];
        foreach (self::$providers as $slug => $provider) {
            $out[$slug] = $provider->capability();
        }
        return $out;
    }

    public static function reset(): void
    {
        self::$providers = [];
    }
}
