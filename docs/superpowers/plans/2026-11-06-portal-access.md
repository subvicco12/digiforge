# DigiForge Portal Access Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox syntax for tracking.

**Goal:** Implement Issue1257 without changing WordPress accounts or signed authority.
**Architecture:** Audited restrictive profiles layer over existing native ACL; WP authentication plus generation-bound DigiForge sessions; shortcode/template composition preserves installed portal.
**Tech Stack:** PHP8.3, WordPress, MariaDB/InnoDB, existing PHPUnit/Actions.
**Spec:** docs/superpowers/specs/2026-11-06-portal-access-design.md

## Global Constraints

- Production baseline1.0.103.2; no production operations.
- STOP ALL ON; external safety lock ON; automation OFF; RELEASE HOLD.
- No Ed25519 bypass, email-based owner grants, WordPress user/role/capability changes, credentials in logs, supplier calls or external invitation delivery.
- Existing accounts must satisfy native ordinary-capability prerequisites; managed profiles cannot elevate them.

## Review Focus

- Deleted/corrupt profile or event evidence must not resurrect a disabled user.
- Invitation acceptance after owner expiry, revocation or role change must deny.
- Viewer cannot mutate through POST, HEAD, method overrides or unsafe GET routes.
- Disable/re-enable cannot revive prior DigiForge sessions; WP sessions remain untouched.
- Login/reset responses and redirects must resist enumeration, CSRF, open redirects and cache leakage.

### Task1: Audited profiles, owner checks and invitations
**Files:** Create includes/PortalAccess/{Policy,Store,Owner,Invitations,Session}.php; tests/wordpress/PortalAccessTest.php.
**Interfaces:** Policy::caps(array modules):array; Store::read(int actor):array|WP_Error; Store::change(int actor,array next,int revision,string request):array|WP_Error; Owner::require(string shop):true|WP_Error; Invitations::{create,accept}; Session::{start,valid}.
- [ ] Write failing behavioral tests for absent owner, role ceilings, scoped profiles, lifecycle integrity, invite expiry/replay, subject mismatch and generation invalidation.
- [ ] Run tests RED in disposable WP fixture, implement minimal classes, rerun GREEN; commit.

### Task2: Authentication, account UI and management
**Files:** Create includes/PortalAccess/{Authentication,Feature,Panel}.php, assets/portal-access.css/js; tests/wordpress/PortalAuthenticationTest.php.
**Interfaces:** Feature::register():void registers early; Authentication::process(array input):array|WP_Error uses WP signon/nonces/throttle; Panel::render():string requires current signed owner; existing native ShopAccessPanel enrollment reused via rendered access view.
- [ ] Write failing login/logout/safe-redirect/owner-denial tests; implement dedicated login/reset links, nonce continue/logout, account bar and management forms; all privileged actions reauthorize and audit.
- [ ] Run focused suite GREEN; commit.

### Task3: Enforcement, preservation and release evidence
**Files:** Create includes/PortalAccess/Guard.php; edit includes/Security/ShopAccess.php with restrictive profile admission; edit digiforge.php only version and feature registration; new package builder and acceptance report.
**Interfaces:** Guard::{rest,action,shortcode}; Store::permits actor/shop/cap read checks do not replace native ACL; Guard Viewer writes deny before callbacks.
- [ ] Test disablement across REST/admin-post/portal, Viewer alternate writes and cross-shop denial; implement exact route guards and managed session checks.
- [ ] Run PHP lint, project unit/WordPress/security suites, exact-ZIP regression, preservation comparison; obtain fresh reviewer and resolve important defects.
- [ ] Commit/push dedicated branch, open one draft PR, run source CI; build complete pinned-lineage ZIP and report digest/migration impact/remaining enrollment gates.
