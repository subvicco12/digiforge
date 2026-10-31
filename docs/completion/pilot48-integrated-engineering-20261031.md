# Accelerated pilot engineering integration — 2026-10-31

Certified parent: `b81261df382ecc741654212cba5bd97001db5098`, audit4837 PASS. Existing controlled integration branch: `integration/full-product-certified-20261031`. This batch does not reuse that audit for changed code. Final pushed SHA, terminal exact-head audit and complete counts are recorded in issue1209 and the final execution response after certification.

## Isolation and reviewed integration

Three sibling worktrees/branches were created from the exact parent, each with exclusive runtime/tests, its own disposable database and private upload root. No lane touched shared ledgers, migrations, CI or other lanes' files. Root alone reviewed and integrated changes. Original lane branches and commits are preserved; no merge to main, deployment or production integration occurred.

| Lane | Pushed source commit | Scope | Owner integration commit |
| --- | --- | --- | --- |
| Digital | 9f0cdfb279d8598cabeaa0f3fdbae088566fa844 | DigitalFactory repository + dedicated identity regression tests | 367bb9bb3bd26325c78c713fb05216097cc34ccc |
| PET | c8e17b71e1138c89e20d6040aa765ad313102b43 | Existing transformation workflow + its regression tests | f5e7da40636a2de2c383b7b3afcf92acef3e3f1f |
| Finance | f6515b5c940b34c6f86c3f88f862172fbc2e6de5 | Generic structural CSV stage + its tests | b24499b8232858c82c5026cbc54837e3de7c61d4 |

Each reviewed sibling patch was replayed with original-commit provenance onto the controlled parent. Files did not overlap; no conflict resolution or divergent historical Finance merge was needed. The integration owner separately owns the existing local ZIP producer and its new package tests. No prior compositor, PNG preparation, CSV header adapter or provider client was recreated.

## One software-gap and acceptance-blocker register

These are bounded engineering behaviors, not new authoritative acceptance IDs. Existing268 non-Finance/23 Finance criteria remain authoritative; no status is promoted by this register.

| Requirement/behavior | Reproduced gap and completed correction | Executable proof | Remaining acceptance gate |
| --- | --- | --- | --- |
| Digital immutable versions/customer-package identity; NF-lineage | File-version hashes/storage/parent IDs and package manifest/hash/size/lineage could change under an existing passing QA target. Freeze populated identity; preserve only one-time empty PENDING population before any QA. Initial fill atomically checks prior defaults/state and absence of QA, preventing stale initializers and late-QA races. Status/name changes remain possible; edits need new IDs. | Pilot48DigitalImmutableIdentityTest: five behavioral cases, including competing initialization and concurrent QA. Existing edited-document journey verifies new version cannot borrow prior approval. | Exact authentic v0.5 bytes and independent exact-file review. |
| Exact customer ZIP membership/source identity | Duplicate flattened names silently replaced an earlier file; changed source bytes were packaged under old declared checksum; same content replay failed after source mtime changed. Reject duplicate names and source hash/size drift; read back exact ordered ZIP members, lengths and streaming SHA-256. New ZIP metadata is deterministic; legacy valid ZIP bytes are verified and preserved. Atomic same-directory no-replace commit avoids overwriting a competing output. New archive publication requires host hard-link support; missing capability fails closed, with no overwrite fallback. Existing valid archives remain readable without that capability. | Pilot48CustomerPackageIntegrityTest: duplicate-name and stale-source RED cases; mtime replay RED case; fresh deterministic ZIP, original legacy bytes, missing identity and unexpected-member controls GREEN. No marketing/evidence file is implicitly added. | Actual v0.5 customer-delivery selection/license/instructions and sealed package hash cannot be established without source bytes. |
| PET supported recipe review; NF-personalization-engine-coverage | Invalid crop/placement/alignment/zero canvas could become immutable reviewed parameters, blocking corrected attempts forever. Preflight uses the unchanged bounded compositor before review storage/append; invalid attempt leaves sequence/evidence unchanged. Expected final reference hash still belongs to explicit render. | FiveEngineArchitectureTest::testCutoutInvalidCompositionCannotPoisonImmutableReview plus existing actual cutout/preview, cancellation, revocation, lock and audit regressions. All22 supplied compositor cases remain applicable. | Licensed genuine photo/matte/reference/result, independent artistic review, host budget review, provider geometry and product approval. Historical invalid reviews are not rewritten; use a new governed job. |
| Finance P04/source-bound staging | Generic CSV accepted conflicting byte strings under one local reference and omitted capture/reference identity from its fingerprint. Reject conflicting references; bind canonical capture/reference hashes without disclosing raw references. | FinanceCsvSourceStageTest: conflicting-source RED, capture-fingerprint RED, distinct-source/UNKNOWN and redaction controls GREEN; existing Etsy/Printify profiles retained unchanged. | Authentic ownership/source records, classifications, precision/FX/finality/completeness and accounting/tax approvals. local:// is not certified provider evidence; no durable or accounting authority added. |
| Wedding Guide v0.5 exact source/customer acceptance | Existing admission receipts record Letter/A4 hashes,52 named fields and9 pages. Those are historical technical receipts, not current source access or visual/rights approval. One bounded inventory found no four exact files. | Source-access JSON:3,195 direct DOCX/PDF files,265 ZIP containers and17 DOCX/PDF ZIP members examined; zero exact matches. No v0.4 or synthetic substitution. | Original corrected Letter DOCX/PDF and A4 DOCX/PDF, customer ZIP selection, buyer instructions/license, genuine Word/Docs edit/export and independent visual/rights decisions. |
| Operational/commercial acceptance | Installed-site OWNER enrollment, live provider acceptance and commercial certification are separate from isolated software tests. No production or provider operation was attempted. | Existing locked local journeys/REST/browser and full exact-head audit; synthetic classifications retained. | Separately authorized installed-site/provider acceptance and explicit release authorization. Postlaunch metrics remain postlaunch evidence, not waived criteria. |

