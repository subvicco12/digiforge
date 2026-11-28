# External Digital document admission — Phase 2

## Scope and defect

Baseline: draft #1237, `224cc36d2a6060141cda1a58d8e746d081820400`. Candidate: draft #1238. This is an additive offline custody/technical-evidence path, not a commercial approval, catalog registration, installed-site acceptance or provider operation.

The frozen MVP requires external DOCX/PDF source custody. Baseline has no `ExternalDocumentAdmission` service. Its bounded local PDF generator cannot represent the Wedding Guide DOCX layout, and `PdfTextIntegrity::validateRevision` delegates non-local generators to their governed adapters. Therefore a new external generator needs its own fail-closed adapter; labeling arbitrary provenance `digiforge_external_document` must not bypass source completeness. The local `digiforge-local-text-pdf-v2` checks from #1237 remain unchanged.

## Admission contract

The callable service accepts explicit `digital` shop, current actor, exact product/version/PDF asset-spec ancestry, a non-secret operation key, local source/export paths and declared SHA-256 hashes. Current signed shop assignment and product-management authority are required. HTTP paths and symlink components deny. Nothing automatically creates a catalog product, approval, listing or external action.

Original source DOCX and PDF bytes are retained under protected storage using content-addressed names and an operation-bound immutable custody manifest. Replays must match the original binding and bytes. A changed operation replay, tampered retained file or conflicting manifest denies without overwriting history. Returned revision input remains pending independent review.

At PDF approval/release validation, the external adapter must revalidate current ancestry and shop authority, retained manifest and source/export identities, the current verifier result and exactly one passing `external_document_source_completeness` QA record for the immutable revision. Missing, borrowed, duplicate, stale or conflicting evidence denies. Technical QA is not Product Approval.

## Existing review-to-draft handoff

1. Use an existing governed **new candidate** product/version/PDF spec belonging unambiguously to the Digital shop. Do not borrow historical Product #25 or invent an identity for a parser trial.
2. Call `ExternalDocumentAdmission::admit` only with the exact scoped IDs and original local source/export hashes. On any WP_Error, stop; do not strip the failed check or rewrite source content.
3. Submit returned `revision_input` through existing `Production\Repository::addRevision`. The revision remains pending; attach exactly one revision QA check `external_document_source_completeness`, with the exact technical receipt plus `binding`, only after independently checking the retained source-bound result. Required specs must reach the existing reviewed `QA_PASSED` state.
4. Use existing plan/bundle `REVIEW_REQUIRED` and ProductReview contracts. The seven plan checks remain `semantic_specification_match`, `semantic_spelling_text_quality`, `semantic_ip_trademark_risk`, `semantic_prohibited_content`, `semantic_link_qr_integrity`, `semantic_marketing_product_consistency`, and `semantic_mockup_production_separation`, with their existing PASS/WAIVED review rules. No synthetic run supplies genuine human review or commercial approval.
5. Existing offline ListingFactory additionally requires version `notes.spec` (title, description, SEO keywords and USD price), unique candidate ownership and linked research evidence with an unambiguous environment. A custody test fixture intentionally does not manufacture this commercial/research evidence. Separate immutable offline draft planning remains downstream of genuine approval; publishing stays held.

The native `AutomatedQa` PDF profile and `SemanticQa` text extraction remain native-generator contracts; they are not claimed to support external compressed PDF exports. Do not forge native-generator provenance or pass a source-completeness receipt off as complete automated/semantic QA. The existing revision lifecycle API does not itself establish commercial completeness when moving to `QA_PASSED`; all actual necessary technical and independent review evidence must exist first.

The read-only integration review found these existing gates; it did not demonstrate a new portal/POD defect. Source custody, product approval, offline draft readiness and installed-site acceptance are distinct outcomes.

## Bounded completeness profile

`digiforge-external-docx-pdf-v1` compares ordered DOCX body text with Poppler PDF text, allowing Unicode whitespace collapse only. Case, punctuation and glyphs are literal. Page dimensions and text bounds must fit the source-declared page/body geometry. The supported plain-text footer/PAGE pattern is relationship-bound and exact; arbitrary footer text is not ignored.

The local isolated Python worker uses standard-library ZIP/XML processing and fixed `/usr/bin/pdftotext`; the PHP wrapper uses argument-array process invocation, bounded input/output/time and post-execution hash checks. Unsupported constructs, external relationships, entities, macros, unknown binaries and symlink components deny. Supported inputs are deliberately narrower than general DOCX/PDF.

**Not established:** PDF sanitization, visual/layout fidelity, complete OOXML conformance, inherited style visibility, application editability, accessibility, legal rights, linguistic approval or commercial fitness. Receipt scope is `SOURCE_TEXT_AND_DECLARED_BODY_BOUNDS_ONLY`; visual/rights/production flags remain false and acceptance remains UNVERIFIED. Human visual inspection and commercial review remain independent requirements.

## Wedding Guide v0.4 technical trial

