# Post-5061 convergence: interface disposition

Baseline: Lane A PR #1267 `36dc9638be6fdeaa33ccee623a82a8a350fcdb2e`, Audit #5061 SUCCESS. This receipt does not promote a capability, certify a provider, approve artwork, or authorize execution. STOP ALL ON; external lock ON; automation OFF; manual approval; RELEASE HOLD.

## Canonical review: producer delivered, consumer evidence bounded

Lane A owns `CustomerPhotoSelection`; Lane B owns held-plan consumers. At Lane B head `4df98bf0a749f47c71346ed44172308acae1bce4`, the consumed producer is Git blob `875b946f356313326f4b6913485bfb2c3b720168`, SHA-256 `9644718d3a9539d08d2a5e63b4cd057e00ad903da0242776e44af28f6982f447`, identical to Lane A's source. `PersonalizationCurrentness::canonicalReviewMatches` compares canonical review status, actor and timestamp to the locked current canonical row. Missing/malformed/substituted values fail closed. These are currentness identities, not portable owner approval.

Issue #1266 comment 6944900864 reports actual local public-getter tests 2/62, managed photo→review→approved-plan→held tests 9/263, and causal substitution/ABA tests 2/46. Lane A independently inspected the published consumer/tests, including actual `HeldFulfillmentPreparation::prepare`, missing/substituted fields, and two-connection timestamp ABA denial. Lane A has not independently rerun Lane B's local tests. It subsequently downloaded the actual hosted successful retry artifact and parsed its raw JUnits as described below; no duplicate implementation or broad rerun was needed.

Hosted native focused run `44061449960` job `133964091339` failed at `ManagedReviewerHeldCompositionTest::testActualManagedProcessKilledAfterCommitKeepsOnePlanAndRejectsReplay`, `ManagedCrashCases.php:65`, null array access (51 tests, 813 assertions, 1 error), before the changed consumer profile. This does not establish a consumer failure or hosted consumer PASS. Diagnosis belongs to Lane B.

Subsequent actual hosted attempt 2 is SUCCESS (job `133984555799`). Lane A independently downloaded artifact `14321698777` (14,873 bytes), matched GitHub digest `f844eb733f4054e711058bb8fa2cf81b05055a0296e51e77308e4e60833552f6`, checked ZIP integrity and parsed raw JUnits: managed producer 10/269, existing consumers 99/1,156, retained authority 51/843 and projection 4/41, all zero failures/errors/skips. Embedded `source.json` pins exact `4df98bf0a749f47c71346ed44172308acae1bce4`, OFFLINE_TEST, production_qualified false. Together with the published actual consumer test source, this confirms independent B execution of all three canonical-review identity fields through real held-plan consumers. Original attempt 1 failure is preserved; retry success is not a repair of the reported ready-file race. Full B audit/installed/native qualification and S2-ART-14/S2-ORD-06 acceptance remain separate and unpromoted.

## Shared interfaces: exact blockers and minimal dependencies

Method names explicitly marked **proposed** below are requirements, not existing callable APIs. General shared-core ownership is **UNASSIGNED_PENDING_COORDINATION**, not implicitly Lane B's. Comment 6909067224 states no active B edits to the named AI files; it does not transfer exclusive general-core ownership. Requests 6938808470 (release/monetary) and 6938993952 (acquisition/client) still require an explicit single-writer disposition. Lane A's existing additive image metering and research model-adapter ownership is narrower.

### I-RESEARCH-CAPTURE — PARTIAL / shared-client boundary

- Producer/editor: shared AI HTTP client, unassigned pending explicit coordination. Proposed writer Lane A after clearance. Consumer: Lane A's existing durable research queue and source repositories.
- Existing methods: `OpenAIClient::startBackgroundDevelop` fixes web search off; `retrieveBackground` strips original output/citation capture. Existing generation contracts can describe bounded web search, but that does not make the old development queue a source-acquisition route.
- Required additive methods (**proposed**): `startBackgroundResearch` and `retrieveBackgroundResearch`, using the existing reviewed sender, endpoint, model/quote and STOP admission. Never silently change old development jobs.
- Evidence contract: versioned, consent-bound shop/actor/task/run/model/request/quote identities; bounded retained source capture and original provider-body SHA; acquisition time separate from reported publication time; URLs, citations and excerpts linked to retained bytes. Citation-only output remains UNVERIFIED and cannot become observed sales/demand. Preserve existing quantity/freshness/byte limits; any new capture limit needs an explicit contract, not an implicit expansion.
- Minimal dependency: shared capture hook and versioned source-consent/capture policy, then additive queue acquisition intent. No dependency on Printify, supplier credentials, native allocations or unrelated authority enrollment. Network execution remains disabled.

