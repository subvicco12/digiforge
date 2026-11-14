# Printify connector Stage 0/1 implementation plan

Goal: offline-qualified thin companion backed by a DigiForge-owned v1 read interface.
Architecture: retain the existing catalog transport/vault; add fixed GET contract, core host port, immutable scope/intent/result types and minimized projections. Companion has no transport, credentials, tables, routes or schedulers. Native host fails closed if newer owner/shop contracts are unavailable; do not merge unrelated API/portal work to satisfy them.
Spec: PR1262 at761fb79abb2813455b7a90a56aa1fac8e6994f22; existing Stage0 baseline.
Constraints: no live requests/credentials/production/merges; STOP ALL and external lock supersede reads. No authority exceptions. No mutations/historical22. No schema migrations.

- [x] TDD fixed GET contracts and bounded shared catalog transport; preserve legacy tests.
- [x] TDD immutable v1 scope/reference/intent/result, normalized privacy-safe data, and read service with fresh authorization before vault/dispatch/receipt and audited consumed intents.
- [x] TDD native host integration with existing capabilities, owner shop policy, reviewed provider binding, vault and audit; atomic bounded read budget; missing contracts deny.
- [x] TDD companion load-order/idempotent hooks and missing/incompatible-host denial; no independent controller.
- [x] Mock HTTP negatives, WordPress integration and full unit/offline/static/security; review final diff; commit/push existingPR1263, retain exact-head CI evidence (hosted result recorded in PR handoff).
- [x] Record compatibility inventory, native-lineage blockers, API handoff, scope matrix and parallel POD tests without overlapping changes.

Review focus: stale authorization after network; PII/token reflected in provider data; missing native owner/binding interfaces; budget acknowledgement uncertainty; double plugin bootstrap/load order.
