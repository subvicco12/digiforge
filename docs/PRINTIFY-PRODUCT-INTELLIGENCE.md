# Shop 2 Printify Product Intelligence

Scope: offline research evidence in the existing `pod_personalized` portal. Shop key `personalized_pod`; business `digicraftifygoods`; catalog `digicraftifygoods-master-500-v2`. This is neither catalog promotion nor supplier/production certification. No installation in production is asserted.

## Operation

1. Use the authenticated Google Drive connector to read/download retained source files and metadata. This connector is available in the engineering session; it does **not** provision Google OAuth or an automatic Drive sync in WordPress.
2. Keep originals and downloaded research outside the application repository. Prepare a local manifest (example below), recording the observed Drive ID, original name and modification timestamp, local path and preferably the expected SHA-256. Never include credentials, permits or operational journals.
3. Run `python3 bin/product-intelligence.py /private/manifest.json --output /private/research.json --xlsx /private/research-export.xlsx`. Python standard library only; no network calls. Formula-bearing sheets are rejected; retain a separate approved values-only source if such data is needed. No formula evaluation, tax assumptions, currency conversion or inferred prices.
4. An authorized operator opens **POD — Personalized**, selects **Personalized POD**, and imports the JSON under **Shop 2 · Printify Product Intelligence**. Requires existing `manage_digiforge`, `manage_digiforge_pod`, and `manage_digiforge_research` capabilities plus a WordPress nonce. This writes only a new local research snapshot and its audit receipt. No new roles or production schema migration.
5. Browse section cards, search by concept/blueprint/provider, review source hashes/timestamps and missing fields, select immutable versions, and export a section CSV. CSV includes snapshot/source provenance and neutralizes spreadsheet formulas. The converter optionally exports literal-string XLSX; no XLSX library is installed into WordPress.

Example manifest (replace placeholders using observed metadata):

```json
{
  "sources": [{"id":"OBSERVED_DRIVE_FILE_ID","name":"research.xlsx","path":"/private/research.xlsx","modified_at":"2026-11-02T17:34:50Z"}],
  "sections": {
    "concepts":{"source_id":"OBSERVED_DRIVE_FILE_ID","sheet":"Master_500_v2"},
    "lineage":{"source_id":"OBSERVED_DRIVE_FILE_ID","sheet":"Source_Migration_500"},
    "matches":{"source_id":"OBSERVED_DRIVE_FILE_ID","sheet":"Concept Candidates"},
    "candidates":{"source_id":"OBSERVED_DRIVE_FILE_ID","sheet":"Candidate Links"},
    "blueprints":{"source_id":"OBSERVED_DRIVE_FILE_ID","sheet":"Printify Products"},
    "providers":{"source_id":"OBSERVED_DRIVE_FILE_ID","sheet":"Print Providers"},
    "variants":{"source_id":"OBSERVED_DRIVE_FILE_ID","sheet":"Variants Pricing"},
    "print_areas":{"source_id":"OBSERVED_DRIVE_FILE_ID","sheet":"Print Templates"},
    "shipping":{"source_id":"OBSERVED_DRIVE_FILE_ID","sheet":"Shipping Profiles"},
    "size_guides":{"source_id":"OBSERVED_DRIVE_FILE_ID","sheet":"Size Guides"},
    "gaps":{"source_id":"OBSERVED_DRIVE_FILE_ID","sheet":"Missing Evidence"},
    "coverage":{"source_id":"OBSERVED_DRIVE_FILE_ID","sheet":"Blueprint Coverage"}
  }
}
```

CSV sources use the same binding; `sheet` names the report. All cells become original strings or explicit null, with `_source_row` retaining logical CSV record/worksheet row position. Blank source DG IDs and the literal `V2-PRODUCT-RESEARCH` sentinel remain unknown; no IDs are generated. Conflicting nonmissing parent/product identifiers in linked concept sections reject the packet. Partial 1–500-concept snapshots remain explicit; full-source completeness is not inferred.

## Integrity and authority

Snapshots use insert-only, non-autoloaded options keyed by canonical packet SHA-256. Reads bypass the option cache, verify bytes and rerun validation. Corruption and database errors fail closed; the latest ten version identifiers are listed, with older versions accessible by exact hash. No auto-pruning or overwrite. Audit failure returns an explicit error even if the snapshot was retained. A replay produces another audit receipt without duplicating the snapshot.

The source file hash is retained provenance declared by the import; it is not a signature or a proof that WordPress contacted Drive. Section hashes detect row drift, not forgery by an authorized importer. Every snapshot is UNREVIEWED with source authenticity UNVERIFIED and all production, Finance and external authority false. Original source approval/status cells remain visible as claims; they never set application authority. No provider mapping/geometry/cost table is modified.

Limits: 16 MiB packet/source, 64 MiB expanded XLSX, 50 source files, 50,000 total evidence rows, 64 columns per source row, 64 KiB per cell, 25 cards per page, 120-character search. Originals remain outside the plugin ZIP. Version storage is not an unlimited research warehouse; operators must manage capacity separately without deleting original evidence.

## Read-only Drive verification on 2026-11-03

Authenticated metadata, recursive listing and downloads succeeded for the exact supplied folder. Inventory: **898 entries / 67 folders**, with names, IDs, parent IDs, sizes, MIME types and created/modified timestamps retained in a private local inventory artifact. No subfolders remained unvisited; no listing errors. Metadata for some objects says `not_shared` or `access_not_verified`; actual successful authenticated listings/downloads establish only the exercised read access.

