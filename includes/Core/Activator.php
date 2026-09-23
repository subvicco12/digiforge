<?php
declare(strict_types=1);
namespace DigiForge\Core;
use DigiForge\Database\BusinessScopeInstaller;
use DigiForge\Database\FinanceSchema;
use DigiForge\Database\EtsyOperationSchema;
use DigiForge\Database\OrderSchema;
use DigiForge\Database\ListingSchema;
use DigiForge\Database\Migrator;
use DigiForge\Database\PodSchema;
use DigiForge\Database\ProductionSchema;
final class Activator {
    public static function activate(): void {
        Capabilities::add();
        if (! ListingSchema::migrateIfNeeded()) {
            return;
        }
        if (! EtsyOperationSchema::migrateIfNeeded()) {
            return;
        }
        if (! OrderSchema::migrateIfNeeded()) {
            return;
        }
        if (! FinanceSchema::migrateIfNeeded()) {
            return;
        }
        if (! PodSchema::migrateIfNeeded()) {
            return;
        }
        if (! ProductionSchema::migrateIfNeeded()) {
            return;
        }
        if (! (new Migrator())->migrate()) {
            return;
        }
        if (! BusinessScopeInstaller::migrateIfNeeded()) {
            return;
        }
        Settings::ensure_defaults();
        flush_rewrite_rules();
    }
    public static function deactivate(): void { flush_rewrite_rules(); }
}
