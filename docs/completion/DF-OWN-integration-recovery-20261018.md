# DF-OWN-001/002 integration: ambiguous dispatch recovery

Engineering-only follow-on to #1200 (`4be3b6b5a92efe3d372260886e388be4eb01d171`, audit #4732 PASS). Main remains `844107ab9baf7e1303cd47708e9eab3bc700b348`. Live #1194–#1200 heads and successful checks were verified on 2026-10-18; the full linear ancestry is preserved. Existing draft branches are unchanged.

## Authority and ownership

Reused the original-source Blueprint/Master 500 inventory, issue #959 execution policy and current DF-OWN contracts. Read the original Operating Manual DOCX and authoritative GitHub Markdown/AGENTS/ledger from unmerged #1190; that proposed operating model is not present on main or this candidate and its initial assignments are historical. The current explicit non-Finance integration task governs scope. No competing writer was observed in the open-PR snapshot. Changes avoid the catalog lane, Finance, shared CI, migrations and coordinator-owned ledger. This new handoff is the only documentation addition.

## Confirmed defect and smallest correction

Before correction, four synthetic cases (order/production × thrown exception/provider WP_Error containing synthetic protected material) returned sanitized errors without usable reconciliation identity. Generic error normalization cannot persist UNKNOWN evidence without its request fingerprint and scoped identity. Two further regressions confirmed the same loss when ownership was revoked inside the mocked failing sender.

`PrintifyControlledTransport` now catches sender exceptions at the transport boundary, discards provider error text and emits explicit UNKNOWN using only the already-authorized pinned request. A local attempt marker preserves that same recovery identity if the credential boundary later rejects the result after dispatch. Existing explicit network-attempted errors remain compatible. No pre-dispatch denial is converted into fabricated attempted evidence. Successful responses and known HTTP rejection semantics are unchanged.

Six regression cases verify existing result normalization and immutable UNKNOWN repository persistence, both mutation actions, redaction, in-flight revocation, and refusal to dispatch twice. The original request fingerprint, connection scope and consumed authorization remain intact. No new grant, approval, schema, dependency, retry or activation interface was introduced.

## Integration review and limits

Credential retrieval remains guarded before ciphertext access and before/after callbacks. Order/production interlocks still require exact independently reviewed environment/business/shop/provider/account/external-shop/integration scope, durable consumed permit, current package/mapping approval and unexpired authorization. Dispatch retains its atomic audit-backed receipt, deletion/replay and concurrency denial. Legacy catalog retries and reconciliation pages retain fresh scope checks. Shared credentials cannot confer cross-shop authority; Digital is independent; deferred shops remain denied. Legacy paths without reviewed scope/package provenance intentionally remain blocked.

A network attempt cannot be recalled after dispatch. UNKNOWN is recovery evidence, never proof that a provider accepted a mutation, and never automatic retry authority. Missing authentic identity, supplier geometry/shipping, rights, selected variant, production authority and installed-site evidence remain external blockers.

## Acceptance and validation

No acceptance promotion: 246/268 implemented candidates and 22 UNVERIFIED remain unchanged. Additional behavioral evidence supports `NF-operation-state` (original source anchors retained in the matrix), `BP-portal-9-reconciliation`, and the still-UNVERIFIED shop/provider criteria. The coordinator-owned matrix/ledger is unchanged.

Red evidence: 4/4 failures before sender-error correction; 2/6 failures before post-dispatch revocation recovery correction. Green evidence: focused WordPress 87 tests / 1,897 assertions PASS; affected unit 141 / 544 PASS; additional WordPress-aware level-5 static and acceptance-matrix reference validation PASS. Catalog profile and final exact-head hosted audit evidence are recorded in the draft PR handoff without changing the audited commit.

STOP ALL ON; external safety lock ON; automation OFF; release hold ACTIVE. All transports mocked with synthetic local fixtures. No real credentials, provider calls, Finance changes, production/staging writes, orders, activation, merges or deployment. Production impact: NONE.
