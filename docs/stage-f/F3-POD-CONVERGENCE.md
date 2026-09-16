# Stage F3 — POD Persistence, Replay & Economics Convergence

Status: development-only; no provider execution is authorized.

## Safety boundary retained

- Printify/Gelato/provider intents remain data-only and start `BLOCKED`.
- Provider/order network execution is outside `DigiForge\POD\Repository`.
- Human approval remains required for provider mapping readiness.
- Release-ready production evidence and approved print areas remain mandatory.
- No Etsy publish, provider order, fulfillment, GST, or schedule control is activated by Stage F3.

## Replay convergence contract

The REST mutation boundary already requires `Idempotency-Key` and reserves `sha256(operation|header)` before repository mutation. A duplicate externally reachable mutation therefore fails closed before a second repository write.

The repository persistence helper is intentionally tracked as a defense-in-depth gap: direct internal callers currently receive an existing row for a repeated idempotency key without comparing immutable payload fields. Before final release certification, POD persistence must converge with Product/Listing persistence so that:

1. exact replay returns the existing row with an explicit replay marker;
2. same key + changed immutable payload returns HTTP-equivalent conflict (`digiforge_idempotency_payload_conflict`);
3. mutable lifecycle/audit fields are excluded from comparison;
4. key length is validated rather than silently changing business identity;
5. duplicate-key races re-read and validate the winner rather than converting a safe replay into a generic create failure.

## Economics/readiness contract

Current mapping readiness proves production and human-approval readiness, but final POD release certification must also distinguish operational readiness from economic evidence. The final readiness model must expose, without activating provider execution:

- current approved provider mapping;
- approved print area(s);
- release-ready production bundle;
- approved personalization when applicable;
- human approval;
- provider/environment integration identity;
- latest applicable cost snapshot and its state;
- cost currency and observation timestamp/source;
- whether economic evidence is missing/stale/unapproved.

Economic evidence must fail closed for any workflow that claims a product is commercially ready. It must not silently invent margin, shipping, tax, provider fees, or exchange rates.

## Final certification rule

Stage F3 is not complete merely because provider intents cannot execute. Final certification requires both replay-safe persistence and explicit economics evidence, while all external provider controls remain disabled until separately authorized after the final release artifact passes certification.
