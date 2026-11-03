# Authenticated local browser operator acceptance

Base: audited #1196 `9fab24a5ecd0538ea18e70cde3b79b6151643eab`, audit #4726 PASS; retains #1195 `02a67a61b7247457546a4530d26ca971d3645ec5` / #4725 and #1194 `135a3dc2bfd6602dc620ca6ea79f1072c4c3f024` / #4721 ancestry. Heads/checks were reverified read-only. No prior PR branch, production implementation, Finance, migration or CI workflow is changed by this test batch.

The new `tests/browser/authenticated-local-portal.cjs` uses a copied released WordPress core, native login cookies and REST nonces, real loopback HTTP, existing signed shop-assignment fixtures and synthetic product lineage. Unlike earlier `setContent` browser fixtures, requests reach the actual WordPress portal/REST handlers. Runtime instructions and the exact safety/coverage boundaries are in `tests/browser/authenticated-local/README.md`.

## Measured evidence

`authenticated-local-browser-evidence.json` records the completed focused run: **64 checks**, 390/1280 viewports, Chromium 154.0.8037.57, Node v24.19.0, native successful/failed login, authenticated product reads, missing nonce and foreign-product denials, unassigned/low-role/deferred/global-shop denials, keyboard shop navigation and mobile overflow checks. Browser egress and server HTTP guards report zero external requests. All **44** snapshotted non-finance business tables remain identical; no listing publication, order creation, provider mutation or product-row write occurred. Successful fixture DB tables and private temporary login/configuration files are cleaned up.

Existing additive startup schema maintenance is separately counted: **255 fixture-bootstrap CREATE/ALTER statements** before `init`. The guard does not permit those operations during operator handlers. This is explicitly not proof of a zero-DDL startup, nor production migration certification. The fixture updater/avatar/admin-toolbar settings remove incidental WordPress.org/Gravatar requests; production settings are untouched.

Expired, consumed and receipt-fault consent requests are denied through authenticated HTTP while STOP ALL/external lock remain ON and automation OFF. No confirmation or candidate result is available. **The receipt-fault query itself is not reached because the safety lock denies first.** The unchanged `PortalResearchRunAcceptanceTest` provides the separate detailed expiry/replay/receipt-read-failure branch evidence (including #1196's three regressions). The standard hosted audit executes those native tests, plus the new two offline disposable-entrypoint/syntax regressions. Hosted CI does not install/run Playwright; only the separately recorded focused run proves this browser coverage.

## Conservative acceptance mapping

| Existing criterion | New local evidence | Classification change |
| --- | --- | --- |
| DERIVED-BP-shop-permissions | Native actor sessions, role/shop/REST denial | None; existing IMPLEMENTED leaf strengthened |
| BP-portal-2-stop-all | Locked consent UI and authenticated denial; unchanged controls | None |
| BP-portal-3-selected-shop-view-isolation | Own/foreign product lineage, shop selector and native REST GET | None |
| BP-portal-5-manual-research-runs | Locked browser submissions plus unchanged detailed native lifecycle regressions | None; no browser-success claim |
| BP-live-portal-normal-work | Real authenticated loopback read-only desktop/mobile journeys | **UNVERIFIED retained**; not a full installed-site operator/commercial journey |

Ledger counts remain 246 IMPLEMENTED / 268 distinct mandatory non-finance criteria (91.79% bounded candidate implementation coverage), with 22 UNVERIFIED: 11 owner approval, seven external, four composite. Source records are not distinct requirements. Authentic ownership, supplier/variant/shipping/geometry/rights evidence, production authority and installed-site acceptance remain unverified. Standard POD/Jewelry remain operationally deferred; only synthetic denial fixtures mention them.

Release hold and dependency order #1194 → #1195 → #1196 → this draft remain. Next authorized engineering may add independently demonstrated operator defects or broader local browser journeys, while supplier and installed-site certification require separate authentic evidence/authorization. No merge or activation is implied by local browser or hosted audit success.
