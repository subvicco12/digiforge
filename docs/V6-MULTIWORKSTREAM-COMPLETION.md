# DigiForge v6 multi-workstream completion strategy

Authority: DigiForge Ultimate Master Blueprint v6.0.

## Delivery model

Independent work proceeds behind shared contracts and is integrated before audit. External execution remains separately authorized and fail-closed.

### Stream A — Personalized catalog
- Master 500 v1 remains immutable source lineage.
- Import contract requires exact governed headers, exactly 500 rows, unique DG IDs and a deterministic fingerprint.
- v2 is a versioned migration plan, never a silent overwrite.
- Catalog evidence never grants production authority.

### Stream B — Personalization / Etsy compatibility
- Internal personalization schemas may carry a validated Etsy projection.
- Etsy projection supports 1–5 typed questions: text, dropdown and one upload question.
- Legacy single-field assumptions are not authoritative.
- Optional text questions can preserve explicit add-on pricing policy.
- No Etsy write is performed by the contract.

### Stream C — Shop-scoped AI
- Every plan has a shop key, currency, monthly budget and stage quantity ceilings.
- Stages: research, shortlist, develop, listing_prepare, render, QA.
- Quantity or budget ceiling closes execution eligibility; it does not grant execution below the ceiling.
- Actual provider billing remains evidence distinct from estimates.

### Stream D — Orders / fulfillment
- Fulfillment mode is explicit: digital, pod, hybrid.
- POD and hybrid require provider mapping evidence.
- Readiness remains distinct from external fulfillment authorization.
- Webhook evidence never authorizes provider execution.

### Stream E — Portal
- Personalized POD portal surfaces the governed Master 500 identity and counts.
- Portal status is read-only evidence in this batch.
- Routine operations continue moving toward the DigiForge front-end rather than wp-admin.

## External research compatibility checkpoint — 2026-09-27

Etsy Open API documentation now uses dedicated listing-personalization endpoints and typed personalization questions; legacy listing personalization fields are deprecated. The integration contract must support multiple property_id 54 transaction variations and file-upload values. Etsy also documents optional text personalization add-on pricing.

Printify catalog/provider/variant evidence remains normalized independently of DigiForge business ownership. Provider mutation is outside this contract batch.

## Integration rule

A workstream may not invent a second representation when a shared contract exists. Behavioral tests are preferred for semantic rules; structural tests may supplement but must not be the sole evidence for business behavior.
