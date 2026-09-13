# DigiForge Production Operations Guide

## 1. Purpose
This guide is the production operating reference for DigiForge. It consolidates the runtime architecture, safety model, recovery, deployment, rollback, integration preparation, activation sequencing, testing, troubleshooting, and operational governance required before controlled external activation.

## 2. Current certified baseline
- Product: DigiForge
- Runtime: WordPress plugin
- Current production host: converentis.com
- Current plugin release baseline: 0.11.1
- Current database schema baseline: 13
- Operating posture: LOCAL-FIRST and FAIL-CLOSED
- STOP ALL: must remain ON until explicit activation authorization
- Activation authorization: OFF until separately approved
- Automation armed: FALSE until separately approved
- External actions: prohibited while locked

Repository documentation may advance ahead of the live deployment. A merged repository change does not mean production has been updated.

## 3. Architecture overview
DigiForge is a WordPress-native operations and automation platform for the DigiCraftify ecosystem. Its principal domains include:
- research and opportunity intelligence;
- AI governance/orchestration foundations;
- product factory;
- production management;
- digital product records;
- POD provider and personalization foundations;
- Etsy listing preparation and draft-package generation;
- orders and fulfillment planning;
- finance ledger, FX snapshots, tax classification and analytics;
- integration registry and credential security;
- queue, lease, idempotency and audit infrastructure;
- alerts, system health and final readiness controls.

The architecture separates local preparation from external execution. Local records, plans, drafts, calculations and validation can exist while all external actions remain disabled.

## 4. Database and schema governance
- Schema changes must be versioned and migration-driven.
- Production schema must match the expected plugin schema before readiness can pass.
- Migration failures must fail closed.
- Database changes must not be used to bypass authorization or activation gates.
- Before destructive or production-affecting migration work, a recoverable production database backup must exist.
- Schema version 13 is the current certified baseline for release 0.11.1.

## 5. Safety and external-action model
External execution is governed by layered controls.

Required locked posture before any activation:
- STOP ALL = ON;
- activation authorization = OFF;
- automation armed = FALSE;
- all external feature switches = FALSE;
- external_actions_performed = false.

External feature areas include research, AI, product development, Printify, Gelato, Etsy draft, Etsy publish, order automation and GST/tax automation.

No individual feature switch may be treated as sufficient authorization. Operation-specific capability, credential, environment, validation and audit checks remain required.

## 6. STOP ALL
STOP ALL is the emergency and default production safety control.

When STOP ALL is ON:
- external execution must not occur;
- provider actions must fail closed;
- queues/workers that would create external side effects must not execute those effects;
- publishing, fulfillment, refunds/cancellations, provider orders, AI calls, financial/tax actions and marketplace mutations remain blocked.

Turning STOP ALL off is a production activation event and requires explicit authorization. It must never be disabled merely to test a feature.

## 7. Readiness states
The readiness service evaluates schema health, safety locks, audit/queue health and recovery evidence.

Typical states:
- REVIEW_REQUIRED — one or more readiness requirements remain incomplete;
- READY_LOCKED — readiness requirements have passed while external execution remains intentionally locked.

READY_LOCKED is not permission to activate external automation. It means the platform is ready while still safely locked.

## 8. Recovery and disaster-recovery evidence
Production recovery certification requires truthful evidence, not merely option flags.

Required recovery evidence includes:
- current production database backup available;
- audited plugin package available;
- package checksum verified;
- current schema version known;
- restore instructions available;
- STOP ALL confirmed.

The formal restore runbook is maintained separately under docs/operations/RECOVERY_RESTORE_RUNBOOK.md.

Never set a recovery-evidence flag true unless the underlying evidence genuinely exists.

## 9. Backup requirements
Before production changes that could affect configuration, schema, plugin behavior or recoverability:
1. create/verify a current production database backup;
2. preserve the audited plugin package;
3. preserve/verify its checksum;
4. record the current schema version;
5. confirm STOP ALL is ON.

Backups must be restorable and locatable; a partial table snapshot is not a substitute for a proper production database backup.

## 10. Deployment
Production deployment should use an audited artifact generated by the DigiForge GitHub Actions safety workflow.

Deployment sequence:
1. merge only an audited PR head;
2. confirm the main-branch commit intended for release;
3. verify release artifact/checksum;
4. confirm production backup and recovery readiness;
5. confirm STOP ALL remains ON;
6. deploy the audited package;
7. verify plugin activation and schema;
8. run readiness and health checks;
9. run internal smoke tests only;
10. confirm all external switches remain OFF unless a separately authorized activation is being performed.

## 11. Rollback
Rollback must prefer restoring a known-good audited plugin package and, when necessary, restoring the associated database backup.

Rollback triggers include:
- fatal errors;
- failed migrations;
- schema mismatch;
- broken REST/admin behavior;
- queue/audit health degradation;
- unexpected external-action eligibility;
- readiness regression that cannot be safely corrected in place.

During rollback:
- keep STOP ALL ON;
- do not activate integrations to diagnose the problem;
- preserve logs and evidence;
- document the failed release and recovery action.

