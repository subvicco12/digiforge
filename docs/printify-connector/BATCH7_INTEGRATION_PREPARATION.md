# Batch 7 protected panel and native-port integration preparation

Baseline companion: a7dca70902f755c38f04aa7793040a8253a3056d.
Core reference independently rechecked: 702a4d078555ac9cb75fd115357db5387281c74a. No newer native core implementation was available at preparation time. Native conformance is PENDING, not passed. Core runtime and PR1264 are unchanged.

## Scope

The existing DF_NATIVE_CORE_PORT_1 registry and source pin remain unchanged. ProtectedPanel now renders scoped operation/review state, review revision, product/order versions, blockers and required evidence. No form, mutation action, public route, scheduler or dispatch controls are introduced. Empty queues explicitly do not prove readiness. Failure output suppresses operation details.

Read-only inspect(scope,actor,context,subject,reviewRevision) obtains a newly authorized snapshot and returns exactly one matching current scoped subject/revision. Missing, ambiguous, stale, revoked and incompatible evidence denies. Repeated inspection does not write review state, resolve UNKNOWN, retry, authorize or dispatch. Native authority is checked by the existing adapter and again before returning the snapshot. A response represents evidence at the time checked, never an ongoing execution permit.

A behavioral test exposed stale review-row recovery epochs under a newer queue checkpoint. The panel now rejects the entire snapshot rather than rendering inconsistent recovery evidence. A correct self-hash cannot establish freshness. Existing semantic, scope and approval validation is preserved.

## Test boundaries

Synthetic native fixtures exercise missing/incompatible manifest, wrong schema digest, revoked/stale/expired authority, cross-shop rows, private fields, missing journal, stale checkpoints, exact selection, UNKNOWN/conflicting operations, revocation during snapshot and repeated read-only inspection. Test instrumentation counts writes (mutation exchange, publication authorization, review changes, workflow/fulfillment persistence and webhook-secret access); all panel workflows must leave this counter zero. Real WordPress route/cron registration and STOP options are checked in the isolated test environment. Existing Batch6 WordPress nonce/session tests remain mandatory.

These tests are companion conformance tests against synthetic ports. They do not establish native core transaction durability, provider connectivity, exclusive credential custody or recovery safety. No policy exception is added or activated.

## Native integration gate

When a new core SHA exists: independently verify PR1264 head and ancestry, inspect its NativeBridge factory and method-to-authoritative-repository mapping, compare against the canonical Batch6 schema and semantic handoff, and review the genuine new source pin before composing a candidate. Run all existing adapter/negative suites against the actual core implementation in isolation, including concurrent reservations, journals, receipts, revocation and emergency drain. Missing implementations or persistence guarantees remain blockers; do not substitute fixture results or a manifest self-claim. Keep caller-selected source pins, credentials, URLs and authority overrides unavailable.

STOP ALL ON; external lock ON; automation OFF; RELEASE HOLD. No installation, merge, provider request, credential use, publication, order submission or historical replay. Production/staging are untouched.
