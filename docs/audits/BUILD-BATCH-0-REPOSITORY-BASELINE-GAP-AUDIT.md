# BUILD BATCH 0 — DigiForge Repository Baseline & Gap Audit

**Audit date:** 2026-09-12  
**Repository:** `subvicco12/digiforge`  
**Audited baseline:** `main@3d5d3b6afaf7e1a08ac6403e8d12ac0a25f4d4ea`  
**Authority:** DigiForge Master Development Architecture v1.0 and Master Architecture Implementation Blueprint v2.0  
**Change class:** documentation and planning only  
**Production posture:** no deployment, no external calls, no publishing, no automation activation

## 1. Executive conclusion

The repository is a sound, deliberately inert WordPress-native foundation. It already provides secure bootstrap boundaries, capability-gated REST surfaces, additive custom-table migrations, audit redaction, idempotency primitives, Product Factory records, Digital Product Factory records, and a non-executing queue boundary.

It is not yet the complete DigiForge platform described by the master architecture. The implemented code is concentrated in the foundation, Product Factory, and Digital Product Factory. Research, AI, asset generation, listings, Etsy, POD providers, orders, finance, GST, policy, analytics, reconciliation, retry execution, and operational dashboards remain placeholders or are absent.

Batch 0 therefore establishes the current implementation truth, identifies safety and engineering gaps, and defines the dependency order for subsequent build batches. It does not activate any business workflow.

## 2. Non-negotiable safety baseline

The following constraints apply to every later batch:

1. External side effects remain disabled until a separately reviewed activation phase.
2. Etsy publishing, Etsy drafts, Printify, Gelato, AI processing, order automation, and GST automation remain OFF.
3. Personalized-order auto-approval and provider auto-approval are deferred.
4. Human approval remains mandatory before any future marketplace publication.
5. Secrets must never be committed, logged, returned through REST, or displayed after write.
6. Every externally visible mutation must use capability checks, validation, audit events, and idempotency.
7. Sandbox/test and production credentials, records, webhooks, and controls must remain isolated.
8. No Batch 0 change may deploy to a WordPress site or merge itself.

## 3. Repository inventory

### Implemented

- WordPress plugin bootstrap and internal namespace autoloader.
- Activation/deactivation/uninstall lifecycle.
- DigiForge capability model.
- Private settings storage and automation switches.
- Additive `dbDelta` schema through database version 4.
- Append-oriented audit log with recursive credential-key redaction.
- Job-intent table, idempotency table, state vocabulary, and inert scheduler.
- Product Factory:
  - opportunities;
  - product families;
  - products;
  - product versions;
  - parent validation;
  - lifecycle transitions;
  - optimistic state transition;
  - bounded REST pagination;
  - idempotent creation.
- Digital Product Factory:
  - digital products;
  - files and file versions;
  - packages;
  - previews;
  - templates;
  - licenses;
  - QA/download checks;
  - readiness lifecycle;
  - relationship validation;
  - capability-gated REST endpoints;
  - read-only admin tables.
- GitHub Actions foundation audit with PHP 8.3 linting, structural tests, legacy-name scan, secret-pattern scan, external-HTTP scan, and packaging checks.

### Reserved but empty

- AI
- Analytics
- Assets
- Etsy
- Finance
- GST
- Legal
- Listings
- Opportunities module boundary
- Orders
- POD
- Policy
- ProductFamilies module boundary
- Products module boundary
- Quality
- Research
- workers
- retry execution
- rate limiting
- reconciliation
- most admin application areas

### Not yet present as production implementations

- provider registry and encrypted credential storage on `main`;
- OAuth lifecycle and token refresh;
- signed webhook ingestion and replay protection;
- real background worker dispatch;
- retry/backoff and dead-letter handling;
- rate-limit budgeting;
- research ingestion and scoring;
- AI routing, model policy, prompt/version registry, cost ledger, and output provenance;
- design/asset generation and storage abstraction;
- POD catalog, variants, mockups, personalization, pricing, and supplier routing;
- listing composition, SEO, policy validation, and Etsy draft synchronization;
- orders, approval gates, fulfillment, shipment reconciliation, refunds, and exceptions;
- finance ledger, profitability engine, taxes/GST evidence, and reporting;
- analytics, alerts, health monitoring, backup/restore drills, and retention jobs;
- multi-tenant SaaS isolation and billing.

