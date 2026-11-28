# DF-OWN-001 v1.0: scoped provider connection evidence

Owner engineering decision: 2026-10-18, supplied directly in the implementation request. This records the approved engineering contract, not a provider ownership signature or operational approval. Strict shop isolation, fail-closed revocation/drift, deferred Shops 3/4, unapproved legacy relationships and draft-only engineering are mandatory; no exceptions are inferred.

## Baseline and gap

Verified main `844107ab9baf7e1303cd47708e9eab3bc700b348`; open drafts #1194 `135a3dc2bfd6602dc620ca6ea79f1072c4c3f024` → #1195 `02a67a61b7247457546a4530d26ca971d3645ec5` → #1196 `9fab24a5ecd0538ea18e70cde3b79b6151643eab` → #1197 `d381d0a10251c89caa37c069d069f4aa892a1d08` → #1198 `60f45e3e67b2ba8a468b5ee73c9163758d5d5625`. Prior exact-head audits #4721/#4725/#4726/#4727/#4728 remain successful. This branch extends #1198 and does not modify earlier branches. No repository AGENTS.md was found in this checkout; the existing source ledger and approved task contract are reused.

Existing product ownership, integration metadata, catalog grants and independent reviewed local assertions were already implemented. The missing relationship was an explicit persisted connection-to-business/shop/program reference, rather than treating configured metadata as ownership. Catalog grant admission previously did not require that separately reviewed relationship. The legacy portal ownership projection correctly reported NOT_ESTABLISHED.

## Additive structure and authority reuse

`ProviderConnectionSchema` installs schema revision **1**, using a separate `digiforge_provider_connection_schema` marker without changing or rewinding global version 25. Plugin boot and activation both require its success. An exact-column probe and marker avoid repeated startup DDL; a missing table is repaired, failed DDL/marker persistence denies success, and no legacy approvals are backfilled.

The new `{prefix}digiforge_provider_connections` table contains append-only proposal relations: unique proposal/binding hash, review-stream key, integration/business/store/program identifiers, canonical shop/provider/capability, canonical payload and creation time. It follows the repository's indexed logical-reference convention, with application-level exact registry joins rather than cascading SQL foreign keys. Deletion or corruption cannot confer authority; historical orders, artifacts and reconciliation rows are untouched.

The payload binds installation URL/blog/runtime environment/main-site identity hash, integration environment, business/store/program, exact account and external shop references, existing `printify.catalog.read` capability, integration snapshot and audit-history hashes, non-secret connection-material metadata hash, source SHA-256, local evidence reference and absolute observation time. This batch supports the existing Printify catalog capability only; it does not invent Etsy/Gelato/order/production capabilities.

Approval identity, revision/version, effective/expiry times and revocation remain in the **existing** `ReviewedLocalEvidence` ownership stream: independent author/reviewer permissions, atomic audit/state, HMAC/hash-chain verification, durable request replay checks and current shop assignments. The relation row and proposal are committed atomically using checked InnoDB storage and nested-savepoint handling. They are not an alternative authorization system. No raw credential, ciphertext, bearer token or private signing key is copied into a relationship.

`VERIFIED_LOCAL_BINDING` means the stored tuple matches an independently reviewed local assertion. Every output still says `provider_identity_verified=false` and `external_execution_authorized=false`; authentic provider certification is not implemented by relabelling synthetic review.

## Enforcement and portal

`PrintifyCatalogReadRepository` requires the exact relation at grant creation and active grant revalidation. The relation hash is included in the immutable grant binding; a newly reviewed relationship cannot silently revive an old grant. Existing pre-dispatch, post-response and evidence-read validation therefore recheck ownership as well as the original account/shop/grant/dispatch/replay/safety controls. Global REST target-shop discovery resolves only metadata so the existing assignment guard runs; its private metadata-only path cannot approve, reserve or dispatch a request.

Missing/ambiguous relation or audit evidence, expiry, withdrawn review, lost assignments, account/shop/environment/registry/material changes and duplicate configured external-shop identities deny. Integration audit-history hashes prevent restoring old configuration in the same second from reviving an earlier approval. A shared credential does not authorize another integration or shop. Independent STOP ALL/external-lock/catalog-exception controls are preserved, with no new exception or activation path.

Read-only connection status is exposed in the existing selected-shop **Fulfillment / Providers** view and the globally authorized Businesses view. The stronger all-shop Businesses guard is unchanged. Statuses distinguish UNKNOWN, PENDING_OWNER_APPROVAL, VERIFIED_LOCAL_BINDING, REVOKED, DRIFTED, invalid/missing evidence and deferred shops. Collections are shop-filtered before a 50-row window; failures are UNAVAILABLE, never zero/verified. Digital reports NOT_APPLICABLE and acquires no POD prerequisite. `standard_pod` and `jewelry` stay deferred without provider inheritance.

## Validation and limits

The new native regression first failed because the scoped relationship did not exist. Tests use synthetic registry entries, credentials and independently assigned reviewers, never real account identifiers or provider calls. Coverage includes exact lookup, foreign/deferred shops, shared material, absent/duplicate evidence, expiry, revocation and lost revocation state, environment/account/shop/material/registry drift, replay/self/stale approval denial, safe portal rendering, SQL faults, atomic rollback, idempotent migration and recovery without approval backfill. The catalog integration test revokes the ownership review inside intercepted HTTP and asserts that the response is discarded. Existing catalog tests retain their assertions; fixtures now make an explicit independent synthetic relationship review, including fresh review after a connector is re-enabled.

Final sequential focused validation: **46 native tests / 775 assertions**, **39 production-profile catalog tests / 618 assertions**, WordPress-aware PHPStan PASS, mandatory WordPress profile coverage PASS, and **64 authenticated loopback browser checks / 194 requests** at 390/1280 widths with zero startup DDL, zero external requests, zero business-row mutations and 44 monitored tables unchanged. See `df-own-001-local-evidence.json`. Existing injected failure tests emit expected SQL/audit diagnostics; PHPUnit reports no failed test.

One pair of local runs accidentally overlapped on the same disposable prefix, invalidating each other's fixtures. Those runs are not acceptance evidence; final runs are sequential. The existing authenticated desktop/mobile/keyboard harness remains separate from hosted CI. Full exact-head audit results are attached to the draft PR, not inferred from local tests.

Automatic approval review rejected extending this work into credential retrieval and order/production transport interlocks, citing sensitive external-operation boundaries outside the scoped catalog work. Those attempted additions were removed. **Existing non-catalog transports/interlocks remain unchanged; comprehensive DF-OWN-001 enforcement there is not claimed.** This requires a separately explicit approved engineering batch before broader ownership enforcement can be declared complete. No controls were bypassed.

Acceptance remains **246/268 implemented candidates; 22 UNVERIFIED**. DF-OWN-001 resolves the missing design decision for BP-portal-4-provider-connections, but does not certify it: the ledger now has ten owner-approval, seven external and five composite/unverified blockers. Both that criterion and BP-printify-shop-scope remain UNVERIFIED.

Authentic Etsy/Printify account/shop ownership and integration assignment, PP-011 exact variant, supplier geometry/shipping, commercial mockup rights, production authority and installed-site acceptance remain unverified. The next smallest step is authentic offline identity evidence plus explicit authorization for the remaining sensitive-boundary engineering; no live verification or production activation follows automatically. No further unrelated software feature is proposed.

STOP ALL ON; external lock ON; automation OFF; draft/release hold. Finance and earlier PRs untouched. No provider calls, credentials from production, production/staging writes, orders, publishing, merges, deployments or activation.
