<?php
declare(strict_types=1);
namespace DigiForge\DigitalFactory;
/** Durable job adapter. Payloads contain references only, never credentials/grant bodies. */
interface DriveQueueJobs
{
    public function create(string $transfer,string $stage,?int $dependency):?int;
    /** @phpstan-impure */
    public function read(int $id):?array;
    public function wake(int $id):bool;
    public function block(int $id):bool;
    public function claim(int $id,string $owner):bool;
    public function finish(int $id,string $owner,string $state):bool;
}
