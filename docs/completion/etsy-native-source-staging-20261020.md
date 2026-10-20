# Batch 11 — offline Etsy native-source staging

**RELEASE HOLD / NO-GO.** Engineering and synthetic tests only. Baseline draft #1228 is `937ed06d25b23f68ff526994cab3c44d08e3c62e`, exact-head Engineering & Safety Audit #4763 PASS. Main fetched at `844107ab9baf7e1303cd47708e9eab3bc700b348`. Source branches are unchanged; this candidate retains the cumulative ancestry. No provider transport, credential retrieval, real financial import, period approval, schema change, production/staging write, merge or deployment occurs.

## Implemented boundary

`EtsyNativeObservationStage::preview` reparses one to 20 original documents through the eight already-pinned Etsy schemas. Each source is at most the parser's existing 64 KiB; combined original bytes are at most 256 KiB. Original strings/hashes, native integer/divisor/currency and nullable/absent fields, timestamps and correction objects remain intact in the ephemeral packet. No decimal conversion, category, ledger debit/credit, revenue, fee, refund, source completeness or settlement is inferred.

The declared and selected context must match exactly: shop, environment, business/store, external account/shop, integration and pinned source-contract digest. Only Digital and Personalized POD are supported. The browser selected-shop authorization is independent of these asserted numeric fields. **Account/integration/business/store values are not server-verified ownership.** A hash is integrity evidence, not ownership authority. No new ownership system or financial binding is introduced; actual import remains blocked until the existing binding architecture supports and verifies the complete source relationship.

The existing parser now additionally exports a deterministic identity-to-content-hash map. This is additive to its unchanged `source_identity_keys` and observation-key contract. The stager rejects duplicate canonical observations, including whitespace-only alternate encodings, and conflicting top-level or nested native identities. Identical corrections referenced by separate related views remain shared references, never extra money. A changed native identity cannot silently become an immutable revision; an authorized persistence/revision contract is still required.

Money and ShopRefund have no supported stable transaction identity. They can be inspected as native observations, with `MISSING_STABLE_TRANSACTION_ID`, not counted as durable financial transactions. Capture timestamps after the server evaluation time are rejected. No unsupported maximum-age rule is invented: a valid historical transaction is not stale merely because it is old. Old source-contract versions or changed selected contexts fail closed.

`projection` rebuilds every observation from retained original bytes and requires exact canonical packet equality before producing a fixed redacted view. Raw customer notes/status strings, source references and buyer/address data are not displayed. A tampered packet cannot change UNKNOWN/false authority flags, supply a fabricated identity index or substitute native content.

## Operator workflow and persistence boundary

`FinanceNativeSourceWorkflow` is integrated into the existing main-site Finance view, beside the established allocation preview. It requires authentication, existing Finance and platform capabilities, current Finance assignments across all four architectural shops, a nonce, the exact active selected shop and bounded typed input. Authorization is rechecked after processing. Deferred shops cannot be selected for this feature. The result is a keyboard-focusable, horizontally scrollable redacted integrity table; posted source fields clear after every response. Failed validation exposes no raw diagnostics.

This is **nonpersisting local staging**, not a native import endpoint. The page says this explicitly. Tests observe zero database mutations and zero HTTP attempts during validation. STOP ALL/external lock/automation OFF remain unchanged.

Durable source replay protection is intentionally not claimed. `ReviewedLocalEvidence` is a bounded, signed local review stream, with a 32 KiB payload bound and credential-key restrictions; it is not an arbitrary raw financial-document store. `Repository::importDeclaration` is an accounting writer and must not be reused to store native observations. Retention/privacy, source-account binding, atomic uniqueness, correction revisions and missing-history/revocation semantics need a reviewed native-storage contract before persistence. No new tables, options, APIs, approval privileges or generic review kinds are added.

## Source-to-accounting reconciliation

| Native evidence | Supported now | Still blocked |
|---|---|---|
| Payment / posted / adjusted | Preserve all distinct representations and source identities | Gross includes shipping/tax; views cannot be summed as separate revenue. Complete fee population, occurrence/finality and category/precision rules are absent. |
| Ledger entry | Preserve native signed integer, currency and correction references | No invented divisor; no automatic four-decimal declaration or separate revenue. |
| Payment adjustment / item | Validate parent links; reject conflicting hashes across documents | Refund/fee/reversal classification and receipt/order/line attribution require actual records and reviewed rules. |
| Money / ShopRefund | Preserve native units and absent/null data | Stable transaction identity, authoritative finality and complete source population are missing. |
| Printify/Gelato | Existing declared/provisional contracts are reused unchanged | No pinned paid-invoice/order-export monetary schema is available in repository evidence. Catalog/shipping estimates are not settled charges. |
| OpenAI aggregate costs | Existing pinned aggregate schema remains research evidence | No invoice/charge identity, exact-decimal/finality guarantee, per-charge occurrence or reviewed account/project/shop/environment attribution. Aggregate usage is not settlement. |

