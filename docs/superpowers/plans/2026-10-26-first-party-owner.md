# First-party OWNER implementation and continuation

User-directed execution on the existing draft integration branch. Release hold,
STOP ALL, external lock, automation OFF and Finance exclusion remain binding.

## Design and decisions

Default OWNER is exclusively WP user 1 / subvicco12@gmail.com. Neither email nor
administrator status establishes identity. A protected PHP configuration constant
pins the account fingerprint and records first-party mailbox verification with a
bounded validity period and an installation binding. No web API can write it.
Account deletion/recreation, password reset, login/email/registration changes,
configuration revocation/expiry and removal of any existing capability invalidate
the root. Password rotation deliberately requires refreshing the protected pin.
This is ordinary first-party configuration, with no Ed25519 signer or ceremony.
No production pin is invented or installed during this engineering batch.

The existing signed authority mechanism remains compatible when first-party
configuration is absent. When configured, only this account supplies OWNER roots;
separately signed DELEGATE records remain usable. Invalid first-party configuration
fails closed and cannot fall back to another signed OWNER. Operator grants remain
separate, explicit, scoped, expiring and auditable. No direct authorize() bypass:
OWNER uses existing website enrollment, nonce, confirmation, revision lock, replay
and immutable audit lifecycle. All 15 capabilities and four canonical scopes are
available for enrollment, without activating future shops or external execution.

Alternatives rejected: administrator/email fallback violates identity requirements;
automatic option/user-meta self-pinning cannot distinguish an account replacement;
retaining mandatory external signatures contradicts the owner's decision.

## Execution

1. Write pure policy regressions for exact identity/config/schema/time/scope/pin
   and integration regressions for native enrollment and lifecycle revocation.
   Observe missing implementation failures before production code.
2. Implement FirstPartyOwnerPolicy with pure evaluation plus fresh database
   identity reads. Compose authority records at the existing root boundary.
   Preserve delegation and enrollment implementation; explain native status in panel.
3. Reconcile Blueprint/Master500 current ledger and develop a bounded offline
   launch-preparation deliverable using actual source data, with no certification.
4. Run focused/full exact-head Engineering & Safety Audit. Preserve failures and
   exact commit/run evidence. Review diff and restrictions before reporting.

Review focus: stale WP user cache, cross-installation replay, replacement account,
capability drift, malformed protected config, invalid root fallback, changing
authority hash, grant revocation and audit failure. External evidence remains
UNVERIFIED until actually observed.
