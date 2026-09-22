# Etsy External-Execution Safety Foundation

This sub-batch implements the policy boundary required before any live Etsy adapter is introduced.

Architecture baseline:
- Product lifecycle separates HUMAN APPROVAL -> PLATFORM DRAFT -> DRAFT QA -> PUBLISH APPROVAL -> PUBLISHED.
- Etsy lifecycle separates internal draft, QA, approval, external draft, reconciliation, publish approval, and published state.
- STOP ALL outranks workflows and feature flags.
- External effects require authorization, idempotency, auditability, and current-state checks.
- Controlled activation progresses from internal-only to read-only, webhook/reconciliation, external drafts, human-approved writes, then selected certified automation.

Implemented here:
- `EtsyExecutionPolicy` recognizes READ, DRAFT, and PUBLISH boundaries.
- The policy fails closed while the global external safety lock is active.
- DRAFT additionally requires the effective `etsy_draft` switch and explicit human approval.
- PUBLISH additionally requires the effective `etsy_publish` switch and explicit human approval.
- Unknown operations fail closed.
- No Etsy HTTP request, OAuth mutation, draft creation, publishing, media upload, webhook activation, schedule, or worker is implemented by this sub-batch.

This is intentionally a safety foundation only. A later adapter sub-batch must add official Etsy API integration, operation records with NOT_SENT/SENT/CONFIRMED_SUCCESS/CONFIRMED_FAILURE/UNKNOWN/RECONCILIATION/RECONCILED semantics, idempotency, reconciliation-before-retry, shop/account scoping, audit evidence, and separate publish approval. External automation remains OFF until separately deployed, certified, and explicitly activated.
