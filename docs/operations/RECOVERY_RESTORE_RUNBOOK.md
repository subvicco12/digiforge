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
- Current live DigiForge release: 1.0.33
- Repository-certified release: 1.0.33
- Expected DigiForge database schema: 14
- Production site: https://digiforge.converentis.com/
- External automation state during recovery: locked/off

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
6. Install or retain the audited DigiForge 1.0.33 plugin package matching the currently verified live deployment. Verify its recorded checksum before use; do not substitute a different package merely because it is newer.
7. Activate DigiForge only if required for the restored installation to match the certified baseline.
8. Verify the DigiForge schema reports current = expected = 14. Do not manually force the schema option if migrations have failed.
9. Verify STOP ALL is ON.
10. Verify activation authorization is OFF.
11. Verify automation armed is FALSE.
12. Verify every effective external feature switch is FALSE.
13. Verify the DigiForge audit subsystem is healthy.
14. Verify the queue can be queried and contains no expired leases requiring intervention.
15. Verify the readiness endpoint reports no external actions performed.
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
- `external_actions_performed = false`.

## Failure Handling
If any validation fails:
- Keep STOP ALL ON.
- Do not enable any external integration.
- Record the failed check and evidence.
- Repair or roll back only the failing layer.
- Re-run the readiness and recovery checks after remediation.

## Rollback Principle
Recovery must never be used as a reason to bypass migration, checksum, authorization, or external-action safety controls. If the restored database/plugin pair is incompatible, restore the last known compatible audited pair rather than forcing readiness flags.

## Readiness Evidence Flags
DigiForge currently evaluates these WordPress options as recovery evidence markers:
- `digiforge_recovery_database_backup_available`
- `digiforge_recovery_plugin_package_available`
- `digiforge_recovery_checksum_verified`
- `digiforge_recovery_restore_instructions_available`

These flags are declarations of verified evidence, not substitutes for the evidence itself. Set them only after the underlying evidence exists and has been checked.

## Completion Criteria
The recovery phase is complete only when `/digiforge/v1/readiness` reports:
- recovery status PASS
- `recovery_drill_passed = true`
- overall readiness no longer blocked by missing recovery evidence
- externally locked remains true
- external actions performed remains false