The supplied review package is a new candidate, not recovered historical Product #25. Source ZIP SHA-256: `f6cbc69447aa508a758d8773204513a81c771b0ae2aa9cf9953353a0fa4cb13d`; proposed customer ZIP: `c1ccba966d340387d0a2da20985eeeadbfe136bb076168077e2e1f6a54de0836`. Originals were not changed or committed into the plugin.

On 2026-10-22, the actual local worker accepted all three pairs below for its narrow technical scope. Each PDF has nine pages. This is an actual parser trial, not database admission under invented product IDs.

| Pair | Original DOCX SHA-256 | Original PDF SHA-256 |
|---|---|---|
| Wedding_Guide_v0_4_Letter | `c23fdcb154d5eafc5f3edff95f89563e73119c276997c6eecf1c637ceba01b86` | `69d0e57ab1c51d62fc8994785d8e46afa95bfe4c4961002171090749281c5c8c` |
| Wedding_Guide_v0_4_A4 | `a8aa4306778e73ba08ecd21f77a9267de6223ebd984699b6f069c61b026a0b62` | `a637349b52578df94521523dc5580d3e61fb56f9792e8b5cc8540361ee623cbc` |
| Fictional_Completed_Guide_v0_4 | `0fb9807f56be119f15117c7fce5131c28e3bb0053eeddc64f633730db945ac77` | `56cc4ee9de7e1d655a310d26e2beb830c1dcdbad5418c296fccae5ad4ac4bdeb` |

The Letter/A4 normalized text hash matches: `ca66fd62bfb737cdad6083dfc2d436f8c4bd14280aa06e497178fb03e20f83d1`. The completed fictional sample differs as expected: `3c1fc496b643f6cd45a20478dd0f82196e11995dfa7ea775a5922931263f4133`.

## Validation and unresolved acceptance

Observed RED evidence: audit #4776, run `39810062175`, test-first head `dd585c6add52752e067b404413ef4d0b2fac3bab`, completed 1,357 WordPress cases with the expected six missing-service errors and one missing-capability failure (two existing skips). Audit #4777, run `39812405667`, head `f53458b9fe3b22f34aa98453bfffe78403316358`, reproduced both the external-generator provenance bypass (returned true) and cached release drift (created a local listing instead of WP_Error). These are actual PHP 8.3/WordPress observations, not static-only assertions.

Follow-on audit #4778/run `39813362651` passed unit1,743/9,181 and offline155, with23/24 focused WordPress cases passing; the remaining test attempted a conflicting unique-key mutation instead of changing lineage. The fixture was corrected to assert a successful mutation. Audit #4779/run `39814031358` passed those24 cases but exposed a non-instance PHPUnit callback in the new permission-race fixture; that fixture was corrected without changing application behavior. Audit #4780/run `39814777041`, head `7dd73fc66f04a7d83aa1dd2176db0141c6db4fb9`, then observed the genuine listing-only authority race:24/25 cases PASS, remaining case returned a listing array after the signed listing assignment was revoked while product authority remained valid. The final guard rechecks the same actor and exact listing authority after worker-backed validation, before quota/listing writes.

The correction validates exact manifest-bound external members before listing quota/reservation/creation; cached RELEASE_READY alone is insufficient. Valid external custody supports a scoped local REVIEW_REQUIRED listing only in an explicitly synthetic already-reviewed fixture. Added regressions cover final-read revocation/lineage/byte drift, MIME/provenance downgrade, distinct authorized reviewer, same-byte symlinks, source/export tamper and manifest-member removal. Independent source review found the initial bypass boundaries corrected; atomic/serializable database or filesystem snapshot guarantees are not claimed.

Verifier code SHA-256 at preparation: `0c9ef2c9cef31a49c41a5a06e6643f565d8a7fa1fc5f78729c3f6205af7101dd`. The PHP wrapper binds this worker hash in every current receipt. Installed-site Python/Poppler availability and web-server protection of stored files require separate acceptance.

Focused offline tests: 11/11 PASS, including ordered text/punctuation, source geometry, truncation, footer exactness, entity/UTF-16 bypass, unexpected binaries/thumbnail and parent symlinks. Independent read-only worker/wrapper review reproduced no blocking defect. PHP/WordPress execution and mandatory engineering/security/package audit are performed in the authorized PHP 8.3 CI environment; exact-head results belong in draft #1238 and issue #1209. A baseline audit is not reused for changed code.

No authoritative criterion is promoted by this technical path: 246/268 implemented non-Finance candidates, 22 UNVERIFIED remain. Original-source acceptance classifications and historical Product #25 records remain unchanged. No Finance behavior changes.

Minimum remaining owner evidence: governed new-candidate identity/version assignment; independent visual/content/commercial review with attributable rights and license decisions; actual Word/Google Docs buyer-editability evidence if those compatibility claims are offered. Supplier/POD evidence and installed-site acceptance remain separate. No screenshots or repeated policy approvals are requested.

**NO-GO. STOP ALL ON; external lock ON; automation OFF; RELEASE HOLD.** No production/staging/provider writes, orders, publishing, activation, merges or deployment.