## 12. Admin / Control Center
The central DigiForge Control Center provides read-only operational visibility for:
- system status;
- readiness;
- safety and controls;
- module navigation;
- recovery/readiness evidence.

Safety status pages must not become a shortcut for arming automation or disabling STOP ALL without the separately governed activation process.

## 13. Integration preparation
Integration preparation is distinct from activation.

Prepared integration classes include:
- Etsy;
- Printify;
- Gelato;
- AI providers;
- future finance/accounting/payment/analytics providers.

Preparation includes credential mapping, environment isolation, least-privilege scopes, idempotency, retry/circuit-breaker policies, webhook security, audit requirements and explicit activation gates.

No real secret should be committed to source control, logs, fixtures or documentation.

## 14. Credential security
Credentials must:
- be encrypted at rest using DigiForge credential-security infrastructure;
- be environment-scoped;
- never be committed to Git;
- support revocation/replacement;
- fail closed when missing, invalid or expired;
- expose only non-secret metadata to administrators.

Sandbox/test and production credentials must remain separated where supported.

## 15. Webhooks and workers
Before live webhook or scheduled-worker activation, require:
- signature verification;
- replay/timestamp protection;
- idempotency/deduplication;
- environment validation;
- rate limiting;
- audit logging;
- STOP ALL enforcement;
- explicit activation authorization.

No externally acting schedule should be enabled merely because infrastructure exists.

## 16. Production smoke testing
The internal smoke path is:
Opportunity → Product Family/Product/Version → Production Plan → Digital/POD record → Listing → Etsy Draft Package → Mock Order → Fulfillment Plan → Finance → Analytics → Audit → Final Safety Verification.

Smoke testing must remain internal-only until separately authorized. It must not publish listings, submit provider orders, call AI providers or mutate external systems.

Production smoke execution requires recovery readiness first.

## 17. Failure-scenario testing
Failure testing must cover at minimum:
- invalid/malformed input;
- duplicate idempotency keys;
- expired leases;
- missing/revoked credentials;
- unauthorized access;
- provider timeout/unavailability;
- interrupted queues and dead-letter behavior;
- migration/database failure;
- plugin-update recovery;
- finance/FX inconsistency;
- emergency STOP ALL;
- activation-gate enforcement;
- external-action sentinel verification.

Failure injection against production is prohibited until backup/recovery prerequisites are satisfied and the test is specifically approved.

## 18. Troubleshooting order
When DigiForge reports a problem, investigate in this order:
1. confirm STOP ALL remains ON;
2. check readiness status;
3. check system health/audit status;
4. check schema current vs expected;
5. check queue query health, leases and dead letters;
6. check WordPress/PHP errors without enabling unsafe public debug output;
7. check credentials/environment metadata if an integration is involved;
8. review the relevant audit trail;
9. compare the live plugin version/package with the audited repository release;
10. rollback if safe correction cannot be demonstrated.

Do not disable safety controls as a troubleshooting shortcut.

## 19. WPVibe / management API throttling
Intermittent HTTP 429 responses from the management connector can be connector-plan quota exhaustion rather than a DigiForge REST defect. Distinguish connector limits from application failures before changing runtime code.

## 20. Activation sequence
Never activate the entire platform at once. Future activation order remains:
1. Research
2. AI
3. Product Development
4. Printify/Gelato
5. Etsy Draft
6. Etsy Publishing
7. Orders
8. Fulfillment
9. Finance automation
10. Tax functionality

Each stage requires separate authorization, validation and audit evidence.

## 21. Activation checklist for each stage
Before activating any stage:
- recovery readiness passed;
- STOP ALL decision explicitly authorized for the required scope;
- specific feature switch identified;
- required credential/environment validated;
- least-privilege permissions reviewed;
- operation-specific tests passed;
- failure handling and rollback documented;
- audit visibility confirmed;
- previous activation stage stable;
- explicit approval recorded.

## 22. Version and change governance
Every production-affecting change should be traceable through:
- branch;
- pull request;
- exact audited head SHA;
- GitHub Actions audit run;
- merge commit;
- release/package checksum when deployed;
- live verification evidence.

Do not merge a PR head that changed after the audited SHA without re-checking the new head and its CI result.

## 23. Current production-readiness dependency
Repository work may continue while production remains locked, but live smoke/failure testing and final recovery certification depend on a genuine current production database backup and truthful recovery evidence.

## 24. Final production audit
The final independent audit should include:
- GitHub CI success;
- PHPUnit/unit tests;
- WordPress/MariaDB integration tests;
- PHPStan/PHPCS;
- secret/security scans;
- external HTTP scans;
- reproducible packaging;
- package-boundary verification;
- permissions/admin review;
- live smoke verification;
- recovery certification;
- readiness verification;
- confirmation that external automation remains locked unless explicitly authorized.

## 25. Operating principle
DigiForge is designed to prefer a safe refusal over an unsafe action. Missing evidence, ambiguous authorization, invalid credentials, schema mismatch or uncertain environment state must result in a fail-closed outcome.