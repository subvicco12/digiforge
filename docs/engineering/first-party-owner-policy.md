# Default first-party platform OWNER

The owner's 26 October 2026 decision designates **WordPress user 1,
subvicco12@gmail.com**. No other account becomes default OWNER. Protected
first-party configuration replaces mandatory external signing for this identity.
The existing separately signed delegate mechanism remains available for operators.

## Identity root

After a separately authorized installation, protected PHP configuration loaded
before DigiForge may define `DIGIFORGE_FIRST_PARTY_OWNER` as an array containing
exactly these fields:

| Field | Meaning |
| --- | --- |
| version | Integer 1 |
| account_sha256 | FirstPartyOwnerPolicy::fingerprint of the verified database account |
| installation_sha256 | SHA-256 of `site_url('/') . '|' . $wpdb->prefix` |
| email_verified_at | Positive UTC epoch of genuine first-party mailbox verification |
| expires_at | Future UTC epoch; at most 365 days after mailbox verification |

The account fingerprint covers exact ID, login, email, registration timestamp and
password hash. It is a binding, not a password or a signature. Never export account
password hashes to chat/logs. Do not fabricate mailbox verification from a matching
profile email: WordPress core profile email alone supplies no verified-email claim.
The protected configuration asserts that verification was actually performed.
There is no auto-pinning, user-meta assertion, option setter, web self-grant or
configuration-writing route. This engineering batch does not establish the live
account fingerprint or verification timestamp and does not install configuration.

Every authority read queries fresh account identity, clears cached WP user data
and checks the current administrator role plus all 15 pre-existing DigiForge
capabilities at both role and user level. No capabilities are added by this policy.
Password resets intentionally invalidate the binding. Account recovery/rotation
requires authorized first-party re-verification and a new protected pin; existing
grants become stale when the root hash changes. The root has bounded expiry and
can be revoked immediately by invalidating/removing its configuration. Removing it
returns to the legacy signed configuration; remove obsolete signed OWNER records
as part of any authorized migration so they cannot reappear on rollback.

When first-party configuration is present, malformed/expired/mismatched native
identity fails closed. Signed OWNER records cannot provide fallback. Valid signed
DELEGATE records compose with the four native OWNER scopes; a malformed optional
signed envelope contributes no delegates. Native OWNER does not depend on Sodium.
Signed delegates retain their existing Sodium and provisioning requirements.

## Website and audit

The owner uses Operations Console → Shop access and operator assignments and
explicitly enrolls selected capabilities for each desired canonical shop. All 15
capabilities are offered for self-enrollment in all four scopes. Existing POST
nonce, exact approval hash, confirmation, current role/capabilities, bounded expiry,
revision lock, request replay journal, transaction and immutable audit checks stay
authoritative. Reads never enroll. Operator assignment remains a separate action;
browser Finance GRANT stays denied. No generic administrator authorization path
was added. Existing Finance operational exclusion is unchanged.

STOP ALL ON, external lock ON, automation OFF and RELEASE HOLD remain independent.
Standard POD and Jewelry remain future/inactive shops. Local ACL enrollment cannot
activate shops, authorize Google/provider calls, publish, place orders or deploy.

## Acceptance boundary

Pure-policy and native WordPress synthetic tests establish software behavior.
They cannot verify the installed account/mailbox, protected configuration custody,
installed-site UX, artifact identity or live provider acceptance. No production
configuration or deployment is authorized by this document.
