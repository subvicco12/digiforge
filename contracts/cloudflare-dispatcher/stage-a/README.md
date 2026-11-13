# Stage A — synthetic Cloudflare qualification candidate

**STAGE_A_FACTORY_IMPLEMENTED / OFFLINE_VERIFIED / HOSTED_NOT_EXECUTED / BILLING_UNVERIFIED / RECOVERY_BLOCKED / CREDENTIAL_CUSTODY_BLOCKED / LIVE_NOT_AUTHORIZED.**

This is a source deployment candidate, not a WordPress update, real provider adapter, independently administered authority, recovery certificate or permission to create resources. Production WordPress and all489 existing runtime hashes are unchanged.

## Reviewed resource contract

One proposed Worker `digiforge-dispatcher-qualification`; one SQLite-backed DO class `DigiForgeQualificationState`; namespace binding `DF_QUAL_STATE`; one fixed object name `digiforge-stage-a-singleton-v1`. No routes, custom domain, workers.dev/preview URLs, credential, production binding, external provider, automatic retry, queue or alarm.

Hard admission bounds:1000 synthetic operation attempts including private calls/internal transitions; three private admissions per rolling60seconds (including receipt reads); at most3600seconds of testing; SQLite `databaseSize` at most10,000,000bytes with100,000bytes reserved headroom; synthetic sessions1–10slots and at most300seconds, independently enforced by a real wall-clock case cap as well as fixture expiry; fixture body at most1,048,576bytes. A twenty-second driver deadline returns a bounded UNKNOWN error and does not retry.

These are work-admission limits for the one approved object/namespace/window, not a Cloudflare billing firewall. Rejected platform requests can still be charged. A resource administrator restoring/replacing the namespace can rewind its budgets. Such operations are outside the approved run and require separate review; same-account storage is not an independent recovery witness. Managed PITR-log/billing overhead is not included in SQLite `databaseSize`.

## Source layout

- `entrypoint.mjs`: actual `cloudflare:workers` WorkerEntrypoint and DurableObject exports; public HTTP always503. Only private `qualify({intent})` and zero-argument `status()` RPCs exist.
- `factory.mjs`: exact environment/mode/object/window validation, source-level egress seal, immutable runner; no vault/provider/business runtime import.
- `state.mjs`: actual SQLite/CAS adapter reusing the existing SqliteState; durable run budget and per-case namespaces. Fault controls are internal fixture capabilities, never RPC methods.
- `suite.mjs`: fixed24-case sequence; no caller-selected case, fault, SQL or command. Each private invocation advances one case. Repeated intent returns its prior committed result without advancing. Interrupted RUNNING work fences on rehydration.
- `driver.mjs`: one private service-binding invocation, bounded response validation, no URL/headers or automatic retry. Intent identifiers are synthetic correlation values, not authentication secrets; none appears in reports or URLs.
- `offline-driver.mjs`: separate Node24 CLI with a disposable file-backed SQLite database and explicitly synthetic time. Not part of the deployable closure.
- `build_bundle.py`: standard-library deterministic ZIP_STORED source packaging, frozen Git source SHA, closed runtime module graph, source/member hashes and disabled config. No deployment subprocess or network operation.
- `dependencies.lock.json`, `deploy-tools/package-lock.json`: no npm runtime dependency; future Wrangler4.150.0 and transitive dependencies pinned. Registry metadata only was retrieved; Wrangler was not installed or executed.

## Invocation confinement and authentication

Cloudflare service bindings call RPC without a public URL. Authentication/authorization is possession of an independently approved account-level service-binding capability, not a public bearer header. The Worker does not parse HTTP bodies or dispatch HTTP paths. POST/GET/PUT/PATCH/DELETE all receive503, in both selected and unselected modes. Unknown environment bindings, production secrets, missing state binding, wrong mode or unsealable global APIs deny before case execution.

**Remaining external caller gate:** no approved caller Worker currently exists in the observed account. The account metadata connector has not demonstrated private RPC invocation. A deployable source factory is not proof that ChatGPT Work can invoke it. The owner must approve an existing supported private caller or a separate caller-resource proposal; this candidate does not silently add a second Worker or a public proxy. A future caller can bind:

```json
{"services":[{"binding":"DF_STAGE_A","service":"digiforge-dispatcher-qualification"}]}
```

