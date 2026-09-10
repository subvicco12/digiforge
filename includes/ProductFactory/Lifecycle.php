<?php
declare(strict_types=1);
namespace DigiForge\ProductFactory;

/** Pure lifecycle policy shared by repositories, REST endpoints, and tests. */
final class Lifecycle {
    public const TRANSITIONS = [
        'opportunity' => ['NEW' => ['QUALIFIED', 'REJECTED', 'ARCHIVED'], 'QUALIFIED' => [], 'REJECTED' => [], 'ARCHIVED' => []],
        'product_family' => ['DRAFT' => ['ACTIVE', 'ARCHIVED'], 'ACTIVE' => [], 'ARCHIVED' => []],
        'product' => ['DRAFT' => ['READY'], 'READY' => ['DRAFT', 'ACTIVE'], 'ACTIVE' => ['ARCHIVED'], 'ARCHIVED' => []],
        'product_version' => ['DRAFT' => ['REVIEW'], 'REVIEW' => ['DRAFT', 'APPROVED'], 'APPROVED' => ['DRAFT', 'REVIEW', 'RELEASED'], 'RELEASED' => ['RETIRED'], 'RETIRED' => []],
    ];

    public static function initial(string $type): ?string {
        return match ($type) { 'opportunity' => 'NEW', 'product_family', 'product', 'product_version' => 'DRAFT', default => null };
    }
    public static function valid_state(string $type, string $state): bool { return isset(self::TRANSITIONS[$type][$state]); }
    public static function can_transition(string $type, string $from, string $to): bool {
        return in_array($to, self::TRANSITIONS[$type][$from] ?? [], true);
    }
}