## 4. Findings

| ID | Severity | Finding | Evidence | Required disposition |
|---|---|---|---|---|
| B0-01 | Critical | Global kill-switch posture is not fail-closed by default. | `Config::default_settings()` initializes `stop_all=false`. | Before any executable automation exists, define and test fail-closed semantics. New installs should require explicit activation, and loss of configuration must disable side effects. |
| B0-02 | High | Most planned platform modules are empty placeholders. | Module and automation directories contain only `.gitkeep`. | Implement by dependency-ordered batches; do not expose placeholder functionality as complete. |
| B0-03 | High | CI tests are largely structural source-string assertions. | Tests inspect source with `str_contains`; no WordPress/database integration harness exists. | Add PHPUnit, WordPress test environment, migration tests, REST authorization tests, concurrency/idempotency tests, and negative security tests. |
| B0-04 | High | CI does not run for direct pushes to `main`. | Workflow push trigger names only an old Codex foundation branch. | Trigger CI on `main`, active build branches, and pull requests; retain explicit external-call and secret scans. |
| B0-05 | High | `main` is unprotected. | GitHub reports `protected=false`. | Require pull requests and required checks before merge when repository settings permit. |
| B0-06 | High | No dependency/static-analysis baseline exists. | No Composer manifest, PHPUnit configuration, PHPCS, PHPStan, or WordPress coding-standard configuration. | Establish reproducible dev tooling compatible with PHP 8.3 and the supported WordPress baseline. |
| B0-07 | High | Database relationships rely on application validation without integration coverage. | Custom tables contain indexed identifiers but no database foreign keys; repository code enforces relationships. | Add transaction-aware repository tests, orphan detection, reconciliation, and safe repair policy. |
| B0-08 | Medium | Schema migrations are centralized in one growing method. | All versions are represented in `Migrator::migrate()`; only one legacy transformation is explicitly version-gated. | Introduce ordered, resumable migration steps with failure telemetry and upgrade tests from every supported schema version. |
| B0-09 | Medium | Audit logging lacks retention, export, integrity-chain, and failure handling. | Logger writes one row and does not check/report insertion failure. | Define retention and immutable evidence policy; surface write failures without leaking context. |
| B0-10 | Medium | Queue transitions are permissive. | `JobRepository::transition()` accepts any state in the global state list without a transition graph or compare-and-set. | Add legal transition policy, lock ownership, leases, attempt limits, backoff, dead-letter state, and concurrency tests before dispatch exists. |
| B0-11 | Medium | Settings types are stored but not enforced on read/write. | `setting_type` is persisted; decoding is generic JSON. | Add a typed settings registry and reject unknown/invalid keys and values. |
| B0-12 | Medium | Admin surfaces are read-only scaffolds and do not show effective control state clearly. | Individual switch rows use raw values rather than effective values under STOP ALL. | Show configured versus effective state, environment, safety lock, last actor, and audit reference. |
| B0-13 | Medium | Product Factory lacks update/archive dependency safeguards. | REST supports create/read/transition but no general update or dependency-impact policy. | Specify mutable fields, archival rules, referential restrictions, and version immutability. |
| B0-14 | Medium | Operational metadata is incomplete. | Repository has no license, changelog, release manifest, support matrix, or generated package checksum. | Add repository governance and reproducible release packaging before deployment automation. |
| B0-15 | Medium | An integration-foundation PR is already open and diverges from audited `main`. | PR #6 targets `main` from `phase-7-integrations-foundation`. | Review/rebase it only after Batch 0 decisions; avoid duplicating or silently superseding its security design. |
| B0-16 | Low | Obsolete branches and a stale open repair PR remain. | Multiple old Codex branches exist; PR #2 is open although later merged work contains the repair. | Close stale PRs after verification and delete obsolete branches only with explicit approval. |

## 5. Current architecture coverage