The caller's own source, authentication, provisioning, costs and binding require separate approval. The driver calls only `DF_STAGE_A.qualify({intent})`. It must compare returned build ID with the frozen source manifest. A configured hash is a binding assertion, not hardware/runtime attestation.

## No-egress evidence and limit

Before active qualification, fetch/WebSocket/XMLHttpRequest/EventSource/WebTransport are replaced with immutable denial functions. If that cannot be done, the factory fails closed. Runtime imports are a closed seven-module graph plus the built-in `cloudflare:workers` module; Node compatibility, socket imports, dynamic import/eval, provider transport and credential paths are absent. The only fetch expression is the fixed `.invalid` negative fixture executed after sealing. No caller supplies a URL.

This is frozen-source confinement and API-shape evidence, not a Cloudflare network firewall against an administrator deploying different code. Hosted compatibility of global sealing must be observed; failure leaves the run disabled. The deployable directory contains only the seven checked modules and config, excluding test loaders, original sender implementations, driver CLI and deployment tooling.

## Persistent execution and fault interpretation

RunBudget and per-case state/events persist synchronously and atomically. Provider/network calls never occur inside SQL transactions; Stage A has no provider port at all. Internal failed-state/journal writes roll back; lost ACK injection happens only after a mutating transaction commits. Consumed slots/nonces remain spent. Concurrent reservation uses CAS and does not retry.

One active case at a time. The object can rehydrate completed case boundaries but never automatically runs the next case. Rehydrating an interrupted RUNNING case records recovery-required and refuses further execution. Per-case restart fences its previous synthetic session. There is no public or RPC reset, fault injection, recovery/cleanup or rearm method.

The24 cases cover commit, state/journal rollback, lost ACK, duplicate/concurrent slots, nonce rehydration, repeated emergency generation, pending drain, restart, storage failure, namespace/build/epoch mismatch, stale signed synthetic workload certificate, expired synthetic owner grant, quotas, synthetic timeout/interruption, modeled subordinate rewind, all-case-root counterexample, body bounds and denied egress.

**RECOVERY_BLOCKED:** the rewind case restores only private fixture scope state; it is not a real Cloudflare PITR test or an independent authority. Its `COUNTEREXAMPLE_REPRODUCED` result means the desired recovery invariant FAILED. The original104 full-dispatcher tests—including the actual all-trusted-store counterexample and three PHP parity tests—remain enabled. Stage A does not claim to host the full independent service/root architecture. Stage B remains separate.

## Offline reproduction

