# DigiForge Production Hardening & WPVibe Reliability

## Scope
This document records Production Readiness P2 (WordPress Production Hardening) and P3 (REST / WPVibe Reliability) findings for the DigiForge production environment at https://converentis.com/.

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

### Hardening Gap
`DISALLOW_FILE_EDIT` is not currently defined in `wp-config.php`.

Recommended production setting after backup confirmation:

```php
define( 'DISALLOW_FILE_EDIT', true );
```

This prevents plugin/theme source editing through the WordPress dashboard while preserving normal plugin/theme operation and deployment workflows.

### Changes Deferred Until Backup Confirmation
Do not make configuration-level changes that may affect recovery or site access until the production database backup has been confirmed and recorded in DigiForge recovery evidence.

Deferred items include:
- Define `DISALLOW_FILE_EDIT` as true.
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

## P1 Dependency
P1 remains incomplete only because real production database backup evidence is still pending.

Once the backup exists and is verified:
- Set `digiforge_recovery_database_backup_available = true`.
- Re-run `/digiforge/v1/readiness`.
- Confirm recovery status = PASS.
- Confirm `recovery_drill_passed = true`.
- Confirm overall status advances to `READY_LOCKED` if no other check fails.
- Confirm `externally_locked = true` and `external_actions_performed = false` remain unchanged.

## Completion Criteria
P2/P3 can be considered operationally certified when:
- Production backup evidence is complete.
- `DISALLOW_FILE_EDIT` is enabled and verified.
- Core checksums remain clean.
- Debug remains disabled.
- Cron and object cache remain healthy.
- REST/WPVibe validation succeeds with available quota.
- No production safety control has been weakened.
