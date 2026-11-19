# Google Drive execution grant — sprint boundary (24 Oct 2026)

Scope: isolated draft branch `feat/google-drive-scoped-grant-contract-20261024`. No Finance, provider writes, production activation or release.

## Verified code

`DriveExecutionGrant::validate()` is a pure offline predicate. It checks exact installation/environment/Digital-shop/folder/product/version/revision/source/plan identity, actor, issuer/connection syntax, stage allowlist, 15-minute maximum grant duration, nonce syntax and bounded remaining attempts. Unit tests cover exact matches, revocation, expiry, consumed nonce, mismatched identity and invalid quota. **A true result is not authorization to dispatch.**

## Missing security boundaries — must not be inferred from this predicate

1. Trusted issuer registry and cryptographic/durable grant issuance bound to an approved Google account/connection; caller-supplied issuer text is not proof.
2. Atomic nonce and attempt consumption with durable claims, expiry/revocation and crash-safe reconciliation.
3. Server-side Google credential custody, OAuth state/PKCE callback, refresh and revocation, exact account/folder proof.
4. Guarded HTTP executor that checks STOP ALL, external lock, release authority, current shop ownership and exact grant *before credential retrieval, before each attempt and before result acceptance*.
5. Exact original DOCX evidence, SHA-256, Google edit/export and nine-page Letter/A4 visual acceptance.

## Safe integration sequence

Implement and test issuer/nonce/credential lifecycle independently first. Wire the executor only after authority is demonstrably durable and rechecked. Keep `DriveArtifactTransfer::execute()` fail-closed until separate owner authorization for live Google access. Preserve UNKNOWN create outcomes; reconcile by exact folder/marker and never blindly repeat POST. No connector token reuse, no broad Drive scope, no exception to STOP ALL.

## Acceptance

Current grant validator is offline-only. Neither live Google upload/conversion nor Wedding Guide v0.5 acceptance is established. No live pilot, credential installation, merge or deployment is authorized. RELEASE HOLD / NO-GO.
