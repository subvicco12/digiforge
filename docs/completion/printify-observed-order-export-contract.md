# Printify observed order-export contract

Starting engineering head: `23b1717510f5873be662806bdd91f37fe0e9c2b7`.

The owner-supplied ZIP is 17,277 bytes, SHA-256 `7f804aac62dbd15c00bc6b38a3c2663a735f6375ae9ae7dd8475bbfcc474afb7`. All nine unique members passed CRC checks; all eight manifest-listed hashes and lengths matched. [Independent verification](evidence/printify-order-header-input-verification.json) records exact members and twenty ordered column names. Attached instructions were treated as data, not executed.

The unchanged runtime header is 306 bytes, SHA-256 `8b5db86bf5a3855b24a3082e660c97d646743e48023b8d421dea0e65a882c78d`: ASCII/valid UTF-8, no BOM, single LF, original mixed quoting. The package has no transaction rows. Download provenance is owner-supplied, not independently publisher-verified; filename and store labels do not establish account ownership.

## Implemented boundary

`PrintifyOrderExportStage` reuses bounded CSV syntax/population validation, exact scoped context and the authenticated Finance source workbench. The observed profile accepts only the exact header/order/bytes, UTF-8 bodies and exact document keys. Caller-supplied layouts, malformed quoting, width/size/population violations, duplicate files, conflicting immutable capture references and foreign scopes fail closed. Alternate header serialization requires separately supported evidence, not silent normalization.

Original input bytes remain unchanged and hash-bound; capture/reference hashes contribute to deterministic stage identity. Header-only input is an operational observation, never evidence of zero expenses. Money, units, dates, statuses, transaction identities, invoices, source completeness and finality remain UNKNOWN. No proposed accounting mappings were adopted. Existing generic CSV inspection and eight Etsy schemas remain separate.

This preview is nonpersisting. Existing encrypted native custody remains restricted to its approved Digital/Etsy ownership contract; the observed Printify header does not authorize POD account custody. No tables, credentials, transports, declarations, period approvals, profitability certifications or production authority were added. Raw customer values and links are not displayed.

## Local verification

- Focused source/parser unit: 152 tests / 417 assertions.
- Full unit: 1,915 tests / 10,240 assertions; one existing skip.
- Focused WordPress source/custody/Finance: 149 tests / 2,076 assertions.
- Authenticated isolated browser: 284 checks, 486 same-origin requests; zero external requests or business-row mutations across 47 business tables. Widths 390/768/1280. [Source-bound result](evidence/printify-order-header-local-browser.json).
- Offline: 195 tests; security regressions: 9 tests; supplemental changed-runtime PHPStan level 5 passed.

Full WordPress, catalog, packaging and exact-head hosted audit results are recorded in the final issue #1209 evidence and delivery report after completion; these claims are not inferred from a pending run.

## Acceptance and remaining evidence

All 23 Finance-dependent and 22 unresolved non-Finance criteria remain UNVERIFIED. The ledger adds bounded implementation/test references only; no certification, 500 identity or 20-wave changes.

Required next evidence: scoped seller/account/integration ownership, representative original export records and source version, documented money units/precision, timestamps/timezones, status semantics, stable identity/corrections, paid invoice relationships, completeness/finality and reviewed accounting rules. A CSV header cannot certify settled production/shipping charges or financial acceptance. No Etsy CSV mapping was introduced.

STOP ALL ON; external lock ON; automation OFF; RELEASE HOLD. Release: NO-GO.
