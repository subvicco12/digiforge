<?php
declare(strict_types=1);
namespace DigiForge\Database;

/** Reads mutable wpdb query state; calls can change last_error even after an explicit reset. */
final class QueryEvidence
{
    /** @phpstan-impure */
    public static function failed(): bool { global $wpdb; return $wpdb->last_error !== ''; }
}
