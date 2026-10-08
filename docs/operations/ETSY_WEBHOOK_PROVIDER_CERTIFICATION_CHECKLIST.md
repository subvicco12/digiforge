# Etsy webhook provider-side certification checklist

Authority: DigiForge Blueprint v6.0 and completion ledger #959. Repository-side signature-first verification, replay protection, deduplication and local-only order intake are implemented; provider-side delivery/signing is **not certified**. This checklist is preparation only and grants no provider activation.

## Preflight (read-only)

- [ ] Confirm the exact installed and certified DigiForge artifact and current isolated-staging environment.
- [ ] Confirm STOP ALL is ON, external safety lock is ON, activation authorization is OFF, automation is unarmed, and effective external switches are OFF.
- [ ] Identify the approved Etsy shop, webhook endpoint, subscribed events and provider-supported signing scheme from authoritative provider configuration; never invent signature headers or algorithms.
- [ ] Confirm webhook signing secret is provisioned only through approved server-side secret storage; do not copy the secret into issues, logs, screenshots or client-side settings.
- [ ] Verify transport TLS, endpoint identity, timestamp tolerance and replay window against current integration contracts.
- [ ] Prepare a redacted evidence record with environment, shop reference, exact artifact SHA, UTC time and responsible human reviewer.

## Isolated certification scenarios (only when separately authorized)

- [ ] Valid provider-signed delivery passes signature-first verification and persists local VALIDATED/REVIEW_REQUIRED intake only.
- [ ] Missing/invalid signature fails closed before payload mutation.
- [ ] Expired/replayed delivery fails closed; duplicate delivery remains idempotent without duplicate order.
- [ ] Wrong shop identity, malformed payload and unknown event type are rejected or review-required as defined by the contract.
- [ ] Database evidence outage yields UNAVAILABLE/REVIEW_REQUIRED, never success.
- [ ] Provider retry/timeout does not imply order approval, fulfillment authority, Etsy publication or Printify/Gelato execution.
- [ ] Verify sanitized logs, correlation IDs, persisted status and operator portal attention; preserve historical evidence.

## Sign-off and activation boundary

Capture exact provider event ID (redacted as appropriate), signing verification result, deduplication key, expected/actual local state, request time, code/artifact identity and reviewer sign-off. Failed or missing scenarios remain NOT CERTIFIED.

Certification does not authorize production webhook enablement, paid-order fulfillment, external retries, POD provider dispatch or automation. Any provider-side configuration change or live signed test requires its own explicit scoped approval. Never bypass STOP ALL or the external safety lock.
