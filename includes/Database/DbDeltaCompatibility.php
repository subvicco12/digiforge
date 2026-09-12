<?php

declare(strict_types=1);

namespace DigiForge\Database;

/**
 * Normalizes only DigiForge AI CREATE TABLE statements into the line-oriented
 * shape expected by WordPress dbDelta. This avoids dbDelta mis-associating a
 * later column DEFAULT with an AUTO_INCREMENT id when a statement is one line.
 */
final class DbDeltaCompatibility
{
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;
        add_filter('dbdelta_queries', [self::class, 'normalizeQueries']);
    }

    /** @param array<string,string> $queries @return array<string,string> */
    public static function normalizeQueries(array $queries): array
    {
        foreach ($queries as $key => $query) {
            if (! str_contains($query, 'digiforge_ai_') || ! str_starts_with(ltrim($query), 'CREATE TABLE')) {
                continue;
            }
            $queries[$key] = self::lineOrientCreateTable($query);
        }
        return $queries;
    }

    public static function lineOrientCreateTable(string $query): string
    {
        $open = strpos($query, '(');
        $close = strrpos($query, ')');
        if ($open === false || $close === false || $close <= $open) {
            return $query;
        }

        $prefix = substr($query, 0, $open + 1);
        $body = substr($query, $open + 1, $close - $open - 1);
        $suffix = substr($query, $close);
        $parts = [];
        $buffer = '';
        $depth = 0;
        $quote = null;
        $length = strlen($body);

        for ($i = 0; $i < $length; $i++) {
            $char = $body[$i];
            if ($quote !== null) {
                $buffer .= $char;
                if ($char === $quote && ($i === 0 || $body[$i - 1] !== '\\')) {
                    $quote = null;
                }
                continue;
            }
            if ($char === "'" || $char === '"') {
                $quote = $char;
                $buffer .= $char;
                continue;
            }
            if ($char === '(') {
                $depth++;
                $buffer .= $char;
                continue;
            }
            if ($char === ')') {
                $depth--;
                $buffer .= $char;
                continue;
            }
            if ($char === ',' && $depth === 0) {
                $parts[] = trim($buffer);
                $buffer = '';
                continue;
            }
            $buffer .= $char;
        }
        if (trim($buffer) !== '') {
            $parts[] = trim($buffer);
        }
        if ($parts === []) {
            return $query;
        }
        return $prefix . "\n  " . implode(",\n  ", $parts) . "\n" . $suffix;
    }
}
