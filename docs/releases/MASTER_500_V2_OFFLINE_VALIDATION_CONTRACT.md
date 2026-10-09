# Master 500 v2 offline validation contract

Scope: owner-provided `DigiForge_DigiCraftifyGoods_Master_500_v2_Research_Migration_Plan(1).xlsx`. This contract defines a **read-only candidate check**. It is not a live migration, a source-catalog overwrite, or an authorization to promote.

## Deterministic preflight

A validator must inspect `Source_Migration_500` and `Master_500_v2` with all 500 data rows, excluding the header. Fail closed on missing sheets/columns, blank or duplicate DG/DG2 IDs, duplicate populated successor references, a successor that is absent from the v2 IDs, or a populated v2 source that is absent from source IDs.

For each `KEEP` source, require a populated successor and verify that the successor v2 row points back to the same source ID. For `MERGE` and `DOWNGRADE`, require no direct successor unless a separately documented migration exception exists. Require v2 rows with an origin claiming retention to have source lineage. Compare the `Family_Rebalance` v2 targets against exact counts by family; reject unrecognized families and noninteger targets.

Validate disposition and recommended-stage vocabularies against the actual workbook values; do not silently normalize unknown values. Record each exception with row number, column, source identifier, reason and severity. Calculate a SHA-256 of the raw workbook and a deterministic canonical row digest before any subsequent candidate review.

## Non-structural gates

Provider availability, design originality/licensing, template QA, product economics, demand research, supplier-routing compatibility, catalog idempotency and rollback evidence require their own checks. Passing structural validation does not pass these gates.

## Current observed baseline

The 2026-10-09 offline inspection found 500 unique source IDs, 500 unique v2 IDs, 428 populated unique direct successors, 428 populated unique v2 source references, no dangling v2 source references, and 24 matching family targets. Dispositions: 428 KEEP, 37 MERGE, 35 DOWNGRADE. **Classification remains MIGRATION_CANDIDATE.**

## Execution boundary

Do not import into production, update the live catalog, promote, publish to Etsy, dispatch to POD providers or arm automation without distinct explicit owner authorization and governed evidence.
