# DigiForge Production Hardening & WPVibe Reliability

## Scope
This document records Production Readiness P2 (WordPress Production Hardening) and P3 (REST / WPVibe Reliability) findings for the DigiForge production environment at https://digiforge.converentis.com/.

## Safety State
The following controls must remain unchanged until separately authorized:
- DigiForge STOP ALL = ON.
- Activation authorization = OFF.
- Automation armed = FALSE.
- All external feature switches = FALSE.
- No Etsy, Printify, Gelato, AI, order, fulfillment, finance, GST/tax, webhook, or other external side effects.

## P2 — WordPress Production Hardening Findings

### Verified PASS
- WordPress version: 7.1.
- PHP version: 8.3.33.
- `WP_DEBUG` is false.
- WordPress maintenance mode is not active.
- WordPress core checksum verification passed.
- 3,338 WordPress core files verified with no mismatches, missing files, or unexpected files.
- WP-Cron spawn test passed with HTTP 200.
- External object cache/drop-in is active.
- LiteSpeed Cache remains installed and active.
- One administrator account is present in the current WordPress user list.
- DigiForge remains externally locked and fail-closed.

### Host-level hardening completion
The previously identified `DISALLOW_FILE_EDIT` gap has been closed. On 2026-09-25, after confirming recovery evidence, `DISALLOW_FILE_EDIT=true` was applied through safe host-level `wp-config.php` configuration and verified as a boolean value. The post-change check retained READY_LOCKED, schema 14 / 14, recovery PASS, healthy WP-Cron, inactive maintenance mode, all effective external switches false, and `external_actions_performed=false`.

### Historical pre-change safeguards
Before the completed hardening change, configuration-level changes that could affect recovery or site access were intentionally deferred until the production database backup was confirmed and recorded in DigiForge recovery evidence.

Items governed by that safeguard included:
- Define `DISALLOW_FILE_EDIT` as true (completed 2026-09-25).
- Review host-level file and directory permissions.
- Review login/rate-limit protections that require host or security-plugin changes.
- Verify production secret handling at the hosting layer.
- Perform any configuration change that could affect WordPress bootstrap, authentication, caching, or REST routing.

## P3 — REST / WPVibe Reliability Findings

### Root Cause Identified for Current 429 Condition
The connected WPVibe account is on the Free plan.

At the latest audit:
- Rolling 24-hour usage: 301 / 300 calls.
- Remaining calls: 0.
- Two WordPress sites are connected to the same account: converentis.com and findnivo.com.

This means the present `Too Many Requests` / HTTP 429 condition is consistent with WPVibe account quota exhaustion. It is not, by itself, evidence that DigiForge REST endpoints, WordPress REST, PHP, MariaDB, or the production server are failing.

### Reliability Controls
Until quota becomes available:
- Avoid repeated polling loops.
- Batch read operations where possible.
- Prefer GitHub-side work for code and documentation tasks.
- Use a single readiness request after meaningful state changes instead of repeated checks.
- Do not interpret WPVibe quota 429 responses as DigiForge application failures.
- Preserve STOP ALL and external-lock state independently of connector availability.

### Post-Quota Validation
When WPVibe calls are available again, perform a small controlled validation set:
1. `site_info` connection check.
2. DigiForge `/digiforge/v1/readiness` GET.
3. One representative WordPress REST read.
4. One safe WP-CLI read command.
5. Confirm no unexpected 429 response before the account quota is exhausted.
6. Confirm failures, if any, can be separated into account-quota, network, WordPress, or DigiForge layers.

## P1 Recovery Evidence — Completed
Post-deployment verification and the later live 1.0.31 acceptance confirm:
- Database backup evidence: TRUE.
- Plugin package evidence: TRUE.
- Checksum evidence: TRUE.
- Schema version: 14 / 14.
- Recovery status: PASS.
- Overall readiness: READY_LOCKED.
- `externally_locked = true`.
- `external_actions_performed = false`.

The host-level `DISALLOW_FILE_EDIT=true` hardening item was completed through safe `wp-config.php` configuration on 2026-09-25 and verified afterward; it was not emulated with a runtime snippet or database write.

## Completion Criteria
P2/P3 completion criteria are now satisfied for the host-level file-edit hardening and recorded recovery evidence:
- Production backup evidence is complete.
- `DISALLOW_FILE_EDIT` is enabled and verified.
- Core checksums remain clean.
- Debug remains disabled.
- Cron and object cache remain healthy.
- REST/WPVibe validation succeeds with available quota.
- No production safety control has been weakened.
