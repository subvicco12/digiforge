# Shop 2 image/research financial convergence and public review identity

Parent905352c8630411d4aa9f4207a710fd2061a4571d/Audit5058 SUCCESS is preserved. This batch changes A-owned services/panels/tests only. Shared MonetaryReservationRepository, ProviderEvidenceRepository, OpenAIClient, B/native/order/held/API/companion and commercial artwork bytes are untouched.

## Implemented normal workflows

`ImageGenerationExecution::costEvidence(string $shop,string $id)` resolves the authenticated creator's immutable job, usage, exact request/model/attempt/run reservation and image metering. `reconcile(string $shop,string $id,string $event,string $document,bool $confirmed)` takes the same image execution lock, rejects stale events, simulator billing and missing metering, retains original bytes through the existing settlement service, verifies lock ownership/release and rechecks current authority before returning. It never dispatches, retries, adopts artwork, approves a source or infers a refund. Cancelled/UNKNOWN jobs can still record a bill; their generation state stays unchanged. Stale artwork remains unavailable for adoption.

The existing commercial-research action/nonce now offers a bounded creator-only image charge queue BEFORE design lookup. It remains usable when a stored design is unavailable. Only `image_charge_reconcile` uses financial-only resolution; all artwork operations retain their current source checks. Current signed POD, research and AI authority are required. Discovery validates immutable job chains and final authorization; rendered billing HTML is buffered until final full admission. Both the global and embedded statement controls deny late research withdrawal after the service releases its lock. No separate POST handler, proof storage or runtime provider is introduced.

Research reconciliation reuses the same final-source parser and now rechecks authorization after its final owned lock release. A durable charge may already exist when the response is denied; it is not erased or automatically retried.

## Versioned final-charge source admission

Current entrypoint policy: `shop2-final-charge-review-v1`. Portal forms bind that policy version and require explicit human final-charge confirmation. It is an admission policy, **not a retroactive policy stamp on old shared-ledger settlements**.

In addition to existing provider/invoice/exact-metered-line fields, the retained original document must assert:

```
"finality": {"status": "FINAL", "additional_charges_possible": false}
```

Only compatible original documents are accepted. Do not rewrite a provider original to add these assertions. This syntax checks source claims; it cannot authenticate a provider, prove real billing finality, or establish that an actual provider exposes this format. Authentic source review and provider-format qualification remain external gates. Offline fixtures explicitly label their documents SYNTHETIC_TEST_ONLY.

Missing/provisional/unknown finality, malformed/oversize documents, duplicate/conflicting lines, wrong response/model/currency and simulated usage cannot reduce exposure through these entrypoints. A matching reviewed final zero charge is a zero-charge observation, never a refund. Over-ceiling evidence remains retained and blocks further admission. Original documents/hashes and actual charges use the unchanged immutable core; no token-based price inference exists.

`charge_finality` is UNKNOWN, NOT_PROVIDER_USAGE, UNVERIFIED_LEGACY_STATEMENT or SOURCE_ASSERTS_FINAL. It distinguishes retained source assertions from provider qualification. Existing charge amounts remain observable as RECORDED charges; they are not relabeled final merely because the new UI reads them.

**Shared limitation:** the old protected monetary core can already count a historical settlement's amount rather than its maximum, even when its old document has no finality assertion. Local wrappers cannot repair or certify that historical exposure. No private options/amounts/history are rewritten. Universal safe unused/no-send release, legacy exposure repair and actual provider-finality authority remain BLOCKED on the shared contract/ownership request6938808470. Default network execution remains disabled. Do not interpret this batch as certification of global monetary release.

## Canonical review receipt for Lane B

`CustomerPhotoSelection::forOrder` adds exactly these source identity fields under existing approved/current `review_provenance`:

- canonical_review_status: exact verified canonical database status;
- canonical_reviewed_by: exact verified canonical reviewer integer;
- canonical_reviewed_at: exact verified canonical database timestamp.

They bind the same submission, selection SHA/revision and selected immutable review SHA. `reviewer_user_id` remains the actual selected independent reviewer, which may differ from canonical_reviewed_by after identical payload reuse. Existing currentness, read lease, writer fence, audit, scope and reviewer revocation admission stays unchanged. These fields are neither permission nor native epoch/allocation grants. B request6940801185 is fulfilled at the public producer; B must independently consume/test its locked canonical row comparison.

## Verification and unchanged blockers

New causal tests first demonstrated missing image reconciliation, provisional research admission, legacy finality mislabeling, late research authority withdrawal, stale-source financial UI and research-only discovery disclosure. Corrections preserve written financial truth and fail closed at response emission. Final affected WordPress suite141tests/6978assertions PASS; unit2226/11989 with1SAM skip; PHPStan and all changed PHP/JS syntax PASS. The new foreign-creator fixture initially retained the transaction deliberately restored by ShopAccessFixture::authorizeActor; the test now explicitly commits only its synthetic setup, following the existing review fixture pattern. Production caller-transaction denial remains unchanged. Original invalid fixture iteration (a second conflicting image task policy) and local tooling faults remain in evidence, excluded from PASS claims. The existing interrupted-research test deliberately emits missing_research_dispatch_ack SQL as fault injection; successful final suite is not a production DB error claim.

Actual image HTTP:43checks/10POSTs PASS, including original upload, provisional/stale/old-policy/malformed/oversize rejection, stale artwork cleanup, exact replay, conflicting zero and causal final-lock research withdrawal. Actual research HTTP:17checks/4POSTs PASS. Both use real WordPress cookies, core admin-post, nonces, uploads, redirects and persistence; measured outgoing WPHTTP0. All billing/image data and decisions remain synthetic software evidence. No existing five-engine campaign is regenerated.

Automatic source acquisition remains BLOCKED: approved background client fixes web_search=false and omits retained citation/tool capture; explicit source-consent/capture interface ownership request6938993952 remains unanswered. The recorded-source queue, freshness/deduplication, bounded quantities, quotes and recovery are preserved. Do not manufacture observed evidence or create an alternative sender/orchestrator to bypass the shared client.

No full151 capability promotions. Candidate evidence extends AI08/12 and ART14/ORD06 producer intake; complete independent cross-lane/provider criteria remain separate. Provisional remaining A engineering2–4working days AFTER shared interface disposition, excluding external/live qualification.

STOP ALL ON; external lock ON; automation OFF; manual personalized-order approval; RELEASE HOLD. No production, paid/live provider, commercial research/artwork generation, merge, deployment or publication.
