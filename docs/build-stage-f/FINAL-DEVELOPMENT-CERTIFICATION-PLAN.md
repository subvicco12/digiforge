# Build Stage F — Complete Development & Final Release Certification

## Purpose
Complete DigiForge development in the repository and produce one tested production artifact. Intermediate repair builds are not production releases and are not to be manually deployed.

## Architectural baseline
DigiForge remains WordPress/PHP-native with WordPress/MySQL as operational source of truth and Action Scheduler as the asynchronous boundary. Existing modules and schemas are reused; implementation must not silently redefine the master architecture.

## Human gates
1. Opportunity Approval — should DigiForge make this product?
2. Product Approval — is the finished product good enough to sell?
3. Listing/Publish Approval — is this exact Etsy listing ready to go live?

No gate is automatically approved.

## Stage F workstreams
### F1 — Repository & runtime convergence
- Eliminate repository/package divergence.
- Verify U3 repair identities for production plans, every asset spec/revision, marketing assets and customer packages.
- Verify exact-run replay is idempotent and changed-run repair creates fresh controlled identities.
- Ensure bounded workers and recoverable failures.

### F2 — Digital Product Factory completion
- Approved opportunity → specification → actual product assets → marketing assets → package → deterministic QA → semantic QA → Product Review.
- Keep product assets separate from marketing assets.
- Validate dimensions, formats, languages, page/file inventory, filenames, links/QR where applicable, package integrity and provenance.

### F3 — POD Factory completion (externally locked)
- Product/design specification, print artwork, print-ready validation, mockups, provider configuration model, supplier/cost comparison, margin validation and QA.
- Printify/Gelato execution remains disabled.

### F4 — Listing Factory & Gate 3
- Generate title, description, tags, FAQs/instructions, metadata and price recommendation.
- Validate field limits, duplicate keywords, product/listing consistency, profitability and required evidence.
- Prepare Etsy draft package only behind controls; no publish without Gate 3.

### F5 — Operations foundations
- Validate order, fulfillment, finance, analytics and reconciliation paths with mocks/local fixtures only.
- No live marketplace mutation, fulfillment, refund/cancellation, banking or tax authority action.

### F6 — Approval Inbox & Dashboard
- Approval Inbox aggregates research, product, QA exception, listing/publish, personalization/POD/fulfillment exceptions.
- Dashboard remains summary/action oriented and surfaces queue/integration health, failures, blocked items, costs and reconciliation gaps.
- Verify desktop, tablet and mobile behavior.

### F7 — Failure/recovery certification
Test invalid data, duplicate/replayed idempotency keys, payload conflicts, expired leases, missing credentials, unauthorized access, malformed REST requests, unavailable providers, interrupted queues, migration failures, plugin-update recovery and STOP ALL behavior.

### F8 — Security & integration certification
- Credentials never hard-coded/logged/frontend exposed.
- Validate permissions/capabilities, nonces/auth boundaries, REST mutation guards, idempotency and auditability.
- Validate Etsy/Printify/Gelato/AI adapter architecture and sandbox/production separation without enabling external side effects.

### F9 — Release certification
Run repository tests and static/security checks available in the project, package-boundary checks, reproducible-build checks, PHP/JS syntax checks, migration/schema checks and workflow simulations. CI infrastructure failure must be distinguished from executed test failure.

### F10 — Final artifact
- Build one release candidate from the exact certified commit.
- Test the exact ZIP artifact, not a reconstructed equivalent.
- Record SHA-256 and manifest.
- Produce final production ZIP only after certification.
- Deployment remains a separate step with backup, smoke tests and controlled activation.

## Mandatory safety state during Stage F
Do not activate Etsy publishing, Printify, Gelato, live order/fulfillment automation or GST/tax actions. External actions remain fail-closed. Human approvals remain required at irreversible/high-risk boundaries.

## Certification exit criteria
Stage F is complete only when repository/runtime/package are converged; internal digital and POD workflows pass required simulations; deterministic and semantic QA fail closed; approval gates cannot be bypassed; replay/recovery behavior is verified; security scans find no release blocker; the exact final ZIP is reproducible and tested; release manifest/checksum/documentation are complete; and no external action was performed during certification.