| Architecture area | Current status | Confidence |
|---|---|---|
| WordPress plugin foundation | Implemented | High |
| Security capabilities and basic redaction | Partially implemented | High |
| Database foundation | Implemented through schema v4 | High |
| Product Factory | Foundation implemented | High |
| Digital Product Factory | Foundation implemented | High |
| Queue/idempotency | Persistence boundary only | High |
| Integration security | Not on `main`; proposed in PR #6 | High |
| Research engine | Not implemented | High |
| AI orchestration | Not implemented | High |
| Asset/design factory | Not implemented | High |
| POD production | Not implemented | High |
| Etsy listing/publishing | Not implemented | High |
| Orders and fulfillment | Not implemented | High |
| Finance/GST/legal evidence | Not implemented | High |
| Analytics/alerts/reconciliation | Not implemented | High |
| SaaS/multi-tenancy | Deferred/not implemented | High |

## 6. Recommended build dependency order

1. **Batch 1 — Engineering and safety baseline**
   - fail-closed control semantics;
   - CI trigger correction;
   - PHPUnit/WordPress test harness;
   - coding standards and static analysis;
   - typed configuration;
   - release metadata.

2. **Batch 2 — Migration, queue, and observability hardening**
   - ordered migrations;
   - transactional repositories;
   - legal job transitions;
   - locking, retry, dead-letter, reconciliation;
   - structured health and audit-failure reporting.
   - Worker execution remains disabled.

3. **Batch 3 — Integration registry and credential security**
   - reconcile and independently review PR #6;
   - encrypted write-only secrets;
   - provider/environment separation;
   - connection metadata and audit evidence.
   - No provider network calls.

4. **Batch 4 — Research and opportunity intelligence**
   - source registry, ingestion contracts, scoring, evidence, provenance, deduplication, review queues.
   - Schedules remain disabled.

5. **Batch 5 — AI governance and orchestration**
   - task/model routing;
   - prompt and output versioning;
   - schema validation;
   - cost/usage ledger;
   - safety and human-review gates.
   - AI execution remains disabled until separately authorized.

6. **Batch 6 — Asset and product-production pipeline**
   - storage abstraction;
   - design specifications;
   - generated assets, previews, packages, checksums;
   - technical and visual QA.

7. **Batch 7 — POD provider and personalization foundation**
   - provider-neutral catalog/variant/margin models;
   - mockup and personalization records;
   - Printify/Gelato sandbox adapters.
   - Auto-approval remains deferred.

8. **Batch 8 — Listing and Etsy draft foundation**
   - listing content/version model;
   - SEO, policy, IP, profitability gates;
   - Etsy sandbox/draft adapter and reconciliation.
   - Publishing remains disabled.

9. **Batch 9 — Orders and controlled fulfillment**
   - webhook inbox;
   - order normalization;
   - human approval;
   - fulfillment state reconciliation;
   - exception and refund handling.
   - Personalized auto-approval remains deferred.

10. **Batch 10 — Finance, GST, analytics, and operations**
    - immutable financial ledger;
    - margin and tax evidence;
    - analytics, alerts, retention, backup and recovery drills.

11. **Final activation batch**
    - production readiness review;
    - credential/environment verification;
    - least-privilege activation;
    - canary execution;
    - emergency-stop drill;
    - explicit owner approval.

## 7. Batch 0 acceptance criteria

- [x] Repository and default branch identified.
- [x] Exact baseline commit recorded.
- [x] Existing tree and implementation boundaries inventoried.
- [x] Security and automation posture reviewed.
- [x] Database, REST, queue, admin, tests, and CI inspected.
- [x] Existing open work and overlap risk identified.
- [x] Gaps classified by severity.
- [x] Dependency-ordered build sequence defined.
- [x] No production behavior enabled.
- [x] No external integration invoked.
- [x] No deployment performed.
- [x] No merge performed.

## 8. Exit decision

**Batch 0 status: COMPLETE FOR REVIEW.**

The repository may proceed to Batch 1 only after this audit PR is reviewed. PR #6 should remain unmerged until its credential and integration design is reconciled with the Batch 1 safety baseline. All production and provider controls must remain disabled.
