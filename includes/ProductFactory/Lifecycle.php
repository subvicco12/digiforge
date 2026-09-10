<?php
declare(strict_types=1);
namespace DigiForge\ProductFactory;

/** Central, whitelist-only lifecycle rules for Product Factory records. */
final class Lifecycle {
    public const OPPORTUNITY = ['NEW', 'QUALIFIED', 'REJECTED', 'ARCHIVED'];
    public const FAMILY = ['DRAFT', 'ACTIVE', 'ARCHIVED'];
    public const PRODUCT = ['DRAFT', 'READY', 'ACTIVE', 'ARCHIVED'];
    public const VERSION = ['DRAFT', 'REVIEW', 'APPROVED', 'RELEASED', 'RETIRED'];

    private const TRANSITIONS = [
        'opportunity' => ['NEW' => ['QUALIFIED', 'REJECTED', 'ARCHIVED'], 'QUALIFIED' => ['ARCHIVED'], 'REJECTED' => ['ARCHIVED'], 'ARCHIVED' => []],
        'product_family' => ['DRAFT' => ['ACTIVE', 'ARCHIVED'], 'ACTIVE' => ['ARCHIVED'], 'ARCHIVED' => []],
        'product' => ['DRAFT' => ['READY', 'ARCHIVED'], 'READY' => ['DRAFT', 'ACTIVE', 'ARCHIVED'], 'ACTIVE' => ['ARCHIVED'], 'ARCHIVED' => []],
        'product_version' => ['DRAFT' => ['REVIEW', 'RETIRED'], 'REVIEW' => ['DRAFT', 'APPROVED', 'RETIRED'], 'APPROVED' => ['DRAFT', 'RELEASED', 'RETIRED'], 'RELEASED' => ['RETIRED'], 'RETIRED' => []],
    ];
    public static function states(string $type): array { return match ($type) { 'opportunity' => self::OPPORTUNITY, 'product_family' => self::FAMILY, 'product' => self::PRODUCT, 'product_version' => self::VERSION, default => [] }; }
    public static function valid(string $type, string $status): bool { return in_array($status, self::states($type), true); }
    public static function can_transition(string $type, string $from, string $to): bool { return in_array($to, self::TRANSITIONS[$type][$from] ?? [], true); }
}