## Wedding Guide source boundary

Expected originals are pinned by the existing `bin/verify-wedding-v05-admission.py` and `wedding-v05-illustrated-admission-20261024.json`:

| Artifact | Expected SHA-256 |
| --- | --- |
| Letter DOCX | 0a9fabc469d217827ef50f645324c5bbda34f897d969092d21582034eaf10168 |
| Letter PDF | ef8b759106936b6e63d0874d61cbc379c7dd36c35f0806f67adf38bbc6052f7f |
| A4 DOCX | 41170901605d7148ecb61c8ae32e3c6ab1ba9f1095a16dfb3a60831fe274a56c |
| A4 PDF | 1420880bba0eeda13334269f9e6634dd54aa13fc312b4cedfe3e2d5ed504ef3e |

The inventory exported no source content and created no authoritative product identity. Historical Product25 and all500 catalog source identities remain untouched. An actual integrated v0.5 customer ZIP cannot honestly be called verified in this workspace. The product-agnostic delivery software is exercised with clearly synthetic files and existing isolated source-to-edit/admission/approval/listing services. No real Word or Google Docs compatibility is inferred.

## Verification and failure handling

- Digital lane:136 WordPress tests/3,147 assertions; full unit1,993/10,634(skip1), configured analysis and default credential-history gate PASS.
- PET lane:15 selected WordPress tests/296 assertions,47 PET units/221 assertions; full unit1,993/10,634(skip1). Two exact DB-name concurrency safety guards failed only in its intentionally different lane database. They were not weakened or run against another lane's database; root's integrated original-disposable-DB selection includes them and passes.
- Finance lane:260 WordPress tests/4,773 assertions; full unit1,996/10,647(skip1), configured checks and default credential gate PASS. Existing unrelated test-double dynamic-property deprecations remain visible.
- Owner integrated selection:55 tests/1,465 assertions PASS, including edited Digital admission/approval/local listing, scoped external document integrity, all FiveEngine behavior, package identity and dual product/marketing separation. The first reused-database attempt hit two existing synthetic listing quotas; only the disposable test DB was reset, not the quota or assertions.
- The initial full unit run found a legacy source-spacing assertion; actual basename/addFile variable calls were preserved with their original spelling. Tests were not weakened. Full unit1,996/10,647(skip1), legacy/configured PHPStan/coding standards PASS afterward.
- Supplemental changed-file PHPStan initially exhausted128MiB and lacked the repository's PHPDoc-certainty setting. It now includes the existing repository configuration with a512MiB development-analyzer allowance; level5 coverage passes without ignored errors, baseline suppression or changed production budgets.
- An execution-channel interruption temporarily made the owner workspace unavailable. All three lane results were already pushed. Source and uncommitted package work were confirmed intact after recovery; no remote source workaround or false certification was used.

The final integrated full WordPress/catalog/offline/browser/scheduler/security/dependency/package results belong to the terminal exact-head audit receipt. This document does not claim a pending check passed. No redundant lane hosted audits were triggered.

## Minimal owner-action queue and decisions

1. Make the four exact v0.5 artifacts and intended customer-delivery ZIP available through an authorized artifact location. Reuse the pinned verifier; preserve the originals and historical Product25. If bytes differ, admit a distinct governed version rather than relabeling hashes.
2. Decide exact-file content/design, buyer instructions/license and commercial rights, then perform genuine buyer editing/export in Word/Google Docs with recorded app versions. No fresh approval of already established technical policies is requested.
3. Supply the genuine licensed PET photo/matte/reference/result and independent reviewers; resolve provider-specific geometry and host resource evidence before print/commercial approval. No paid AI or new detector is needed for the approved supplied-matte recipe.
4. Provide the remaining genuine Finance/source/accounting evidence only when available; zero sales is not zero expenses or certified profit. Separately authorize any installed-site/provider acceptance or release operation.

Engineering: bounded integrated software candidate, subject to terminal exact-head verification; not universal Blueprint certification. Authentic Wedding Guide pilot acceptance remains BLOCKED on source bytes and review. Operational/commercial release remains NO-GO. All45 outstanding criteria,500 identities,20 review waves and140 artistic blockers retain existing statuses. STOP ALL ON; external lock ON; automation OFF; RELEASE HOLD. No production change, privileged OWNER enrollment, live financial import, provider mutation, order, publication, merge to main, deployment or activation.
