# DigiForge

DigiForge is a WordPress-native foundation for a future digital-product and print-on-demand operations platform. It targets **WordPress 7.1** and **PHP 8.3+**, including conventional shared hosting such as Hostinger. WordPress and MySQL are the operational source of truth; this plugin has no Node.js runtime, SaaS backend, or external provider dependency.

**V1 deployment model: single-site WordPress.** Multisite/network operation is intentionally not supported by this foundation; destructive uninstall operations are guarded against multisite execution until a dedicated multisite design is implemented and audited.

## Installation

1. Package this directory as `digiforge.zip`, with `digiforge/` as the archive root.
2. Install it through **Plugins → Add New → Upload Plugin**, then activate it as an administrator.
3. Open **DigiForge** in wp-admin. Every automation switch starts **OFF**.
4. Before storing any integration credential, define `DIGIFORGE_CREDENTIAL_KEY` in `wp-config.php` with at least 32 bytes of stable, high-entropy secret material. Keep this value outside the plugin repository and do not rotate it without a planned credential re-encryption procedure.

## Architecture

* `digiforge.php` is a small bootstrap and internal PSR-4-style autoloader for the `DigiForge\` namespace. Composer is not required.
* `includes/Core` owns lifecycle hooks, capabilities, configuration, settings and the admin entry point.
* `includes/Database` contains table names and versioned, additive `dbDelta` migrations.
* `includes/Security` supplies append-oriented audit logging with recursive credential redaction.
* `includes/Integrations` provides the local-only integration registry and encrypted credential vault for Etsy, Printify, Gelato, and AI provider classes. It performs no provider network calls.
* `includes/Research` provides the inert Research & Opportunity Intelligence foundation: local source records, normalized observations, evidence/provenance, deterministic scoring, candidate deduplication, human review, and controlled promotion into Product Factory opportunities.
* `includes/REST` provides authenticated `/wp-json/digiforge/v1/` management endpoints for Product Factory, Digital Factory, integrations, and research.
* `includes/DigitalFactory` owns digital-product lifecycle/readiness policy, local QA vocabularies, persistence, and WordPress admin views.
* `includes/Queue` records job intent only. Jobs begin `BLOCKED`; no workers execute them in this release. The scheduler has an Action Scheduler compatibility boundary when that library is present.
* `modules`, `automation`, and `admin` reserve stable module boundaries for future implementation.

## Data and migrations

Activation installs/upgrades the following prefixed tables via `dbDelta`:

* `{$wpdb->prefix}digiforge_settings`
* `{$wpdb->prefix}digiforge_audit_log`
* `{$wpdb->prefix}digiforge_jobs`
* `{$wpdb->prefix}digiforge_idempotency`
* `{$wpdb->prefix}digiforge_health_events`
* `{$wpdb->prefix}digiforge_opportunities`
* `{$wpdb->prefix}digiforge_product_families`
* `{$wpdb->prefix}digiforge_products`
* `{$wpdb->prefix}digiforge_product_versions`
* `{$wpdb->prefix}digiforge_digital_products`
* `{$wpdb->prefix}digiforge_digital_files`
* `{$wpdb->prefix}digiforge_digital_file_versions`
* `{$wpdb->prefix}digiforge_digital_packages`
* `{$wpdb->prefix}digiforge_digital_previews`
* `{$wpdb->prefix}digiforge_digital_templates`
* `{$wpdb->prefix}digiforge_digital_licenses`
* `{$wpdb->prefix}digiforge_digital_download_checks`
* `{$wpdb->prefix}digiforge_integrations`
* `{$wpdb->prefix}digiforge_integration_secrets`
* `{$wpdb->prefix}digiforge_research_sources`
* `{$wpdb->prefix}digiforge_research_observations`
* `{$wpdb->prefix}digiforge_research_evidence`
* `{$wpdb->prefix}digiforge_research_candidates`
* `{$wpdb->prefix}digiforge_research_candidate_evidence`
* `{$wpdb->prefix}digiforge_research_reviews`

Schema versions are tracked in `digiforge_db_version` and `digiforge_db_schema_version`. The current schema is version **7**. Earlier versions introduced nullable job idempotency keys, Product Factory records, Digital Product Factory records, queue/health hardening, and the local integration registry. Version 7 adds the Research & Opportunity Intelligence tables. Existing schema-v6 installations receive only the additive research tables, and an already-current schema does not re-run stable legacy `AUTO_INCREMENT` tables.

## Product Factory

The local-only Product Factory models the chain **Opportunity → Product Family → Product → Product Version**. Creation accepts an `Idempotency-Key` header, validates that each parent exists, and starts every record in its defined initial state. Strict lifecycle transitions are enforced by the repository and every successful creation or state change is written to the audit log. Capability-gated collection, item, and state endpoints are available below `/wp-json/digiforge/v1/`; no endpoint performs external HTTP requests or starts automation.

## Digital Product Factory

The Digital Product Factory extends a Product Version with local digital products, files and immutable file-version labels, packages, previews, editable-template references, hashed license codes, and technical QA/download-check records. Categories are sanitized configurable slugs, so planners, journals, worksheets, graphics, templates, bundles, and future categories use the same model. Cross-record writes reject mismatched Product Factory versions, products, files, and previews.

Its internal readiness path covers file preparation, technical/content/visual/commercial/copyright-policy-profitability QA, listing readiness, human approval, platform-draft intent, and final validation. `PUBLISH_READY` is only an internal state: this release contains no publisher. QA records support PDF, image, archive, template, relationship, checksum, and package-generation checks while deliberately doing no live processing. Authenticated REST collection/detail/create/update/state routes use bounded pagination and the `manage_digiforge_digital` capability; matching wp-admin pages remain part of DigiForge rather than a separate application.

## Integration registry and credential security

DigiForge stores local connection records for `etsy`, `printify`, `gelato`, and `ai` provider classes. The registry contains provider metadata, environment, connection keys, enabled/status state, and non-secret configuration only. Configuration is recursively checked for credential-like keys; tokens, API keys, authorization values, passwords, private/signing keys, client secrets and similar values are rejected from ordinary config and must use the dedicated credential endpoint.

Credentials are write-only. They are encrypted at rest using a domain-separated key derived from `DIGIFORGE_CREDENTIAL_KEY`, with XChaCha20-Poly1305 when sodium is available and AES-256-GCM as the fallback. Authenticated encryption is bound to the integration id and secret name so ciphertext cannot be moved between records or secret slots without failing authentication. Stored fingerprints are keyed HMAC-derived identifiers and are not secret hashes. REST responses and the read-only **DigiForge → Connections** admin view expose only credential metadata, never plaintext or ciphertext. Sandbox/test/production connection records are isolated by provider, environment and connection key.

## Research & Opportunity Intelligence

Batch 4 adds a deliberately inert research foundation. Research sources are local records only and default to `enabled = 0`; creating a source does not activate collection. Observations accept manually supplied normalized content and provenance, are deduplicated by deterministic content hashes, and can be linked to evidence records. Candidate ideas are canonicalized and deduplicated by SHA-256 fingerprints.

Candidate scoring is deterministic and versioned (`v1`). The current score is a bounded 0–100 weighted calculation over demand, competition gap, margin, trend and evidence quality. The stored score inputs and score version preserve provenance for later review; no AI model is involved in this calculation.

Research candidates begin in `PENDING` review status. Only an explicit human `APPROVED` review allows promotion. Promotion creates an ordinary Product Factory Opportunity through the existing Product Factory repository and uses idempotency to prevent duplicate opportunities. Rejected or unreviewed candidates cannot be promoted.

Research REST routes require the `manage_digiforge_research` capability. Every research mutation requires an `Idempotency-Key`; duplicate mutation submissions are rejected and failed validation releases the pending reservation so a corrected retry can proceed. The **DigiForge → Research Review** admin view is read-only in this batch and exposes no automation controls.

## Security model

Administrators receive DigiForge-specific capabilities on activation. REST routes use WordPress REST authentication (including normal cookie nonce validation performed by WordPress for cookie-authenticated requests) and strict permission callbacks. Integration registry routes require `manage_digiforge_connections`; Research routes require `manage_digiforge_research`; state-changing automation routes require `manage_digiforge_automation`; status requires `manage_digiforge` or `view_digiforge_analytics`.

All state is server-side. Settings are private table records, REST responses never return secrets, and audit contexts recursively redact normalized credential field names including access/refresh tokens, client secrets, private/signing keys, API keys and authorization values. The audit log is append-oriented by plugin code. The **STOP ALL** flag prevents `Settings::is_enabled()` from reporting any individual automation as enabled, and `automation_armed` remains internal and fail-closed.

Uninstall retains all business data by default. It deletes DigiForge tables, including integration and research tables, only if the explicit `cleanup_on_uninstall` option was enabled before uninstall. Destructive uninstall is disabled during multisite/network execution.

## Intentionally disabled integrations and execution

Batch 4 remains storage, review and governance infrastructure only. No live research collector, scraper, crawler, scheduled ingestion, AI execution, OAuth exchange, webhook processing, provider health request, publishing, fulfillment, order processing, GST automation or background worker is enabled. Etsy, Printify, Gelato and AI providers remain local registry records only. Research, AI, product development, Printify, Gelato, Etsy drafts/publishing, orders and GST controls remain OFF by default. The plugin does not send requests to any external service.

## Development checks

Run `find . -name '*.php' -print0 | xargs -0 -n1 php -l`, `composer test`, and `composer test:wordpress` from the plugin root in the configured development environment. CI also runs PHPStan, PHPCS/policy checks, WordPress/MariaDB integration tests, credential and external-HTTP scans, and reproducible production-package verification.
