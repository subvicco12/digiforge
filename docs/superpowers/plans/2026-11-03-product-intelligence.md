# Product Intelligence Implementation Plan

> Execute natively using superpowers:executing-plans; user explicitly authorized autonomous offline engineering.

Goal: source-backed, shop-scoped research import and mobile portal inside existing Shop 2.
Architecture: offline converter -> immutable validated snapshot -> existing authenticated portal.
Spec: ../specs/2026-11-03-product-intelligence-design.md
Tech: PHP/WordPress; Python standard library XLSX/XML and CSV.

## Constraints and review focus
Preserve IDs/nulls/provenance; no promotion or provider execution. Test malformed/hash-mismatched sources, duplicate concept IDs, foreign shop, unavailable/corrupt storage, spreadsheet formulas and untrusted HTML. Bound upload, rows, cells, batch quantity and pagination.

- [x] Add failing contract/converter tests for integrity, scope, identifiers, unknown values, exports and inert batch plans.
- [x] Implement offline converter and PHP validation/projection/export; verify focused tests.
- [x] Implement immutable local snapshot storage, capability/nonce protected handlers, existing portal integration and responsive evidence cards.
- [x] Verify unit/offline/security checks, integration access/storage tests and browser layout where supported.
- [x] Record read-only Drive inventory/reconciliation and missing source authority; create one draft PR and inspect exact-head CI (delivery receipt recorded in PR). Never merge/deploy.
