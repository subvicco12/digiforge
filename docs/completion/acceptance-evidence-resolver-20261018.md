# Reviewed acceptance evidence resolution — read-only engineering

## Verified baseline and ownership

Draft #1203 head `b93036bf03faa9bfafab85a256abacd7fcd362a6`, audit #4735 PASS, remains the dependency. Main fetched as `844107ab9baf7e1303cd47708e9eab3bc700b348`; the isolated branch preserves the complete #1194–#1203 ancestry. Existing original-source Blueprint/Master 500 inventory and the authoritative 268-criterion ledger are reused. No AGENTS.md is present on the candidate; the available operating-manual/execution-ledger snapshots are proposed operating instructions, not asserted merged-main policy. The explicit current owner scope governs this batch. No other active agent was reported; all #1203-owned files are left unchanged.

Changes are confined to new `includes/Portal/AcceptanceEvidenceResolver.php`, its new native WordPress regression suite and this completion record. No migrations, shared CI, portal/controller edits, execution-ledger changes or Finance changes.

## Inventory before implementation

| Existing store / API | Actual identity and integrity fields | Scope/review guarantees | Resolver disposition |
| --- | --- | --- | --- |
| `Security/ReviewedLocalEvidence.php`; options `digiforge_local_review_{stream}`, matching append-only audit rows | Stream binds kind/shop/key; state has version, revision, proposal_hash, effective_at/expires_at, author_id/reviewer_id. HMAC-signed contiguous history must match audit events. Proposal hash canonically binds kind/shop/key/version/author/payload/window; review revision pins the decision. | Existing `approved()` checks current distinct author/reviewer shop assignments and effective/expiry/revocation state. | Supported only for exact typed `ReviewedScopedPolicy` records; not arbitrary generic-policy payloads. |
| `Portal/ReviewedScopedPolicy.php` | Canonical `environment:scope:workflow:capability` key and exact typed payload; sandbox/production are existing evidence environments | Shop is in the signed stream; workflow/SHOP context in payload; independent existing review, no activation grant | Explicit reference codec below. |
| `POD/RenderArtifactStore.php`, `RenderArtifactVerifier.php` | Protected content-addressed storage reference, byte/pixel SHA-256, metadata; render receipt binds output/preview and template/recipe | Legacy artifact paths can lack shop segments; bare stored-byte identity does not itself provide environment and independent review receipt scope | Unsupported, no arbitrary file reads or hash-only certification. Requires explicit record/receipt adapter with full scope before future integration. |
| `POD/RenderEvidenceRepository.php` | SQL render ID/evidence_hash, order/mapping/template version and fingerprints, personalization/output hashes, reviewed_by/reviewed_at | Requires exact order/mapping/personalization/artifact joins; not a generic local URI registry or standalone environment-bound independent review contract | Unsupported, no inferred order/variant/provider mappings. |
| `POD/CutoutVisualReviewRepository.php` | Shop/case/order, revision/parent, source/cutout/reference hashes, reviewed decisions and retained image evidence | Scoped rights and independent cutout review infrastructure; no existing acceptance-reference/environment codec | Unsupported; no image/rights/consent inference. |
| `AI/ProviderEvidenceRepository.php` | Immutable usage/shop/response identity, normalized evidence fingerprints and reviewed source records | Domain-specific metering/charge/attribution lineage, not general acceptance certification | Unsupported; no monetary evidence consumption or Finance integration. |
| `Operations/RecoveryVerificationEvidence.php` | Operation key, evidence_hash, target_environment and dispatch-ledger record | Existing recovery-specific plan/verification semantics, including staging target requirements | Unsupported; no installed-site/staging evidence manufactured. |
| `Portal/ReviewConfirmation.php` | Action-specific selected record and review acknowledgement | Confirmation UI, not an independent immutable general evidence store | Not treated as a certificate or source-record registry. |

No previously opaque `local://` declaration is silently mapped to any store. There is no global hash search, title matching, guessed foreign key, caller-supplied path, external request or alias backfill.

## Explicit supported interface

`AcceptanceEvidenceResolver::policyReference(shop, policyKey)` reads an actual currently approved typed scoped policy through existing signed review infrastructure and returns the existing acceptance-reference envelope (`shop_key`, `reference`, `sha256`). It emits exactly:

`local://reviewed-policy/v1/{canonicalPolicyKey}/{recordVersion}/{reviewRevision}`

