# Master 500 v2 candidate validation — 2026-10-09

Source: owner-provided `DigiForge_DigiCraftifyGoods_Master_500_v2_Research_Migration_Plan(1).xlsx`. This is **offline structural validation**, not migration execution, provider certification or promotion authorization.

## Structural checks

| Check | Result |
| --- | --- |
| Source migration rows | 500 |
| Master v2 candidate rows | 500 |
| Source DG IDs unique | 500 / 500 |
| V2 IDs unique | 500 / 500 |
| Source successor IDs populated and unique | 428 / 428 |
| V2 source references populated and unique | 428 / 428 |
| Dangling populated V2 source references | 0 |
| Family target counts versus v2 rows | All 24 match |

Source dispositions: **428 KEEP, 37 MERGE, 35 DOWNGRADE**. Recommended stages: **428 Re-score, 42 Provider + demand research, 30 Deep research**. Supplier gates: **415 PRINTIFY_PRIMARY, 43 ROUTE_BY_BASE_PRODUCT, 42 PROVIDER_RESEARCH_REQUIRED**.

## Limits and next gates

- These checks establish basic structural consistency, **not** economic viability, licensing compliance, supplier availability, template readiness, Etsy publication authority or production data safety.
- The 72 source entries not carried as direct v2 successor IDs require governed overlay/merge/downgrade traceability during migration rehearsal.
- Confirm immutable candidate snapshot and source hashes; map all 500 rows to migration schema; dry-run against isolated fixtures only; review collision handling, idempotency, lineage, rollback and audit receipts.
- Require explicit human promotion decision and **separate authorization** before any Master 500 production promotion, external provider action or live data mutation.
- Do not treat this document as a PASS for the release-exit matrix; classification remains `MIGRATION_CANDIDATE`.
