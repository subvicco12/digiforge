# Batch 14 — independent native Finance custody hardening

Baseline independently verified: draft #1231, `f656ead15090859bce0b3558d68ffea5fee20010`, audit #4766 PASS. Main remains `844107ab9baf7e1303cd47708e9eab3bc700b348`. This isolated follow-on preserves the source branch. An independent read-only reviewer challenged the implementation; the primary writer reproduced failures with synthetic original bytes and a disposable local database before changing behavior.

## Reproduced failures and corrections

| Defect family | Reproduction on prior code | Correction |
|---|---|---|
| Final authorization | Existing-record replay returned before final authorization. A change during the final read could also escape new-write validation. | One authorization check at the common transaction completion boundary covers fresh appends, retries and receipts. |
| Concurrent/stale authority | An independent committed connector change remained invisible through a repeatable-read snapshot. A stale outer transaction also hid changes outside a partial connector projection. | Lock existing four-shop grant states, the existing Digital review stream, Etsy/test connector claim range, non-secret credential metadata and business/store lineage. Compare exact current versus caller-snapshot values, including the full non-secret integration row; deny stale snapshots. Existing grant/review writers use the same state-row locks. |
| Correction ancestry | Missing intermediate original rows or altered source hashes could leave deeper corrections readable. | Authenticate bounded scope history in original row order, including every ciphertext, source hash, audit witness, predecessor, schema and exact identity set. Native record/claim/audit reads use current locking reads. |
| Immutable first identity / orphan history | A claim could be redirected to a later quarantined record. Deleting a claim, or original plus claim while leaving its audit, could admit changed bytes as a new first observation. | Reconstruct first claims from authenticated originals and require exact index matches. Orphan native audit/claim existence denies admission and reads. The internal orphan check returns no foreign-scope data and runs only behind existing all-four Finance authority. |
| Audit author provenance | Altering the audit actor left the source receipt accepted because only its context was checked. | Require audit actor to match the creator inside the authenticated original envelope, in addition to exact ciphertext-digest linkage. |

The initial expanded 20-case run reproduced six failures. Additional isolated cases reproduced orphan-source readmission, incomplete stale identity projection and changed audit creator. A regression found during repair blocked unrelated new identities after an explicitly reviewed binding refresh; that was reproduced and fixed. Historical receipts/replays retain exact old binding pins, while genuinely new transaction identities can use a separately approved refreshed relation. There is no automatic historical rebinding or resurrection of a revoked review.

## Compatibility and scope

No Finance encryption algorithm, key provisioning, native financial parser, money interpretation, schema, migration, portal registration or transport changes. Existing version-1 encrypted packets and record identities remain valid without backfill. Missing/wrong keys, ciphertext tampering, scope substitution, nonlocal environments and unsupported POD ownership remain denied by existing guards/tests. Source bytes and capture timestamps remain encrypted exactly; no raw response or source diagnostics are exposed.

The history verifier has a fail-closed resource bound of 500 original records per exact stable source scope; exceeding it denies rather than pruning or skipping history. This is an offline processing limit, **not** a retention duration or production capacity certification. Synthetic over-bound testing performs no cleanup or admission. A test fixture disposes orphan artifacts created by previous fault-injection cases only inside its disposable database; there is no application erasure API or cleanup job.

Current authority and evidence locks live through the existing transaction/savepoint boundary. Independent conflicting updates serialize or deny; caller-owned transactions are not implicitly committed. Corrupt global native history causes a generic denial rather than exposing any other scope's records. Repair of missing evidence requires authentic restoration; no repair, deletion or recovered authority is inferred. Coordinated rollback/rewriting of the complete database, audit and key custody by an administrator is not claimed to be prevented.

## Validation and acceptance boundary