Create/update/upload connector actions are present. Destination write permission was **not tested**. No Drive writes were performed. Any versioned write-back needs separate explicit authorization and supported authenticated tools. The folder contains a starter and populated research workbook, not a separately named original immutable v1 workbook. The populated workbook contains Master_500_v2 and Source_Migration_500; neither replaces the governed v1 source hash.

Downloaded matching/coverage sources reconcile the historical checkpoint: 500 concepts; 429 AMBIGUOUS_CANDIDATES + 23 CANDIDATE_UNVERIFIED = 452 concepts with candidate evidence; 48 BASE_PRODUCT_REQUIRED; 86 shortlisted/coverage blueprints; 84 provider-discovery gaps. There are **1,307 candidate-link rows**, so 452 is a concept count, not number of links or approved matches. No new live Printify verification was performed.

The populated workbook converts to 500 concepts, 500 migration rows, 500 matching rows, 1,307 candidate links, 3,015 blueprint rows, 36 provider rows, 1,347 variants, 4,977 print-area rows, 11 shipping rows, 74 size-guide rows, 500 missing-evidence rows, 86 coverage rows and 33 source-lineage rows. Pixel dimensions lack DPI/bleed/safe-zone certification. Shipping rows are service-index evidence with missing destinations/rates; absent costs remain null. The formula-based Finance Margins worksheet is excluded. Sanitized pilot variant evidence was separately downloaded and inspected as an envelope with fetched timestamp, data hash and payload; no credentials or live endpoints were used.

## Existing architecture and gaps

| Concern | Existing main / implementation |
| --- | --- |
| Portal/navigation | Existing `Portal::personalizedPodSummary()` now mounts a dedicated panel; existing CSS and capabilities reused |
| Master 500 | Existing immutable v1/v2 references and governed ingestion unchanged; independent research snapshots cannot promote them |
| Provider reads | Existing `PrintifyCatalogClient` / `PrintifyCatalogSyncService` unchanged |
| Controlled reads | Richer authenticated grant/reservation/idempotency work exists in open draft integration candidates; presence there is not main/production availability |
| Batch preparation | `ProductIntelligence::batch()` validates positive IDs and ceiling 1–20, deduplicates and returns PLAN_ONLY; source run logs/coverage can be imported as evidence |
| Batch dispatch | Deliberately absent: no scheduler, transport, credential access, grant, exception, reservation, permit or bypass |
| Exports | Portal CSV with provenance; offline literal-string XLSX |
| Finance | Source cost fields only; no activation, financial import or profit computation |

Before any later external execution, an approved dispatcher must reuse the existing authenticated WordPress catalog-read boundary and its grants, reservations, safety exceptions, idempotency and audit controls. This plan does not confer that authority or duplicate the transport.

Repository main was verified at `0e15e4ccae430498c92a30ab1f8951171fa0ad13`. Open PR inventory included cumulative portal/Printify/Google candidate #1242 and isolated Google admission #1243; these remain separate drafts and were not merged into this feature. The Ultimate Master Blueprint v6.0 original, Development Operating Manual and AGENTS.md were not found in main or attached local files. Reviewed source references include the First Launch Master Plan v1.1, four-day MVP supplement, Master 500 reference and v6 acceptance/convergence records. Missing originals remain an owner input, not permission to infer their contents.

## Validation and remaining owner actions

Focused unit/offline/WordPress tests exercise source integrity, unknowns, conflicting lineage, immutable replay, audit integration, storage corruption/failure, permissions, foreign-shop masking, escaped HTML and exports. Mobile browser fixture checks run at 390, 768 and 1280px. See PR evidence for final counts and exact-head CI.

Owner actions remain: supply missing authoritative instructions if applicable; review the draft and research packet; separately authorize installation/local import and any versioned Drive write-back; obtain missing supplier costs, destination/rate details, physical geometry, rights and certification; separately authorize any live catalog execution. No deployment, merge, production migration, publishing, orders or capability activation is part of this delivery.

Local verification receipt: Composer legacy/unit/static/standards PASS (1,162 unit tests / 7,360 assertions); offline 66 PASS; focused WordPress 6 tests / 18 assertions PASS; rendered authenticated-panel fixture 15 browser checks at 390/768/1280px PASS. Local PHP 8.4 emits existing dynamic-property and undefined-variable notices; CI targets PHP 8.3. The broader local WordPress run had 203 tests / 1,813 assertions and eight failures, reproduced independently on unchanged baseline: `MigrationTest::testSchemaFifteenPreservesOperationalTablesAndCreatesCurrentTables`; `PrintifyPreflightTest::testProductionPermitConsumptionIsOneTimeFingerprintBoundAndExpirySafe`; and six `Schema19LifecycleProvenanceTest` cases (`testAtomicConsumptionPersistsNonceAndBindingTogether`, `testBindingFailureRollsBackNonceConsumption`, `testCompetingAuthorizationForSamePackageRollsBackLosingNonce`, `testConsumptionRejectsPackageIdHashDriftBeforeNonceMutation`, `testPermitPersistenceReconciliationIsReadOnlyAndNeverRestoresAuthority`, `testPermitPersistenceObservationTransitionsWithoutGrantingRetryOrAuthority`). A pre-existing MariaDB 11.8 audit-table default-value migration diagnostic is also present. No shared migration or test gate was changed to hide these failures. Hosted exact-head CI remains the separate delivery gate.

Independent review identified the audit argument defect and contradictory match-lineage acceptance; both were reproduced and corrected with regression tests. CSV provenance and exact converter source-row metadata were also added. No independent live provider or installed-site certification was performed.
