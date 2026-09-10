<?php
declare(strict_types=1);
namespace DigiForge\Queue;
/** Action Scheduler-compatible boundary. No jobs are dispatched or executed until a future explicit enablement. */
final class Scheduler {
    public function register(): void { add_action('digiforge_dispatch_jobs', [$this, 'dispatch']); }
    public function dispatch(): void { /* Intentionally inert: this release never runs automation. */ }
    public function available(): bool { return function_exists('as_enqueue_async_action'); }
}
