<?php
declare(strict_types=1);
namespace DigiForge\Core;

use DigiForge\Database\Migrator;
use DigiForge\REST\Controller;
use DigiForge\Queue\Scheduler;
use DigiForge\Security\Logger;

/** Coordinates the WordPress-facing plugin services; integrations are intentionally not booted. */
final class Plugin {
    private static ?self $instance = null;
    private bool $booted = false;
    public static function instance(): self { return self::$instance ??= new self(); }
    private function __construct() {}
    public function boot(): void {
        if ($this->booted) { return; }
        $this->booted = true;
        (new Migrator())->maybe_migrate();
        (new Controller())->register();
        (new Scheduler())->register();
        if (is_admin()) { (new Admin())->register(); }
        add_action('digiforge_log', [Logger::class, 'write'], 10, 4);
    }
}
