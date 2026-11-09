# Approved Revision 1 — implementation checkpoint, NOT operational acceptance

Owner instruction authorizes Revision1 wording for separately reviewed offline implementation only. The original approved proposal remains byte-identical (SHA256 ffbbfe63ecc4223d3192c7fc01a8a33e872cfb51f78a896696f2537465526c9e), including its historical DRAFT heading; this checkpoint records the later limited engineering decision without rewriting the approved artifact or posting an owner attestation.

Starting source b7d60b0349e54f400fd4bc0ec781edbb7da4b518; existing PR1260 / feat/printify-api-completion-20261108. No production changes, credentials, installation, provider requests or operational adoption.

## Implemented safely

- Pure `PrintifyAcceptanceOperationalPolicy` pins exact Revision1 digest, GET/capability, actor1, Shop2, integration3, production shop28930593 and requires certification, installation, independent review, operational approval, association, current generation, audit and300-second authority evidence. It requires business locks ON, automation/activation OFF, disabled integration and RELEASE HOLD. Caller-supplied evidence returns only eligibility, never credential or execution authorization. No runtime path trusts these booleans.
- `PrintifyAcceptanceSafetyGeneration` uses existing InnoDB options/audit tables, uncached direct reads, sealed random generations, unique audit anchors and atomic compare/exchange. Successful repeated emergency events rotate even while STOP ALL stays true. Concurrent CAS losers deny. Missing, tampered, unaudited, transactional or non-durable evidence denies.
- `Settings::set(stop_all,true)` and protectProduction invoke emergency invalidation. Setting STOP ALL still attempts to persist even if generation fails, returning failure rather than acknowledging a successful emergency event. protectProduction refuses caller-owned transactions before START TRANSACTION so it cannot implicitly commit caller work. No other setting or native permission is loosened.
- Existing bridge sessions and independent approval payloads bind the generation. Current checks run before entering the upstream credential boundary, inside the physical sender guard and after response. Changed in-flight authority produces UNVERIFIED / AUTHORITY_CHANGED_OUTCOME_UNKNOWN; no automatic retry and no slot restoration. This strengthens the existing normally governed bridge; it does not admit disabled-integration execution.

## BLOCKED — failed emergency persistence

The existing transaction/storage contract cannot guarantee durable revocation when an attempted emergency write is rejected or cannot persist. In particular, inside a caller-owned transaction terminate() rejects the event; after caller rollback the old generation remains readable. A passing test explicitly records this limit; it does not qualify emergency revocation as complete. Likewise a total database outage cannot durably record its own failure in that same unavailable database. An error response is not a permanent revocation fence.

Consequently **no parent-lock exception or disabled-integration credential path is enabled or implemented as executable authority**. Existing unconditional STOP ALL/external lock denial remains in Bridge and ordinary transports. The normal enabled-integration validator, credential retriever and all business mutation paths are unchanged. The pure evidence classifier is never used to relax them. There is no generic bypass, process-global exemption or second transport.

Before advancing, separately review a durable emergency-fence/dispatch linearization protocol that survives failed/caller-transaction persistence, and prove it with independent connections, interruptions and in-flight requests. Do not claim atomic SQL compare/exchange alone proves end-to-end HTTP revocation. If independent durable custody/storage is needed, its provisioning is outside this offline authorization. This is an engineering blocker, not a request to disable a lock or enroll an owner.

## Credential and binding boundary

No live token is read, decrypted, fingerprinted, copied or tested. Existing encrypted vault and enabled runtime gates remain unchanged. Account reference, credential association and effective API scope remain UNKNOWN. Shop2 binding is still proposed, not written; integration3 disabled; Pop-Up28925875 excluded; Shop3 deferred. No new credential store is created.

Once the emergency fence is proven, complete the independently certified, exact-site/session authorization resolver and operation-bound disabled-integration vault admission through the existing transport. Do not accept caller eligibility flags, dashboard access or encrypted-material presence as authority. Retain catalog grants, account quotas, immutable GET descriptors, single-use reservations and all minimized receipt contracts. Current software intentionally cannot perform a locked-state acceptance GET.

## Qualification disposition

IMPLEMENTED / OFFLINE_VERIFIED applies only to the tested foundation above. Full exception: BLOCKED, NOT SOFTWARE_COMPLETE. No version bump or installation ZIP is issued for an incomplete safety implementation. This source checkpoint is not a replacement for the qualified .25 artifact. Hosted source audit results must be recorded separately; passing source tests cannot close the failed-persistence requirement or certify a hosted exact ZIP.

STOP ALL ON; external safety lock ON; automation OFF; activation unauthorized; RELEASE HOLD. No historical reservation22 retry. No owner, provider, Etsy or production changes. No installation authorization requested.

## Exact shared-file preservation receipt

Initial source audit failed the historical Settings.php byte pin after the explicitly requested parent-policy preparation. The original dependency manifest/hash remains unchanged. A separate exact three-hunk emergency-settings overlay records baseline and final hashes, removes only the reviewed hunks during preservation checking, then verifies the original pin. It permits no other Settings.php delta and adds no runtime authority. This follows the existing narrow reviewed overlay convention; no blanket allowlist, assertion removal or mutation admission.
