# DigiForge

DigiForge is a WordPress-native foundation for a future digital-product and print-on-demand operations platform. It targets **WordPress 7.1** and **PHP 8.3+**, including conventional shared hosting such as Hostinger. WordPress and MySQL are the operational source of truth; this plugin has no Node.js runtime, SaaS backend, or external provider dependency.

## Installation

1. Package this directory as `digiforge.zip`, with `digiforge/` as the archive root.
2. Install it through **Plugins → Add New → Upload Plugin**, then activate it as an administrator.
3. Open **DigiForge** in wp-admin. Every automation switch starts **OFF**.

## Architecture

* `digiforge.php` is a small bootstrap and internal PSR-4-style autoloader for the `DigiForge\` namespace. Composer is not required.
* `includes/Core` owns lifecycle hooks, capabilities, configuration, settings and the admin entry point.
* `includes/Database` contains table names and versioned, additive `dbDelta` migrations.
* `includes/Security` supplies append-oriented audit logging with recursive secret redaction.
* `includes/REST` provides authenticated `/wp-json/digiforge/v1/` management endpoints.
* `includes/Queue` records job intent only. Jobs begin `BLOCKED`; no workers execute them in this release. The scheduler has an Action Scheduler compatibility boundary when that library is present.
* `modules`, `automation`, and `admin` reserve stable module boundaries for future implementation.

## Data and migrations

Activation installs/upgrades the following prefixed tables via `dbDelta`:

* `{$wpdb->prefix}digiforge_settings`
* `{$wpdb->prefix}digiforge_audit_log`
* `{$wpdb->prefix}digiforge_jobs`
* `{$wpdb->prefix}digiforge_idempotency`

Schema versions are tracked in the `digiforge_db_version` option. Migrations are additive and can be safely invoked on subsequent plugin boots. Tables use indexed state/time and lookup columns, plus UTC timestamps.

## Security model

Administrators receive DigiForge-specific capabilities on activation. REST routes use WordPress REST authentication (including normal cookie nonce validation performed by WordPress for cookie-authenticated requests) and strict permission callbacks. State-changing routes require `manage_digiforge_automation`; status requires `manage_digiforge` or `view_digiforge_analytics`.

All state is server-side. Settings are private table records, future credentials have an opaque-only accessor, REST responses never return secrets, and audit contexts redact common credential field names. The audit log is written append-only by plugin code. The **STOP ALL** flag prevents `Settings::is_enabled()` from reporting any individual automation as enabled.

Uninstall retains all business data by default. It deletes DigiForge tables only if the explicit `cleanup_on_uninstall` option was enabled before uninstall.

## Intentionally disabled integrations

No live connection, workflow, publishing, or automation exists for Etsy, Printify, Gelato, AI services, GST portals, orders, research, or any other external service. The controls for research, AI, product development, Printify, Gelato, Etsy drafts/publishing, orders, and GST are all OFF by default. This foundation does not send requests to any external service.

## Development checks

Run `find . -name '*.php' -print0 | xargs -0 -n1 php -l` and `php tests/test-foundation.php` from the plugin root. The tests are lightweight and PHP-only so they can run without a WordPress installation.
