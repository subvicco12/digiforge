# DIGIFORGE_PRINTIFY_GET_ONLY_SAFETY_AMENDMENT_PROPOSAL

**Revision 1 — DRAFT / NOT ADOPTED.** Prepared for an approve/reject wording decision only. No signature, approval, adoption, runtime exception, installation, binding, credential access or live GET is created by this document or its publication in PR1260.

## 1. Purpose and limited scope

If separately adopted and implemented, this amendment would define one short-lived, human-controlled Printify acceptance session for secret-safe API compatibility verification. It is not product, order, listing, artwork, support, fulfillment or commercial automation. Current software remains fully denied under its existing gates. The owner may approve, reject or request changes to this wording independently of every subsequent operational decision.

## 2. Relationship to STOP ALL

The current Blueprint §6 and §31 prohibit narrower controls overriding disabled parents; §29 and its final baseline preserve an absolute safety ceiling. An allowance for GETs while STOP ALL is ON would change that semantic ceiling. This proposal explicitly requests that narrow parent-policy amendment; it does NOT claim that GET is already exempt, or merely relabel a bypass as diagnostics. If adopted, STOP ALL remains ON and continues to deny all ordinary business operations. Only the newly defined acceptance authority could be considered for specified GETs after a separately reviewed implementation. Until then, the current unconditional bridge denial remains unchanged.

## 3. Relationship to the external safety lock

The external safety lock remains ON. Adopting an acceptance-only exception would explicitly amend how that parent lock evaluates this one capability; the lock's present absolute meaning cannot simultaneously be claimed unchanged. Every other capability remains denied. No configuration flag is flipped or normal transport unlocked. Unknown lock state is denial, never permission.

## 4. Parent amendment must precede an exception

Owner approval of this exact revision is required before any separately reviewed exception implementation may be proposed for use. The reserved capability `printify.acceptance.read` is not a current runtime grant. A valid draft binding, reviewed session, successful CI or installed PHP file cannot adopt the amendment. Existing legacy catalog grants/exceptions retain their narrow independent contracts and do not authorize the broader bridge or disabled-integration credential access. No execution path is added by this release.

## 5. No normal provider automation

Automation stays OFF; activation stays unauthorized; RELEASE HOLD remains. Integration3 stays disabled. No scheduler, worker, cron, batch crawler or business workflow may consume acceptance authority. Any future implementation must use explicit one-request invocations and must not toggle settings temporarily or use a process-global bypass. Existing ordinary provider enablement checks must remain intact.

## 6. GET-only allowlist

Authority would name an immutable finite set of reviewed GET plans whose method, exact path, API version and source-contract digest are bound to the session. Only HTTPS api.printify.com V1/V2 routes admitted by PrintifyAcceptanceContract qualify. No arbitrary URL/host, request body, caller credential/header, redirected request, discovery of additional endpoints or automatic pagination is allowed. HEAD/POST/PUT/PATCH/DELETE are not acceptance operations. A provider endpoint being documented does not authorize invoking it.

## 7. Exact account/shop/integration binding

Target: existing authorized DigiForge owner actor1; internal Shop2 / personalized_pod; integration3; production; Printify Etsy shop28930593. Pop-Up28925875 is excluded, Shop3 remains deferred. Account identity, credential association, registry/catalog scope, approval revision and effective API scopes remain UNKNOWN until independently established. Dashboard access and encrypted-material presence cannot establish that linkage. Unknown, duplicate, conflicting, changed or revoked binding denies execution. A special inactive acceptance binding may describe disabled integration3; it is never an ordinary enabled provider connection.

## 8. Existing credential custody and disabled-integration admission

No credential creation, reveal, export, rotation or replacement is authorized. Any future amendment implementation would need a separately reviewed, operation-bound use of the existing vault credential while integration3 remains disabled. That is a proposed explicit change to credential admission, not permission to remove the normal enabled-integration check. Private metadata may be matched without exporting token fingerprints or values. Account/credential association must be resolved through approved evidence; if unavailable, stop. No guessed account or undocumented token-introspection endpoint is permitted.

## 9. Separate owner authorization

The existing enrolled Global Primary Owner must retain native WordPress capabilities, correct account/email binding and fresh email verification. WordPress administrator status or email possession alone is insufficient. After policy adoption, installation and binding acceptance, a separate explicit owner authorization must identify the exact session, reviewed plans, evidence revisions and limits. This proposal cannot serve as that authorization.

