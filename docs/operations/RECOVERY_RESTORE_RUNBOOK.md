# DigiForge Recovery & Restore Runbook

## Scope
This runbook covers recovery of the DigiForge WordPress-native automation platform on converentis.com. It is intentionally written for a fail-closed recovery posture.

## Safety Preconditions
Before any recovery work:
- Keep DigiForge STOP ALL enabled.
- Keep activation authorization disabled.
- Keep automation armed = false.
- Keep all external feature switches disabled.
- Do not allow Etsy, Printify, Gelato, AI, order, fulfillment, finance, tax, webhook, or scheduled-worker side effects during recovery.

## Recovery Evidence Required
A recovery drill is only considered complete when all of the following are available and verified:
1. Current production database backup.
2. Audited DigiForge plugin package for the currently deployed release.
3. Verified checksum for that plugin package.
4. Known database schema version.
5. This restoration procedure.
6. Confirmation that STOP ALL remains active.

## Current Certified Baseline
- Do not infer the live plugin version or database schema from this repository. Read and record both from the target environment immediately before recovery.
- Current repository release candidate: DigiForge 1.0.103 with expected schema 23. Use only the exact independently verified audited artifact for the selected candidate; do not substitute an older recovery package.
- Repository-certified development baseline: derive from the exact audited main commit; do not substitute an unaudited ZIP.
- Production site: https://digiforge.converentis.com/
- External automation state during recovery: locked/off
- Historical rollback-package evidence does not by itself prove compatibility with the current database checkpoint. A recovery drill must use and verify the intended audited plugin/database pair before recording restore, schema or application-health verification.

## Backup Creation Procedure
Create a fresh full production database backup using the hosting provider's database backup/export facility or another approved full-database backup mechanism. The backup must include the complete WordPress database, including all DigiForge tables and WordPress options.

Record at minimum:
- Backup creation timestamp in UTC.
- Backup filename or provider backup identifier.
- Backup storage location.
- Database name/environment identifier.
- Backup size where available.
- Integrity/checksum evidence where the provider supports it.

Do not mark DigiForge database-backup readiness evidence true until the backup is confirmed to exist and is retrievable.

## Restore Procedure
1. Put the WordPress site into a controlled maintenance window if recovery is being performed on production.
2. Confirm STOP ALL is ON before importing any data.
3. Confirm DigiForge activation authorization is OFF and automation armed is FALSE.
4. Preserve the currently deployed database before destructive restore operations when feasible.
5. Restore the selected full WordPress database backup using the hosting/database restore facility.
6. Install or retain the audited DigiForge plugin package matching the intended restore checkpoint. Verify its recorded checksum before use; do not substitute a different package merely because it is newer.
7. Activate DigiForge only if required for the restored installation to match the certified baseline.
8. Verify the DigiForge schema reports current = expected for the selected audited restore checkpoint. Do not manually force the schema option if migrations have failed.
9. Verify STOP ALL is ON.
10. Verify activation authorization is OFF.
11. Verify automation armed is FALSE.
12. Verify every effective external feature switch is FALSE.
13. Verify the DigiForge audit subsystem is healthy.
14. Verify the queue can be queried and contains no expired leases requiring intervention.
15. Verify the isolated recovery environment itself performs no external actions during the drill. Do not require or rewrite production's historical `external_actions_performed` indicator; historical confirmed operations remain part of the audit record.
16. Run the DigiForge recovery-readiness evaluation.
17. Run `/digiforge/v1/readiness` and retain the resulting evidence hash/status.

## Post-Restore Validation
The restored environment must satisfy all of these before normal internal testing resumes:
- Plugin version matches the intended audited release.
- Schema current equals expected.
- STOP ALL active.
- Activation not authorized.
- Automation unarmed.
- No effective external feature switches.
- Audit healthy.
- Queue query verified.
- No expired queue leases.
- Recovery evidence available.
- No external action is performed by the recovery drill. Production's historical `external_actions_performed` value is preserved and is not a drill completion flag.

## Failure Handling
If any validation fails:
- Keep STOP ALL ON.
- Do not enable any external integration.
- Record the failed check and evidence.
- Repair or roll back only the failing layer.
- Re-run the readiness and recovery checks after remediation.

## Rollback Principle
Recovery must never be used as a reason to bypass migration, checksum, authorization, or external-action safety controls. If the restored database/plugin pair is incompatible, restore the last known compatible audited pair rather than forcing readiness flags.

## Readiness Evidence
DigiForge recovery readiness uses structured database-backup and plugin-package evidence, including concrete artifact identity, retrievability and verification/checksum evidence. Legacy database-backup, plugin-package and checksum availability booleans are not recovery evidence and must not be used to bypass the structured evidence contract.

The separate `digiforge_recovery_restore_instructions_available` option remains a readiness check for the existence of approved restore instructions; it is not proof of a backup, plugin package, checksum or completed recovery drill.

## Completion Criteria
The recovery phase is complete only when `/digiforge/v1/readiness` reports:
- recovery status PASS
- `recovery_drill_passed = true`
- overall readiness no longer blocked by missing recovery evidence
- externally locked remains true
- the recovery drill itself performed no external actions; historical production external-action evidence remains preserved
