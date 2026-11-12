# Shop 2 Product Intelligence

Implement the owner's six-phase request in the existing `pod_personalized` portal; shop key `personalized_pod`, business `digicraftifygoods`. No deployed-site claim. Main baseline: 0e15e4ccae430498c92a30ab1f8951171fa0ad13.

Use a bounded offline XLSX/CSV converter and immutable content-addressed WordPress option snapshots, avoiding a production schema migration. Keep original columns, null cells, DG/DG2/PP identities, source hashes, Drive file IDs, modification times and worksheet/row lineage. Research approval is always UNREVIEWED; source claims do not authorize mappings or production. Hashes prove retained bytes, not provider authenticity. Import/export requires existing portal and POD capabilities; import additionally requires research capability and nonce. Read errors and corrupted snapshots fail closed. No client-supplied shop fallback.

The portal provides section selection, search, pagination, row details, provenance, snapshot selection, CSV export and local JSON import. XLSX conversion/export stays offline. Compare suppliers using source rows; do not infer physical geometry, costs, tax, shipping rates or certification. Batches are bounded plans only; all execution authority is false. Existing Printify client and its authorization remain untouched.

Alternatives considered: extending governed Master 500 ingestion would confuse research with catalog promotion; adding new provider transport would duplicate existing integration and violate the live-call boundary. A research-only snapshot is the selected engineering approach under the owner's autonomous execution instruction.

Authority reviewed: First Launch Master Plan v1.1, four-day MVP supplement, Master 500 reference, v6 convergence and release acceptance records. Original Ultimate Master Blueprint v6.0, Development Operating Manual and AGENTS.md were not present in main or attached local files; do not claim to have read them. Open draft integration work is not main or installed production.

STOP ALL ON; external lock ON; automation OFF. No grants, exceptions, external batch dispatch, Finance activation, Etsy publishing, orders, merges, deployments or Drive writes.