Final focused unit111/220 assertions PASS; WordPress compatibility52/944 assertions PASS, including26 native-store cases/533 assertions. Supplementary changed-code PHPStan5 PASS without suppressions. Candidate-history security scan and mandatory hosted exact-head audit are recorded in the final PR/issue #1209 receipt. Original missing/wrong-key/context/ciphertext and nonlocal-runtime tests are reused; no real credentials or actual financial records are accessed. There is no fresh installed-site or browser certification.

All 376 protected classifications remain unchanged: 246/268 non-Finance implementation candidates, 22 UNVERIFIED, and all 23 atomic Finance-dependent criteria UNVERIFIED. Added regression evidence hardens local custody only. Master 500 remains 500 identities / 360 bounded recipe capabilities / 140 artistic-execution-blocked concepts.

## D01–D06 remain separate

| Decision | Remaining authentic evidence or owner input |
|---|---|
| D01 ownership | Authentic Digital seller/account/integration evidence; an appropriate independently reviewed Personalized POD Etsy seller-account relationship. Printify ownership is insufficient. |
| D02 custody/privacy | Production readers, privacy scope, key recovery/backup and retention/legal-hold/erasure policy. No duration, expiry, deletion, erasure or cleanup is implemented. |
| D03 source lifecycle | Authentic corrections, source history and independent finality evidence; unsupported Money/ShopRefund identities stay observation-only. Historical rebinding remains unsupported. |
| D04 accounting | Reviewed category, sign, divisor/precision/rounding, FX, attribution, correction, completeness and finality mappings. Payments and ledger views are not separate revenue. |
| D05 original sources | Private original Etsy observations with capture/population context and documented paid POD invoice/order-charge sources. |
| D06 AI costs | Actual invoice/charge semantics, source identity, precision/finality and account/environment/shop attribution; aggregate usage is not settlement. |

The smallest next owner input remains a private scoped Etsy example with authentic seller/account/integration evidence and reviewed mapping/finality rules. Production custody, retention and actual imports require separate authority. Engineering approval is not being requested again.

**NO-GO. STOP ALL ON; external lock ON; automation OFF; RELEASE HOLD.** No actual imports, provider calls, production/staging writes, financial approvals, orders, publishing, activation, merges or deployment.

## Hosted security-gate finding and required dependency correction

The first changed-head audit, #4767 at `5e81720ba8817854dd56d1bc4dd44575cb91591e`, failed before application tests at the unchanged offline dependency advisory gate. Official PyPI metadata now lists eight advisories for Pillow12.3.0, all fixed in13.0.0: GHSA-p4wg-p73j-c528, GHSA-65qm-jvmh-6pqg, GHSA-58r4-6rv3-gfh8, GHSA-pchr-8j38-3x4p, GHSA-q45j-58j2-45rg, GHSA-462x-pgg6-55fp, GHSA-c6hw-5grf-ff96 and GHSA-vx6w-vv9f-6gvg. Public exact-version13.0.0 metadata reports no advisories and requires Python>=3.11; CI uses3.12. Both default and experimental requirement pins, actual worker version checks/receipts and PHP normalization guards are updated together to13.0.0. The advisory script and all CI gates are unchanged; no exception, filter, warning suppression or blind rerun is used.

The source geometry, rights, model-weight and commercial-certification gates remain unchanged. Historical12.3.0 fixture provenance and source hashes are not rewritten. Old processor normalization receipts remain historical and cannot silently pass as new13.0.0 processing; reprocessing and independent review remain required where existing processor hashes become stale. This correction certifies neither SAM inference quality nor artistic/print suitability. Actual offline decode/orientation, strict retired-runtime rejection and the full affected offline suite are validated under13.0.0. Final exact-head evidence is recorded in the final PR/issue receipt; #4767 is retained as the failed security-gate evidence.

Both unchanged dependency advisory commands pass with13.0.0. The135-test offline attempt passed134 and found a new test setup error: simulated version drift preceded the compiled Pillow import. Loading the real compiled module before version simulation corrects the test; the affected7-test module then passes, including rejection of the retired runtime. The final hosted audit must run the complete135 cases on the final head. No test skip, advisory exception or security-gate change was introduced.