This is a newly explicit codec for existing record identities, not a claim that prior opaque references have this meaning. `sha256` is the existing canonical proposal_hash, **not a file hash or supplier certification**. Version/revision are exact positive decimal identities; aliases, leading-zero variants, malformed keys and other reference types do not resolve. No review or record is created by constructing a reference; the caller must put it in a separately reviewed acceptance-contract version using the existing lifecycle.

`AcceptanceEvidenceResolver::resolve(shop, criterion, scope, environment)`:

1. Requires an authenticated explicit `manage_digiforge` assignment to Digital or Personalized POD. Deferred shops and foreign readers are denied before record reads.
2. Requires an explicit existing environment (`sandbox` or `production`); this is evidence context, not a runtime override or production permission.
3. Reads the current independently reviewed typed acceptance contract through its existing API; missing, invalid, future, expired or revoked parent review yields UNKNOWN with no references.
4. Traverses only the contract's validated declared references. Repeated identical references are counted once with occurrence count; conflicting hashes for one reference invalidate it.
5. Resolves only the codec above, through the signed stream in the exact selected shop. Requires exact policy key, typed payload, environment, version, review revision and canonical hash; WORKFLOW policy scope must equal the parent contract workflow. A SHOP policy is explicitly shop-wide and has no workflow claim.
6. Rechecks source policy snapshots and the parent review before returning. Changed evidence is not returned as matching. Database/history/authority failures remain unresolved with no raw diagnostics.

`MATCHED_LOCAL_POLICY` / aggregate `MATCHED_LOCAL_RECORDS` mean exact local record identity and current independent review only. `record_type=REVIEWED_SCOPED_POLICY_V1` and `purpose_verified=false` make the limit explicit. A policy is not an artifact, seam fixture, spelling approval, AI-benefit measurement, rights document or metric source merely because someone references it. Semantic suitability, measurements and certification remain unverified. All results retain **value UNKNOWN, evidence UNVERIFIED**, with supplier/publishing/AI/external execution flags false. No scores, formulas, tolerances, spelling exceptions or authority are introduced.

This is a bounded point-in-time read, not an authorization lease or atomic multi-record production transaction. Downstream execution gates remain independent and must not consume matching metadata as a permit. Existing signed-history/current-authority checks are reused; no new approval, credential or persistence system exists. Installation authenticity beyond the local signed history is not asserted.

## Non-overlapping integration handoff to Codex A

The service is callable and tested but deliberately not registered in #1203's REST/controller, status projection, JavaScript or panel. Those files remain Codex A-owned. A future coordinated GET-only consumer can supply the exact selected shop, criterion/scope and explicit evidence environment, call `resolve`, and render its bounded typed result as text. Existing shop authorization, cookie nonce handling, response-generation invalidation and certification warnings must remain intact. Do not replace #1203's UNVERIFIED flags with a resolver match. Unsupported artifact/provider/recovery types require their own verified identity/version/environment/review contract before an adapter is implemented.

## Validation and acceptance impact

New tests first failed with the resolver absent, then passed against actual synthetic signed policy/acceptance streams. Focused native WordPress selection (`AcceptanceEvidenceResolverTest|AcceptanceContractStatusTest|ReviewedAcceptanceContractTest|ReviewedContracts`): **53 tests / 401 assertions PASS**. Coverage includes matching/version/revision/hash, cross-shop/deferred/anonymous denial, unknown and missing records, environment/workflow mismatch, unsupported/opaque references, expired/revoked parent/source reviews, reviewer authority loss, stale version, duplicate/conflicting references, generic signed-record type confusion, deleted history, database failure/redaction and read immutability. The isolated bootstrap blocks actual external HTTP. Related unit selection: **107 / 678 PASS**; legacy suites, standard analysis/standards and additional WordPress-aware level-5 analysis PASS. `git diff --check` PASS. Final exact-head hosted evidence is recorded in the draft PR without changing the audited commit.

The 268-criterion ledger is unchanged: **246 implemented candidates / 22 UNVERIFIED**. This batch closes a bounded local record-resolution software boundary, not any authentic supplier/owner/installed-site criterion. No numeric acceptance percentage or actual review certification is inferred from synthetic tests.

Smallest next steps: Codex A may wire the documented read-only service without overlapping this batch; owners may provide actual reviewed criterion/scope source packets. Render/supplier/rights/shipping evidence remains UNKNOWN until exact scoped records and applicable independent reviews exist. No production activation is needed or authorized.

STOP ALL ON; external safety lock ON; automation OFF; RELEASE HOLD preserved. No live providers, real credentials, production/staging writes, Finance changes, orders, publishing, activation, merges or deployments. Production impact: NONE.
