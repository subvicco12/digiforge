# Issue1257 — DigiForge-only portal authentication/access acceptance

Development is implemented on `feat/portal-auth-access-1257`, stacked on approved consumer `6e521719d925c75f27aed5ccdd47048cc1e5a15d`. Draft PR: https://github.com/subvicco12/digiforge/pull/1258, base PR1253 branch. Main `3758d1385f7d4a62947248822c1fe1c89ec9818e` and unrelated PR1256 were inspected, not integrated or changed. No production observation or installation is claimed.

## Installation artifact and preservation

Complete installed-lineage **DigiForge1.0.103.3**, `digiforge-1.0.103.3-portal-access-update.zip`, 3,083,301 bytes. SHA-256 `71d44b649bc3e034eefdf827bc268278cc0b4f745776884a98dedcad72408a83`.

The builder pins the complete1.0.103.2 input `800a29e7bca92bcf76804747d4a23f15329b38606649d570df25e1f109fe7677`, checks committed runtime/builder bytes and every packaged member, and preserves all692 input files. New total704: **689 byte-identical, three reviewed edits,12 additions, zero deletions**. The complete preservation manifest and test receipts accompany the private workspace artifact. The ordinary source-CI ZIP is a different source lineage and is not this installation artifact. The composite source/builder commit is bound by the artifact sidecar; the latest PR/source-CI head is recorded separately.

Existing runtime edits:

- `digiforge.php`:1.0.103.3 header/runtime version and one feature-registration hook.
- `includes/Security/ShopAccess.php`: additional restrictive managed-profile/current-session admission and managed grantor scope checks; native cryptographic/grant checks remain.
- `includes/Security/ShopAccessAdminGuard.php`: exact list of five new handlers, which independently enforce authentication, nonce and signed owner scope. No wildcard admission.

Additions:

- `includes/PortalAccess/Authentication.php`, `Feature.php`, `Guard.php`, `Invitations.php`, `Limiter.php`, `Owner.php`, `Panel.php`, `Policy.php`, `Session.php`, `Store.php`.
- `assets/portal-access.css`, `assets/portal-access.js`.

Portal.php, existing hooks, ShopAccessAuthority/Policy/Repository, core migrations, provider/catalog implementations and unrelated features are unchanged. The original shared dependency manifest remains unmodified; an exact-hash reverse-patch receipt documents the owner-approved restrictive consumer overlay. No blanket HTTP or cryptographic exception is added. No production SQL, credentials, test signers or private authority files are packaged or committed.

## Implemented behavior

- Dedicated `?df_auth=login` screen, WordPress signon/password recovery/nonce logout, safe same-origin return, no-cache headers, browser-bound guest CSRF challenge and bounded login counters (five per IP/login and30 per IP in15 minutes). Existing WP authentication hooks remain active. Account/logout navigation wraps both restricted and full portal screens.
- Intended primary email identifies the existing account to verify. Independently signed OWNER, exact current WP actor, reviewed approval, existing ordinary capabilities and explicit audited self-enrollment establish Primary Owner. No email/UID/administrator bootstrap. Recovery uses the original independently trusted signer/envelope, not generated identity.
- Owner dashboard offers existing-account invitations, Admin/Operator/Viewer roles, exact shop/module selections, enable/disable/revoke and scoped permission history. WordPress users, roles, capabilities and unrelated sessions are never edited. Accounts missing native ordinary capabilities/role eligibility are denied rather than elevated.
- Invitations are private, subject-bound, expiring, single-use handoffs. Only credential hashes are stored. No email is dispatched. Pending actors can authenticate to the acceptance screen without gaining a DigiForge session; fragment handoff stays in tab-scoped storage, is removed after populating the acceptance form, and has manual-code fallback.
- Profiles/events/audits use existing InnoDB options/audit tables and transactions. A committed disabled profile precedes any new native grant. Failed or interrupted work stays disabled. Missing/corrupt snapshot, lifecycle/audit mismatch, stale revision, expired/revoked authority or consumed invitation denies.
- Generation-bound HMAC DigiForge cookies are additional to native WP sessions. Disablement, revocation and privilege changes invalidate old DigiForge cookies; re-enablement cannot revive them. WP sessions are retained. Existing native assignments remain independently required.
- Viewer writes, HEAD, unknown reads and supplier-execution reads deny on the server; known module reads also require the corresponding profile module, including the legacy overview/products fallback. REST namespace case cannot bypass managed profile checks. Native backend/shop guards remain in force. Viewer forms are hidden for clarity, with server denial as the authority.
- Owner profiles cannot be reassigned/demoted/disabled through ordinary management. The first-owner host trust root remains an external prerequisite. Per-shop grants expire within independently signed/native limits and are not silently renewed. History is bounded at1000 validated revisions; capacity is checked before one/two-phase mutations, with no silent truncation or invalid revision1001 commit. Dashboard/history windows are explicitly bounded.

