<?php
declare(strict_types=1);
namespace DigiForge\POD;

/**
 * Contract for future mutating adapters. Implementations receive only a
 * ControlledExecutionGate permit, never raw approval as execution authority.
 */
interface ExecutionAdapter
{
 /** @return array<string,mixed>|\WP_Error */
 public function execute(array $permit,array $payload):array|\WP_Error;
}
