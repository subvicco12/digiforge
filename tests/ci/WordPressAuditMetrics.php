<?php
declare(strict_types=1);

use PHPUnit\Runner\AfterTestHook;
use PHPUnit\Runner\BeforeTestHook;

final class WordPressAuditMetrics implements BeforeTestHook, AfterTestHook
{
    private int $queries = 0;
    private array $classes = [];

    public function __construct()
    {
        register_shutdown_function(function (): void {
            $target = getenv('DF_WP_AUDIT_METRICS');
            if (is_string($target) && $target !== '') {
                file_put_contents($target, json_encode([
                    'peak_memory_bytes' => memory_get_peak_usage(true),
                    'classes' => $this->classes,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n");
            }
        });
    }

    public function executeBeforeTest(string $test): void
    {
        $this->queries = (int) ($GLOBALS['wpdb']->num_queries ?? 0);
    }

    public function executeAfterTest(string $test, float $time): void
    {
        $class = explode('::', $test, 2)[0];
        $row = $this->classes[$class] ?? ['tests' => 0, 'queries' => 0, 'seconds' => 0.0];
        ++$row['tests'];
        $row['queries'] += max(0, (int) ($GLOBALS['wpdb']->num_queries ?? 0) - $this->queries);
        $row['seconds'] += $time;
        $this->classes[$class] = $row;
    }
}