## Evidence

- Project unit suite:1,271 tests /7,668 assertions PASS; legacy foundation, Product Factory and Digital Factory scripts PASS.
- Full source WordPress suite:315 tests /2,680 assertions PASS, two existing skips. Source tests use synthetic disposable accounts/signers; no production keys/data are provisioned.
- New focused tests against actual extracted candidate:30 tests /128 assertions PASS.
- Existing installed-lineage exact-package WordPress tests:26 tests /204 assertions PASS, one obsolete main-only pipeline skip.
- Size-guide contracts:6 tests /26 assertions PASS; interface checks42 PASS; Drive boundary21 PASS, zero transport calls; inherited pinned HTTP/secret policy PASS.
- Actual extracted package:517 PHP files linted PASS; all704 member hashes/path/type checks PASS; no unexpected private files, executable modes or high-confidence secret findings.
- PHPStan and configured coding standard PASS. Offline suite PASS; the local PHP-only container lacks Node, so the tab-state test was also executed RED/GREEN against actual JavaScript in isolated V8. Hosted source CI runs its native Node VM test. This is a browser-state model plus real WordPress authentication/acceptance tests, not a claimed end-to-end production browser run.
- Fresh automated security review identified four important findings; real regressions reproduced them and one fix pass resolved all four: uppercase REST returned201 before correction; omitted products scope admitted reads; pending login lost invitation destination; revision1001 corrupted readiness. No minor review findings were deferred. This is not independent human certification.
- Exact-head source Engineering & Safety Audit is recorded on PR1258 (initial passing run42167093138). Final-head evidence is posted separately after any receipt-only commit. Hosted exact-combined-ZIP audit has NOT RUN; sourceCI is not its substitute.
- Private owner-export clone booted the exact package with no DDL, markers legacy23/global25/provider1 unchanged; all129 exported table counts/checksums unchanged. This reuses the protected November6 export and does not establish current production freshness or full-site recovery.

## Migration and installation/enrollment gates

**No new schema migration, table, database marker or WordPress account/role mutation.** Existing schema25 lineage/provider1 and the prior monotonic component fix are preserved. Authorized app use writes profile/invitation/audit options and ordinary WP authentication session records as expected; these are not executed on production here.

Engineering qualification: PASS with documented skips. Production installation: **BLOCKED pending separate acceptance/approval**, current-source/schema drift confirmation and a coordinated full-site backup with tested isolated restoration. Existing Hostinger connection lacks the required CloudLinux backup/export route; no unverified backup or SQL-only restore is promoted to recovery PASS. No merge, deployment or production install occurred.

Owner enrollment remains BLOCKED until the trusted host operator independently verifies the actual existing account, restores/provisions approved current Ed25519 public trust key and signed OWNER envelope outside public/plugin storage, and confirms exact actor/shop/capability/approval/expiry scope. The app cannot create that prerequisite. Existing WordPress user#1 matches the intended email only in the earlier supplied snapshot; that is not current production verification. After separately approved installation and host provisioning, authenticate through DigiForge, review the signed per-shop scope and explicitly confirm owner enrollment. No live provider operation, Finance activation or external automation follows from enrollment.

STOP ALL ON, external safety lock ON, automation OFF, RELEASE HOLD remain required. New ZIP/source SHA/test receipts do not authorize production action.
