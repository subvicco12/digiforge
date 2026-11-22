# PR1258 security remediation

Base reviewed source: `10b0b7a25019fa0b40bd86658e08b319161c637b`. Parent PR1253: `6e521719d925c75f27aed5ccdd47048cc1e5a15d`. Main observed unchanged at `3758d1385f7d4a62947248822c1fe1c89ec9818e`.

## P1 — REST admission

The two adjacent JSON inventories record actual WordPress registrations (pattern, method, callback, permission callback and registration source) from public source and the exact installed-lineage 1.0.103.2 package. They contain no production records or credentials. `RestRoutes::ENTRIES` is their finite union, checked against every registered endpoint during source and exact-package tests. New patterns, unsupported methods and altered callbacks fail closed. Numeric IDs must be canonical positive bounded integers; leading-zero paths cannot avoid a compound permission matcher.

`RestPolicy` intersects explicit module permissions with current native signed shop grants for managed and legacy callers. Stored target resolution uses retained installed authorization helpers. Fixed Shop2 routes reject conflicting aliases; global collections and mutations require all four shops regardless of caller-supplied claims. Compound launch, supplier/listing and research/product operations require all affected capabilities. Scheduling approvals/retry/resume remain global until factory-owned ancestry resolution has an authoritative concordance contract; only an explicit REJECTED review can use the candidate's stored scope. Existing nonce, signature, idempotency, execution approval, rate-limit and external-lock checks remain in their original callbacks.

Native assignment routes retain their independently signed callbacks. A managed Primary Owner must additionally satisfy current audited enrollment for the exact shop and may grant only capabilities within that enrolled profile. Other managed roles cannot use those routes. The exact health, signed-webhook, customer-session and recovery-attestation boundaries retain their independent authentication; arbitrary prefix exemptions are prohibited. Viewer still permits only the finite safe GET surface and cannot use HEAD or supplier-executing GETs.

## P1 — independent authority

`ShopAccessAuthority.php` is restored byte-for-byte from installed 1.0.103.2. The host-configured `FirstPartyOwnerPolicy::records()` dispatch is removed; that unused inherited source helper cannot grant runtime authority. Ed25519 detached verification, public key/signature sizes, record types, actor, canonical shop, expiry and approval hash checks are retained. Tests cover no-trust with an alternative-owner stub, wrong key, wrong signature, tampering, expiry, invalid actor/shop/authority, actor/shop mismatch and revocation. No identity, key or signed production envelope is generated.

The original sixteen-file shared dependency manifest remains unchanged. `installed-security-restorations.json` records the explicit source restoration hashes rather than pretending those original shared-core bytes were unchanged. The restrictive `ShopAccess.php` overlay remains byte-identical to its reviewed receipt; reverse-patch tests recover its original approved dependency hash. Source-only target/guard helpers are exact retained installed bytes in the complete package, not downgraded replacements.

## P2 — empty permissions

Forms send `modules_present=1`. Only CHANGE with that exact marker can normalize omitted checkboxes to an empty list. Invitations and new profiles still require nonempty modules. Removing the last module saves one audited disabled deny-all profile, preserves managed-shop/history evidence, advances generation and creates no native grant. WordPress identity, capabilities and sessions remain untouched. Malformed values and ownerless empty initial profiles fail closed.

## Composition and acceptance

Version 1.0.103.4 composes the verified 1.0.103.2 package, three reviewed integration edits and fourteen added feature files. No existing file is deleted. The builder verifies every retained byte, exact security helper hashes and the narrow admin-guard/bootstrap deltas. It records each file's baseline hash, packaged hash, origin and checkout equivalence; this is deliberately not described as a complete checkout ZIP. In particular, installed publishing controllers and factory hooks remain unchanged despite differing source-branch implementations.

Focused RED tests reproduced the reported omissions, alternative-owner fallback, empty-module rejection and newly reviewed scope gaps before correction. Automated source re-review found no remaining confirmed P1/P2 defects in the examined changes; it is not human approval. The final per-package qualification and CI receipts accompany the archive. No new schema migration, account mutation or activation is introduced.

Hosted exact-artifact audit is NOT RUN pending private intake and a fresh digest-specific reviewed admission profile/attestation. See `remediation-hosted-intake.md`. Passing source CI and local ZIP-extracted tests do not replace that gate. Installation remains BLOCKED pending exact-artifact release acceptance, current production drift/schema evidence, coordinated full-site recovery/isolated restore acceptance and separate installation approval. Primary Owner additionally requires independently provisioned Ed25519 trust, verification of the existing WordPress account and explicit audited per-shop enrollment.

STOP ALL ON; external safety lock ON; automation OFF; RELEASE HOLD. No production or external supplier operation was performed.