### I-NO-SEND-RELEASE — BLOCKED / absent authoritative fence and release primitive

- Producer/editor: shared budget and AI dispatch-fencing core, unassigned pending explicit coordination. Lane A owns the local execution-history projection delivered in this batch.
- Existing interfaces: `MonetaryReservationRepository::reservation` / `exposure`, `ProviderEvidenceRepository::snapshot`, `ShopAiGovernanceRepository::reserveGeneration`. No approved unused-release operation or trusted all-root no-send qualifier exists in these consumers.
- Required interfaces (**proposed**): `holdForAttempt(shop, attemptKey)` for unlinked acknowledgement-unknown holds; trusted AI fence resolution; `reviewNoSend`; `releaseUnused(shop, expectedHoldDigest, trustedNoSendRef, idempotencyKey)`. These are not separate Printify API interfaces.
- Evidence contract: exact hold/attempt/usage (nullable only for unknown acknowledgement), shop/run/stage/model/request/quote/currency; irrevocable local cancellation plus independently trusted all-root dispatch/credential-generation fence; no queued or in-flight capable attempt; issuer/source-pin/revocation/proof references. Local journal history, absence of a dispatch record, cancellation, timeout and operator assertion are insufficient.
- Minimal dependency: approved trusted-fence contract and append-only release CAS under the SAME shop accounting lock as reservation. Preserve original cost/invoice records, unknown charges, prior-month pending exposure and duplicate safety. Do not manufacture a zero invoice, confirmed refund, quota refund or provider finality.

### I-LEGACY-EXPOSURE-QUALIFICATION — BLOCKED / shared monetary disposition

- Producer/editor: shared monetary/settlement core, unassigned pending explicit coordination. Lane A's read-only disclosure wrappers are already implemented.
- Observed boundary: legacy `REVIEWED_PROVIDER_CHARGE` exposure and old settlement/projection matching do not independently establish charge finality. A wrapper disclosing UNVERIFIED does not repair core exposure qualification.
- Required interface (**proposed**): `qualifyLegacySettlement(shop, usageId, expectedSettlementDigest, trustedFinalityRef, confirmed)` and a separate CAS projection reconciliation operation if needed. Add a qualified accounting disposition, consumed under the existing lock; do not rewrite old receipts to add asserted finality.
- Evidence contract: preserved original invoice bytes/hash, charge/metering/model/request/response attribution, and append-only independently qualified finality sidecar. New JSON flags alone are assertions, not authenticated provider evidence. Unknown or unqualified exposure must retain at least max(reserved ceiling, recorded actual), including historical carry; overrun stays blocked.
- Minimal dependency: shared disposition/qualifier implementation and approved evidence authority. No supplier/native credential dependency; no inferred reversal or refund; no Digital Shop expansion.

### I-LOCAL-EXECUTION-HISTORY — IMPLEMENTED, focused acceptance in this batch

- Owner: Lane A. APIs: `ImageGenerationJournal::executionEvidence(string $id, string $expectedEvent)` and `ResearchExecutionJournal::executionEvidence(int $id, string $expectedEvent)`, each returning an array or `WP_Error`. Existing cost evidence includes `execution_history`.
- Version: `shop2-task-execution-evidence-v1`. Reuses existing verified journals and storage; exact-head comparison, repeated current read and refreshed actor/shop authorization. Bounded redacted run/model/request/event metadata; no prompt/source bytes/credentials. Public research run-context extras are rejected rather than copied into metadata.
- Evidence is a local verified snapshot, not a signature, portable approval or provider witness. Recorded usage/index pairs describe historic stage values, not new usage bindings. `execution_fenced`, `cross_root_no_send_verified`, and `reservation_release_authorized` are always false. Charge finality is `NOT_ESTABLISHED_BY_EXECUTION_HISTORY` even after cancellation.
- Consumer dependency: future release/reconciliation code may inspect this snapshot but must separately obtain the trusted fence/finality contracts above. No release can be authorized from this result alone.

## Acceptance and next actions

Focused real WordPress evidence: 68 tests / 3,580 assertions PASS, including stale snapshots, concurrent cancellation, revocation, corrupt history, foreign scope, preserved unknown charge and unauthorized metadata disclosure. The intentional `missing_research_dispatch_ack` SQL fault is the named recovery test, not a new production defect. Initial REDs and independent review finding/fix are retained. HTTP and exact-head CI are recorded separately after the source batch is finalized.

No complete 151-ledger capability is promoted here; last coordinated accepted count remains 32/151. Source ownership and evidence-authority decisions, rather than unrelated native work, block the three shared interfaces. Lane A can complete additive consumers after those contracts are assigned; estimate 2–4 engineering days after shared producer contracts are available, subject to independent HTTP/WordPress acceptance and exact-head CI. This is not a launch estimate.