## 10. Independent approval and existing signed authority

Independent review of binding and exact session remains distinct from owner consent. Existing ReviewedLocalEvidence, owner and delegated-user policies stay authoritative. Where delegation requires Ed25519 verification, valid signatures, actor/shop/capability binding, expiry and revocation remain mandatory. The database-backed Global Primary Owner model is preserved; no new owner signing-root or production keys are generated. An unavailable independent reviewer or invalid/missing delegated authority means denial, not self-approval. Neither governing document explicitly mandates an Ed25519 owner envelope; do not invent that clause.

## 11. Session expiry and revocation

Maximum lifetime five minutes, bound to owner/browser session, profile generation/revision, site, account, credential metadata, exact shop/integration and reviewed allowlist. Authority is non-transferable and checked immediately before credential use, each physical request and after the response. Revocation or drift consumes/terminates remaining authority; it never authorizes a retry. Expired state is denied without a renewal default. An explicit new review is required for a new session.

## 12. Budgets and minimized receipts

At most ten one-use slots, three reservations per account per rolling sixty seconds, three pages, limit ten per page, one physical GET per slot, no automatic retries, timeout at most20 seconds and response cap1MiB. Existing shared account quota, Retry-After and catalog grant limits still apply. Slots remain consumed after interruption. Immutable/tamper-evident audit receipts bind endpoint/plan/schema hashes, scope, actual status, timestamps, correlation and bounded metadata. No credentials, raw provider responses, buyer PII, private media URLs or exception text leave the server.

## 13. Local receipt persistence

Only existing governed local proposal, reservation, nonce, revocation and minimized receipt/audit storage would be permitted as acceptance evidence. This is an explicit local-write allowance, not a production business-data mutation, configuration change or schema migration. If local persistence/transaction/audit verification fails, no request is sent; post-send uncertainty remains UNKNOWN. Historical receipts may be read only by the currently authorized original owner; reading cannot restore execution authority. Existing security denial audits remain.

## 14. Emergency termination must dominate

Emergency STOP ALL must invalidate acceptance authority even when its Boolean flag was already ON. A future implementation must provide a separately reviewed, atomic revocation/generation event linked to the emergency control, plus immediate session revocation and binding/credential-generation invalidation. Merely observing that STOP ALL remains ON is NOT an emergency event discriminator. If the existing emergency action cannot invalidate all outstanding acceptance slots, the exception stays unavailable. No new emergency control or generation is implemented here. In-flight response evidence after revocation is unverified, and no further request/retry may run.

## 15. Provider mutations prohibited

No image upload/archive, product create/update/Save/publish/unpublish/delete, order creation/submission/production/cancellation, support refund/reprint/address-change request, webhook provisioning or Etsy operation is authorized. Acceptance authorities must be rejected by every ordinary mutation transport and must contain no transferable business permit. Historical idempotency reservation22 is untouched.

## 16. Separate installation and execution approvals

Required separate decisions: (a) adopt/reject this amendment wording; (b) review/certify any later exception implementation and exact artifact; (c) authorize installation; (d) independently verify and approve exact binding/credential association without enabling normal integration; (e) verify supported connector owner/session authentication; (f) authorize one bounded live GET session. None implies another. Current authorization covers source preparation, offline tests and draft PR updates only. Current production .17 is not assumed to expose candidate abilities.

## 17. Automatic return to fully denied state

On expiry, revocation, emergency event, exhausted quota/slots, changed owner/profile/site/binding/credential/contract digest, scope failure, malformed response or missing/ambiguous evidence, acceptance returns to denial without cleanup-dependent permission. No scheduled renewal, fallback token, unsafe retry or enabled integration persists. All parent flags and ordinary business denials remain in the preserved posture. The default and present state is fully denied.

## Owner wording decision — no implied adoption

Possible future decision text: “I approve [or reject] revision1 of DIGIFORGE_PRINTIFY_GET_ONLY_SAFETY_AMENDMENT_PROPOSAL for a separately reviewed future implementation. This decision does not authorize installation, binding writes, credential access, owner provisioning, integration enablement, live GETs or provider mutations.”

No such decision has been recorded or posted by Codex. The current result is POLICY_AMENDMENT_REQUIRED / OWNER_APPROVAL_REQUIRED, with INSTALLATION_NOT_AUTHORIZED and LIVE_GET_NOT_AUTHORIZED.
