<?php
declare(strict_types=1);
namespace DigiForge\Core;
use DigiForge\Database\Migrator;
final class Activator {
    public static function activate(): void {
        Capabilities::add();
        (new Migrator())->migrate();
        Settings::ensure_defaults();
        flush_rewrite_rules();
    }
    public static function deactivate(): void { flush_rewrite_rules(); }
}