From an exact source checkout (or the bundle's `source/` directory):

```sh
node contracts/cloudflare-dispatcher/stage-a/offline-driver.mjs
DIGIFORGE_TEST_PHP=php node --test --test-isolation=none contracts/cloudflare-dispatcher/test/*.test.mjs
```

The CLI performs all24 cases against a temporary file-backed SQLite database, advances a SYNTHETIC clock by21seconds per case and removes the database afterward. This is not hosted timing/durability. The PHP executable and two source dependencies are included/referenced for parity; do not silently omit those three tests when PHP is unavailable.

Build twice from a clean committed checkout:

```sh
python3 contracts/cloudflare-dispatcher/stage-a/build_bundle.py --output /tmp/stage-a-source.zip
python3 contracts/cloudflare-dispatcher/stage-a/build_bundle.py --output /tmp/stage-a-source-repeat.zip
cmp /tmp/stage-a-source.zip /tmp/stage-a-source-repeat.zip
```

ZIP timestamps/modes/order are fixed, compression is ZIP_STORED and source bytes come from Git blobs. No timestamps, keys, account credentials or machine paths enter the source artifact. The source manifest hashes every member except itself; its outer ZIP checksum closes that boundary. Any source mismatch is rejected.

## Future provisioning operations — NOT authorized or executed

1. Obtain separate approval for the frozen artifact, exact target account/Worker/class, window, private caller, account entitlement and cost. Billing403 evidence remains unresolved; do not retry or infer Free eligibility from empty resources.
2. Verify target-name absence or exact disposable ownership through the approved authenticated control plane. Abort on unrelated existing resources. Use a fresh artifact directory without `.env`, `.dev.vars`, extra modules or generated `.wrangler/deploy/config.json` redirections. Never import the repository-wide read/write token into this Worker.
3. Only after operational approval, install the pinned CLI using the lock (no scripts) from the extracted bundle root:

```sh
npm ci --prefix source/contracts/cloudflare-dispatcher/stage-a/deploy-tools --ignore-scripts --no-audit --no-fund
node source/contracts/cloudflare-dispatcher/stage-a/deploy-tools/node_modules/wrangler/bin/wrangler.js deploy --config deployment/wrangler.json
```

This future command uploads seven plain ESM modules, creates the proposed Worker and applies `stage-a-synthetic-v1` with `new_sqlite_classes:["DigiForgeQualificationState"]`, binding only `DF_QUAL_STATE`. It can incur charges and is NOT approved now. Authentication must use an independently approved operator session; do not expose, create or transfer credentials through this package. Config is `DISABLED`, windows0, workers.dev/previews OFF and routes empty. Source maps/logging are OFF.
4. Read back actual deployment ID, source/module hashes, class/namespace ID, absence of public routes/previews/secret bindings and account entitlement. If any condition is unknown, stop. Do not treat no-bundle upload as hosted qualification.
5. Separately approve the private caller binding and selected run window. Render only DF_QUAL_MODE=STAGE_A_SYNTHETIC_V1, numeric start/end at most3600seconds apart and the manifest's build ID. Freeze actual object/namespace/build identifiers; no automatic namespace replacement. Re-review the rendered config and deploy/update only after approval.
6. The approved caller explicitly advances the fixed sequence at at most three calls/minute (e.g.21second spacing). There is no retry/poll loop in the supplied driver. On UNKNOWN, inspect the bounded persisted status; do not manufacture a new intent to repeat uncertain work. Archive the24 receipts, reported storage/operation counts and measured hosted CPU/row metrics. Stop on unexpected behavior, budget exhaustion, changed build or interrupted recovery state.
7. Do not arm provider operations, add a credential, install WordPress or reinterpret successful platform probes as recovery/custody qualification. Any unsupported private invocation path leaves deployment/testing blocked rather than enabling an HTTP proxy.

## Future cleanup — separate destructive approval required

After archiving evidence, stop the approved caller and return mode to DISABLED. Expiry denies execution but does not delete resources or end billing. Inspect only the approved disposable Worker/namespace IDs. Prepare/review a cleanup migration with a new tag and `deleted_classes:["DigiForgeQualificationState"]`, removing the DO export and binding from that disposable Worker; apply it only with explicit approval. Then delete ONLY `digiforge-dispatcher-qualification` using the supported Worker deletion operation (`wrangler delete --name digiforge-dispatcher-qualification`). Never delete other classes/namespaces or run generic delete-all scripts. Verify the exact target is absent and archive sanitized cleanup receipts. No cleanup command or alarm is executed by this artifact.

## Usage and cost assumptions

At most24 case executions plus bounded receipt reads; logical work cap1000; at most3 private admissions/minute; at most60minutes; at most10MB reported SQLite data. Node-measured counts/bytes are in the qualification receipt and are not hosted CPU/billing evidence. A conservative single128MB object active for the entire60minutes is460.8GB-s, not a measured charge. Managed storage/PITR and any caller add overhead.

Preparation evidence conditionally cited Free eligibility/$0 within allocations and a public Paid minimum$5/account/month. Actual entitlement, remaining quotas, Billing Read, write grants and caller costs are UNKNOWN. No paid upgrade, purchase or spend cap is authorized. If a paid change is required, stop for a separate decision.

## Evidence and source references

- Preparation: https://drive.google.com/file/d/1sGFiofbhtyNp9QBs-X9HXj4F8oTeYpOY/view
- Resource proposal: https://drive.google.com/file/d/1Msr-OpycOv8ehakX-88oCC9QIMJNgnRh/view
- RPC: https://developers.cloudflare.com/workers/runtime-apis/bindings/service-bindings/rpc/
- SQLite databaseSize/transactionSync: https://developers.cloudflare.com/durable-objects/api/sqlite-storage-api/
- Disabled previews/routes/no-bundle/module rules: https://developers.cloudflare.com/workers/wrangler/configuration/

Official docs and public npm metadata were read; no Cloudflare account operation, deployment, credential use or Printify request occurred. STOP ALL/external safety lock ON; automation OFF; activation unauthorized; RELEASE HOLD.
