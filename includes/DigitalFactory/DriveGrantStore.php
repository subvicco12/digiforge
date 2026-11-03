<?php
declare(strict_types=1);
namespace DigiForge\DigitalFactory;
/** Atomic, uncached storage. null means unavailable; [] means absent. */
interface DriveGrantStore
{
    public function read(string $key): ?array;
    public function compareAndSwap(string $key,array $expected,array $next): bool;
}
