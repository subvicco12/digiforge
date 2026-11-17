# Stage 2 acceptance recovery — offline only

Starting PR1263 source:eec808f1f9b48ea3207a77012208a0cfba1b07a4. Original .17 archive and both connector.1 test-only ZIPs, manifests, control logs and Drive objects are preserved. No production/staging installation, live provider call, credential access, merge, provider write or operation replay is authorized.

## Per-failure root causes

The initial unchanged control (431 tests) and candidate (435 tests) had exactly the same six errors and fourteen failures. None was a newly observed connector regression. Full method names are retained in STAGE2_PACKAGE_REGRESSION_COMPARISON.json.

| Original group | Count | Classification | Justified recovery |
|---|---:|---|---|
| FinalConvergenceScenario positive chain | 1 error | Stale fixture | Fake template/output hashes were not retained, certified artifacts. Supply approved personalization schema, actual deterministic recipe/output bytes, retained artifacts and verified synthetic geometry; positive APPROVED_PACKAGE assertions retained. |
| FocusedEtsyOrderReadinessAvailability | 1 error | Incomplete DB double | Supply environment and shop_reference actually selected by the current readiness query; preserve failure injection at every original count position. |
| OpportunityDesignWorkflow panel round-trip/return | 2 errors | Inherited package assembly omission | Existing qualified source panel class was omitted by .17 assembly. Add the exact hash-pinned class only; it is not registered by the existing bootstrap, and adds no active hook, route or scheduler. |
| PortalEvidenceAvailability cost tests | 2 errors | Incomplete DB doubles | Add the optional cohort currency get_var operation required by the current model; do not suppress evidence errors or change expected totals/breakdown states. |
| ApprovalAttentionNavigation | 1 failure | Stale authority/render fixture | Native Global Owner plus actual portal shell and exact shop required; unsigned WordPress admin alone is not owner authority. |
| ConnectionTester Printify positive | 1 failure | Stale connection/lifecycle fixture | Disconnected/disabled now explicitly denies without HTTP and remains disabled. Separately configured/enabled synthetic binding is independently reviewed; owner session refreshed; mocked stored-token read succeeds and leaves enabled=true unchanged. Original final false assertion encoded an obsolete promotion-from-disabled path; the recovered two-phase assertions are stronger, not a runtime policy relaxation. Wrong-scope also denies without HTTP. |
| MasterCatalogV2IngestionReadModel | 4 failures | Incomplete persisted evidence | Header-only parents had no500children and invented fingerprints; seed normalized complete parent/V2 records and real source lineage. Existing negative overrides still reach their intended conflict checks. |
| MasterCatalogV2Persistence | 1 failure | Fabricated fingerprint/lineage fixture | Use canonical normalized rows, complete migration metadata and actual ingestion contracts; retain replay and conflicting-parent checks. Confirm insertion through persisted rows rather than rows_affected after COMMIT. |
| PrintifyPreflight | 4 failures | Missing geometry provenance | Seed persisted synthetic variants, exact source/placeholder bindings, explicit region and local template policy. Original readiness drift/variant mismatch/dry-run/permit checks retained. Add unbound-geometry rejection. |
| ResearchRepository/ResearchRest | 3 failures | Missing explicit shop/owner fixture | Bind source/candidate/body to personalized_pod and provide governed native owner where required. Replay, missing key, failure release and denial assertions remain. |

No production validation, authorization, geometry, artifact certification, monetary, provider, migration or emergency-control code was relaxed. Existing inherited test files are not overwritten: reviewed copies live in tests/package-acceptance/wordpress and are materialized over the complete source-pinned .17 suite by bin/prepare-printify-connector-package-tests.py. Every original test file and every original test method is retained; manifest verifies the mapping. Additional negative tests are included. Do not call an uncorrected baseline test green.

Two original conditional skips target the deliberately absent-main-pipeline branch when the shared pipeline is installed: PrintifyResearchTest::testAbsentGovernedPipelineDeniesBeforeTransport and PrintifyDependencyBoundaryTest::testUnintegratedDependencyStopsBeforeHttpOrGrantMutation. They remain explicitly reported, not introduced or counted as passes. The main-derived source suite exercises the absent-pipeline branch separately; shared pipeline denial tests continue in the installed package.

## Assembly and exact-source boundary

New test-only core version1.0.103.17-connector.2 preserves739baseline files identically and changes only original version metadata plus the previously reviewed additive client method. It adds the existing OpportunityDesignPanel with SHA256 d5e537a0adbe35e634fe73f918ff69084b66319e8b82425eacc4d6aa40dd3611, identical in qualified e957ba source and current source. No new functional panel implementation is authored. All other connector files remain unchanged. Companion0.1.0 ZIP is unchanged. Existing core/companion safety interfaces, autoloading, sole vault, sole integration registry, mappings and dispatch owner remain intact. Upload ownership remains absent/denied.

The builder verifies named committed blobs and hash-pinned baseline/method/additional class; no mutable working-tree source is labeled certified. Complete member and source manifests, reproducibility and readback must accompany the final candidate. The .2 package is TEST_ONLY; passing offline tests does not authorize installation or GET.

## Production source verification limitation

Retry of existing authorized Hostinger hosting_files_website-content on2026-11-12 returned HTTP422/[Hosting:9999] "This file seems to hold sensitive credentials and cannot be previewed" for BOTH files. No alternate path, partial-line read, download, encoding or execution workaround was attempted.

| File | Expected qualified-baseline SHA256 | Current production hash |
|---|---|---|
| includes/Core/Settings.php | 68e35511444d511c50fd7925e89a6d82cb9133df6e3c4caf3de5bccf70320862 | UNVERIFIED |
| includes/POD/ScopedProviderConnection.php | e48d64e7d1bf2f08c50cb0228ab76b082ae3d7c7dc085ec58fb5fac8d494b5cf | UNVERIFIED |

Nine previously verified non-secret source reads match the baseline, including both modified preimages. Plugin version metadata independently confirms1.0.103.17 active. Full-tree production equivalence is not claimed. Minimum next evidence is owner-supplied non-secret hashes through an approved host hash capability; no source/key/config export is necessary. Correlation IDs and expected hashes are recorded in STAGE2_PRODUCTION_SOURCE_EVIDENCE.json.

## Reproduction and rollback

At final clean SHA, build with bin/package-printify-connector-stage2.py using the original hash-pinned .17 archive into a NEW private directory. Prepare complete tests using bin/prepare-printify-connector-package-tests.py --output a NEW private fixture directory; requires the pinned e957ba git object. Mount tests/tools separately from extracted candidate bytes; use a NEW EMPTY isolated DB, HTTP/mail denial filters and network-disabled container. No production SQL is imported. Run all extracted-package tests, not just the repaired subset. Retain original and recovered JUnit/summary/fixture manifests.

Future rollback remains restore original core files from independently verified pre-install checkpoint and remove companion plus every added connector/panel file, under separate installation/rollback authorization. No schema migration or automatic record deletion. No recovery qualification or actual restoration is claimed. Preserve source/code/package hashes; do not overwrite the previous test-only packages.

Final exact-head local/hosted results, archive identities and readiness disposition are recorded in the PR handoff. Hosted source CI is distinct from local extracted-package acceptance and is not hosted private exact-ZIP certification.