No reviewed raw-source-to-DECLARATION mapping exists. Existing `DeclarationContract` rejects a staged packet. Existing revisions, allocations, inverses, FX evidence and period-completeness approvals are unchanged. No new Finance formula, pooling policy, source-authority claim or tax interpretation is introduced.

## Validation and acceptance

- New staging tests: **27 tests / 77 assertions PASS**, including all eight schemas, exact selected context, changed source version, native precision, malformed/oversized/empty sources, future capture, duplicate/related/conflicting identities, raw-byte preservation, tampering and declaration rejection.
- Combined focused Finance/parser suite: **134 tests / 289 assertions PASS**.
- New isolated WordPress workflow tests: **5 tests / 32 assertions PASS**. Both active shops, all-four access, revoked/missing assignments, anonymous/foreign/deferred denial, nonce/request validation, browser slash handling, redacted rendering, cleared inputs and zero writes/HTTP.
- Existing real-scanner security acceptance: **6 tests PASS**, using the workflow-pinned Gitleaks8.30.1 archive with verified SHA-256. The first invocation lacked its required scanner environment variable; supplying the pinned local tool resolved that harness prerequisite. No scanner rule or gate changed.
- Supplementary changed-code PHPStan level 5: **PASS**, actual existing WordPress symbols scanned, no suppressions. This supplements unchanged mandatory repository analysis.
- Tests use Docker-isolated, internal-network MariaDB and PHP 8.3 fixtures, no published ports. The first database readiness probe reached the bootstrap socket before TCP was available; it was corrected locally to await TCP. One render fixture initially omitted WordPress's request slashing; it now models the actual `wp_slash`/`wp_unslash` boundary. These were harness corrections, not production-validator relaxation.
- Read-only independent source/security review found no reproduced accounting-promotion or privacy defect in this patch; it confirmed that context assertion is not provider ownership verification.
- Responsive/keyboard markup is locally exercised; no fresh installed-site, real-browser/mobile-device, GoTrue/provider or commercial certification is claimed. Existing passing browser evidence is inherited only for unchanged surfaces.
- Hosted exact-head audit is required on the published final candidate. Its authoritative receipt belongs in the final draft PR/issue #1209 comment; no self-referential audit claim or follow-up code commit is needed.

The authoritative matrix retains all 376 rows and existing classifications: **246/268 non-Finance implementation candidates; 22 UNVERIFIED; all 23 atomic Finance-dependent criteria incomplete**. Eight existing Finance source-related rows gain implementation/test references only. A 24th Finance-labelled aggregate row is not another atomic criterion. The other Finance requirements retain their existing implementation/blocker evidence; this work supplies neither actual profitability nor financial acceptance. Master500 remains 500 identities / 360 bounded recipes / 140 artistic-execution-blocked; no promotion. FC01, supplier/native typography/geometry/rights, six-product/three-group plans and installed-site/release blockers are unchanged.

## Minimum owner inputs and next batch

1. Privately provide original Etsy payment/ledger/correction source bytes with precise seller account/shop context, receipt/order relationships and documented export/population limits. Do not send credentials. Public OpenAPI schemas are already pinned; do not upload them again.
2. Provide reviewed source-to-category, native-unit/precision, correction/finality and double-counting rules. Payment/ledger views must be reconciled as representations, not independent income.
3. For safe native persistence, approve retention/privacy and exact source-account/integration/revision binding with a recoverable original-byte store. Preserve current signed binding/revocation and all-four Finance controls; no generic review-store expansion is implied.
4. Supply actual POD paid invoice/order-charge exports and authoritative fields for production, shipping, tax, discounts, refunds/finality; for AI, actual billing records and reviewed account/project/environment attribution.

Next independent implementation is a native-source persistence/import adapter only when these contracts exist. Until then, further financial mappings, durable-replay claims or provider parsers would be speculative. No authentic Finance criterion or production activation is approved by this batch.
