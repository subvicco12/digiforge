# Etsy observed monthly CSV header integration

Engineering baseline: `26d8d3ada076e5bcaa6ba1a4f5c4ffc3e6dc9c4e` (Audit #4830 PASS). Draft/offline only; no accounting authority.

## Verified input

Owner-supplied archive: 14,245 bytes, SHA-256 `79cfd7e699aa7491ef46ab22bca3cbbafcefa97ccd4700e5db83c30bb920845d`. All ten unique basename-only members passed CRC, and all nine manifest-listed hashes/lengths matched. [Independent manifest evidence](evidence/etsy-monthly-header-input-verification.json).

The exact 73-byte witness, including UTF-8 BOM and LF, is pinned unchanged at `includes/Finance/contracts/etsy-monthly-header-v1.csv`, SHA-256 `4b9711a954913dd223c23cde077a22900e836f45803a61442dc0484c81b74f11`:

```
Date,Type,Title,Info,Currency,Amount,"Fees & Taxes",Net,"Tax Details"
```

The private original statement was not supplied or read. Its whole-file hash, encoding, data population, download receipt, source issue time and publisher revision remain UNKNOWN. The header-only witness cannot establish original-file custody, financial-period completeness or authenticated account identity. Attachment instructions/proposed mappings were inspected as data and were not applied as accounting authority.

## Implementation

`EtsyMonthlyCsvStage` selects `etsy_monthly_csv_v1`. `ObservedCsvHeaderStage` shares the existing bounded syntax/population/provenance controls with the Printify wrapper. Server-pinned header bytes, fingerprint, provider and both active Etsy shop scopes are enforced. Original UTF-8 input remains unchanged and hash-bound; the BOM is removed only from the internal decoded header comparison, never original bytes. Missing/reordered/duplicate/truncated/altered headers and unsupported encoding/quoting/width/size fail closed. Duplicate whole files and conflicting immutable capture references are denied. Replay is deterministic inspection, not a durable custody claim.

Existing limits remain: 20 documents, 64 KiB per document, 256 KiB combined bytes, 500 physical data rows, 4096 bytes per field. Caller layouts and accounting overrides are unsupported for the observed profiles. Unrecognized data-cell values remain opaque; formula-like values are not executed or exported as executable spreadsheets.

The shared-code extraction was compared against the exact baseline Printify class: complete header-only and synthetic-row projections, including stage hashes, were identical. No repeated Printify source research or new provider integration.

The authenticated Finance workbench adds a separate Etsy profile and accurate header-witness warnings. Existing nonce, all-four-shop Finance authorization, active-shop restrictions and post-inspection revocation checks remain. Raw cells and private diagnostics are withheld; input fields are cleared. Existing native JSON parsing and encrypted custody contracts remain unchanged. A CSV observation cannot enter accounting declarations or native custody, certify profitability, approve a period, or authorize provider execution.

## Regression evidence

New unit coverage was observed RED before implementation (24 missing-profile failures), then GREEN alongside existing Printify tests: 52 tests / 199 assertions. A portal regression was observed RED for misleading cross-profile wording, then the complete source-workbench suite passed: 20 tests / 142 assertions.

Full unit validation passed: 1,939 tests / 10,329 assertions, one existing skip, with configured PHPStan and coding standards. An initial invocation omitted the existing pinned offline Python environment setting and failed the cutout prerequisite; the configured rerun passed without source or assertion relaxation.

Final full WordPress, native browser, catalog/offline/security/package and exact-head audit results are recorded in the issue #1209 completion evidence after their runs finish. No pending result is represented as passing, and synthetic local/browser checks are not installed-site or provider acceptance.

## Acceptance and blockers

Only bounded implementation/test references are added to the existing ledger. All 23 Finance and 22 unresolved non-Finance criteria remain UNVERIFIED; 246/268 implementation candidates, 500 identities and 20 review waves are preserved.

Still required: original-file custody/receipt and exact full-source hashes, attributable account/shop/integration binding, authentic rows and authoritative money units/precision, dates/timezones, Type enumeration, details encoding, stable IDs, corrections, completeness/finality and independently reviewed accounting rules. Header-only zero rows never implies zero expenses. No paid Printify invoice semantics, Etsy accounting mapping, FX assumption or settlement recognition was introduced.

STOP ALL ON; external lock ON; automation OFF; RELEASE HOLD. Operational/commercial release NO-GO.
