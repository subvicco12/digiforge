<?php
declare(strict_types=1);
namespace DigiForge\DigitalFactory;
/** Trusted server composition only. Never constructed from request booleans. */
interface DriveGoogleGate
{
    /** @phpstan-impure */
    public function now():int;
    /** @phpstan-impure */
    public function owns(array $binding):bool;
    /** @phpstan-impure */
    public function allows(array $binding):bool;
}
