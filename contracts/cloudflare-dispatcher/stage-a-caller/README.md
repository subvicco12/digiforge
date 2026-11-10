# Stage A private caller — offline proposal

NOT DEPLOYED. No operator, IAM grant, hosted service binding or billing entitlement has been qualified. Installation, provisioning and execution require separate approval. STOP ALL, external lock, automation OFF and RELEASE HOLD remain unchanged.

## Boundary and initiation

The default Worker HTTP interface always returns 503. There is no public reset, fault control, SQL, URL proxy or test execution endpoint. A manually created Cloudflare Workflow instance invokes the fixed caller Durable Object, which invokes the existing Stage A Worker through `DF_STAGE_A`. A private service binding is confinement, not operator authentication.

The proposed authenticated initiation is the official Cloudflare management API:
`POST /accounts/{account_id}/workflows/digiforge-stage-a-manual/instances`.
`operator-plan.mjs` produces a plan only: `instance_id` is bound to the request ID, and `params` is a JSON-encoded string. It never sends a request or receives a token. Current official API documentation lists **Workers Scripts Write** as an accepted permission. This is broader than workflow-only invocation; a suitably restricted principal and approval are external blockers. Cloudflare IAM authentication does not establish human DigiForge owner identity. Approve the exact operator and approval digest independently before any execution. ChatGPT connector management POST support is unverified.

Inputs select only ADVANCE_ONE, READ_RECEIPT or RECONCILE. They cannot select a fault, case, URL, reset or SQL. Fresh requests expire within 120 seconds, bind the literal approved target build and approval digest, and bind their Workflow instance identity. No runtime management token or provider credential is accepted.

## Durable intent and UNKNOWN

Before any target RPC, the SQLite journal commits a unique intent and expected next case. A one-use dispatch latch commits before qualification. Duplicate concurrent initiations cannot dispatch twice or overwrite the owning invocation. Workflow step retries are zero; no application retries, alarms, schedules or automatic rearm exist.

Preflight status and terminal receipts must equal target build `b0d29d9f7b362c420b5c56143315ebd1e0cde92d7f00fe4bdff3f46a9836ca1e`. Receipt schemas and case order are independently checked. The target's seven-module graph is unchanged. The shared receipt validator extraction changes only the offline driver, outside that graph.

Timeout, response loss, uncertain commit acknowledgement, malformed evidence, restart with an unresolved latch and unavailable storage are UNKNOWN. Every UNKNOWN blocks further advances. No replacement intent is created. READ_RECEIPT/RECONCILE can recover an already committed caller receipt without target dispatch. The frozen target has no intent-correlated receipt lookup: aggregate counts, times or matching case names cannot resolve a lost target response. Such UNKNOWN remains unresolved. Workflow platform completion is not test PASS. The all-root rollback counterexample remains RECOVERY_BLOCKED.

## Limits and resources

Caller: 60-minute window, at most 1,000 admissions and 128 distinct control requests, three admissions per rolling minute, 24 sequential cases, 1 MB SQLite storage ceiling, 64 KiB journal. Two target admissions per advance (status and qualify); at least 60 seconds between completed advances. Operator should wait 61 seconds and initiate each case explicitly. Each RPC times out after 20 seconds; Workflow step timeout is 50 seconds. The target retains its existing 1 MiB response, 10-operation session and synthetic-only limits. No real provider sender is imported.

This proposal adds **one Worker, one SQLite Durable Object namespace and one Workflow**. Together with the existing target: two Workers, two SQLite namespaces and one Workflow. No custom domains or production routes. Approximately 24 manual advance instances, 48 target RPCs and optional explicit receipt reads for one complete run; storage is bounded by the target 10 MB plus caller 1 MB ceilings. These are usage estimates, not billing guarantees. Account-specific Workflows/SQLite eligibility, pricing, free allowances and spending controls are unverified. No paid service is authorized.

## Future controlled procedure (not executed)

1. Independently review exact source SHA, both deployment manifests, hashes and frozen target build. Approve additional resource scope, operator identity, accepted broad IAM permission and cost cap.
2. Verify account-specific Workers/Workflows/SQLite entitlements and operator management API capability without exposing credentials. Provision only after separate authorization.
3. Review both disabled deployment configurations. Confirm no public routes, workers.dev, previews, schedules, alarms, queues, provider secrets or remote bindings. Confirm exact `DF_STAGE_A` service target and fixed caller namespace. Hosted cross-worker confinement is not established by offline mocks.
4. Separately approve a nonzero approval digest and one <=60-minute synthetic window. Enable only the explicit synthetic mode after authorization. Never supply Printify credentials.
5. Generate the exact manual operation plan with new 32-hex request/operation IDs and <=120-second lifetime. Review it, then use an independently authenticated approved control-plane session. Do not place tokens in params, files, chat or artifacts. No automatic retry of POST after acknowledgement loss.
6. Inspect the Workflow instance and minimized caller receipt. On response loss, issue a distinct READ_RECEIPT or RECONCILE request for the same operation, not a new ADVANCE. UNKNOWN requires stopping; case counts are not terminal evidence.
7. Proceed explicitly at >=61-second intervals only after COMPLETED validated receipt. Preserve COUNTEREXAMPLE_REPRODUCED as failure of recovery invariant.
8. End window, retain sanitized evidence, disable caller and target. Separately approve deletion of disposable resources; deletion is not recovery qualification. No production changes are included.

## Evidence boundaries

Node tests use real local SQLite and a Cloudflare SDK-shape fixture. They do not prove hosted Workflows identity, IAM isolation, service-binding confinement, durability, cost or deployment behavior. Same-account restoration can rewind caller state; this is not an independent recovery witness. Production bridge remains non-executable; credential custody and all-root recovery remain blocked.

Official contracts reviewed: https://developers.cloudflare.com/api/resources/workflows/subresources/instances/methods/create/ ; https://developers.cloudflare.com/workflows/build/trigger-workflows/ ; https://developers.cloudflare.com/workflows/build/sleeping-and-retrying/ . No account API calls were made.
