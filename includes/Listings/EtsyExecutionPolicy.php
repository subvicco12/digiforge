<?php
declare(strict_types=1);

namespace DigiForge\Listings;

use DigiForge\Core\Settings;

/**
 * Fail-closed policy boundary for future Etsy external operations.
 *
 * This class performs no HTTP request and cannot activate a capability. It only
 * evaluates whether a caller may enter a controlled external-operation stage.
 */
final class EtsyExecutionPolicy
{
    public const OP_READ = 'READ';
    public const OP_DRAFT = 'DRAFT';
    public const OP_PUBLISH = 'PUBLISH';

    /** @return array{allowed:bool,operation:string,reason:string,requires_human_approval:bool} */
    public static function evaluate(string $operation, bool $humanApproved = false): array
    {
        $operation = strtoupper(trim($operation));
        if (! in_array($operation, [self::OP_READ, self::OP_DRAFT, self::OP_PUBLISH], true)) {
            return self::deny($operation, 'unknown_operation', true);
        }

        if (Settings::safety_locked()) {
            return self::deny($operation, 'external_safety_lock_active', $operation !== self::OP_READ);
        }

        $switch = match ($operation) {
            self::OP_READ => 'research',
            self::OP_DRAFT => 'etsy_draft',
            self::OP_PUBLISH => 'etsy_publish',
        };
        if (! Settings::is_enabled($switch)) {
            return self::deny($operation, 'capability_not_enabled', $operation !== self::OP_READ);
        }

        if (in_array($operation, [self::OP_DRAFT, self::OP_PUBLISH], true) && ! $humanApproved) {
            return self::deny($operation, 'explicit_human_approval_required', true);
        }

        return [
            'allowed' => true,
            'operation' => $operation,
            'reason' => 'authorized',
            'requires_human_approval' => $operation !== self::OP_READ,
        ];
    }

    /** @return array{allowed:bool,operation:string,reason:string,requires_human_approval:bool} */
    private static function deny(string $operation, string $reason, bool $approval): array
    {
        return [
            'allowed' => false,
            'operation' => $operation,
            'reason' => $reason,
            'requires_human_approval' => $approval,
        ];
    }
}
