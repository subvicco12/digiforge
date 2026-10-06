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
use DigiForge\Database\V6OperationalSchema;
use DigiForge\Database\ScopedPolicySchema;
use DigiForge\Database\CatalogAcceptanceSchema;
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
        // v16 is additive and must run after the historical migration chain so
        // legacy installers cannot lower the current schema marker back to v15.
        if (! V6OperationalSchema::migrateIfNeeded()) {
            return;
        }
        if (! ScopedPolicySchema::migrateIfNeeded()) {
            return;
        }
        if (! CatalogAcceptanceSchema::migrateIfNeeded()) {
            return;
        }
        Settings::ensure_defaults();
        flush_rewrite_rules();
    }
    public static function deactivate(): void { flush_rewrite_rules(); }
}
