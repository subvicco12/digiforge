# Recovery readiness evidence reconciliation contract

This is a read-only investigation contract for isolated staging. It does not authorize recovery drills, backup creation, restoration, live file changes, provider actions or release activation.

## Distinct facts that must not be conflated

- A historical database backup may have been downloaded and checksum-verified without being retrievable **now** through the configured recovery channel.
- A database backup's existence does not prove it is compatible with the currently installed plugin package and runtime schema.
- An owner-reported plugin installation and a healthy runtime do not prove exact on-host file-tree identity when multiple packages share version `1.0.103`.
- A prior successful recovery drill cannot attest to a newer package unless the recorded evidence binds the exact package and database identifiers and satisfies freshness policy.

## Read-only evidence capture

1. Record UTC timestamp, environment identity, STOP ALL, durable external safety lock, automation state, effective switches, runtime schema and health. Stop if the environment differs from the isolated staging target.
2. Obtain the **actual installed** plugin file-tree digest or immutable installed manifest from an approved read-only on-host mechanism. Compare against the exact certified package manifest; do not infer identity from the version string.
3. Independently identify the backup's storage location, immutable identifier, checksum and current download/retrieval mechanism. Verify current retrievability through a read-only authorized download only; preserve redaction and access controls.
4. Compare the recovery readiness gate's input evidence and timestamps against historical evidence, and document which predicate fails. Do not change options to force a PASS.
5. Record the recovery drill's artifact bindings, age, environment and operator approval; classify stale/unbound evidence as REVIEW_REQUIRED.
6. Create a redacted evidence bundle with per-gate provenance and reviewer, then request **separate authorization** for any controlled recovery drill.

## Acceptance boundary

Mark the gate PASS only if current retrievability, exact compatible package identity, schema compatibility and required artifact-bound drill evidence are independently established. Missing, inaccessible or conflicting evidence remains REVIEW_REQUIRED.

No production deployment, Master 500 promotion, Etsy/POD action or STOP ALL/external-lock change is authorized by this document.
