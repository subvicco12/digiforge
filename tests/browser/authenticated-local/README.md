# Disposable authenticated WordPress browser acceptance

This runner uses native `wp-login.php`, WordPress session cookies/nonces, registered portal and product REST routes, and real loopback HTTP. It does not load captured HTML with `setContent`. All actors, signed shop assignments, source lineage, products and consent records are synthetic. No licensed artwork, supplier mappings or production authority are asserted.

Prerequisites: the existing Composer dev dependencies, Playwright/Chromium browser runtime used by the other browser tests, PHP 8.3+, released WordPress core plus matching PHPUnit test library, and a disposable **loopback** database whose name contains `tests`. Never provide production or staging configuration. The runner refuses missing opt-in, non-loopback DB hosts, unsupported config templates and existing table prefixes. It creates a private temporary core/content copy and collision-checked short prefix; it never modifies the source core or supplied config.

```sh
export DF_LOCAL_BROWSER_DISPOSABLE=1
export DF_BROWSER_CORE=/path/to/released-wordpress
export WP_TESTS_DIR=/path/to/matched-wordpress-tests-library
export DF_BROWSER_TEST_CONFIG=/path/to/private/disposable/wp-tests-config.php
node tests/browser/authenticated-local-portal.cjs
```

PHP must be available on PATH for WordPress child processes. `DF_BROWSER_PHP` and `DF_BROWSER_EXECUTABLE` can select local executables. `DF_BROWSER_NO_SANDBOX=1` retains the existing browser-suite opt-in for restricted containers only. No production credentials are used. Random synthetic login material and signed fixture authority stay in a mode-0700 temporary directory, are never printed or committed, and are removed after successful verification. Failed private fixtures remain only for local diagnosis; `cleanup.php` requires the same explicit disposable context and validates its loopback DB/prefix before deleting that fixture's tables.

## Coverage and honest boundaries

- Anonymous sign-in gate, failed password, native successful cookie login, limited operator, unassigned actor, low-role denial.
- Desktop/mobile (1280/390), keyboard shop selector, own product lineage, foreign/deferred/global-shop denial, authenticated REST product GET, missing REST nonce and foreign product denial.
- Expired, previously consumed and receipt-fault synthetic consent submissions all remain denied under unchanged STOP ALL/external lock/automation OFF. Browser assertions prove the protected UI offers no confirmation and no result is created. **The lock dominates before downstream receipt-query evaluation.** This does not claim that a browser reached the receipt-fault branch or completed research. Reuse `PortalResearchRunAcceptanceTest` (including the three #1196 regressions) for the separately executed expiry/replay/receipt-read-failure branches; the mandatory audit runs that suite.
- Browser egress is same-origin and GET-only except native login and the two explicitly allowed research-consent actions. The local router independently restricts methods/routes, rejects non-loopback peers/hosts, and never exposes config/authority/actor files. Server-side WordPress HTTP is always denied and logged. Core updater/avatars/admin toolbar are disabled only in the disposable fixture to avoid incidental WordPress.org/Gravatar requests.
- All snapshotted non-finance business rows and safety settings must remain identical. Order, provider, listing and product data mutations are denied after setup; audit/session evidence is allowed. No order submission, publication, supplier mutation or production access is performed.
- Existing `PodSchema` startup runs additive `CREATE`/`ALTER` maintenance on each native boot. Only that **fixture startup DDL before `init`** is permitted and separately counted; this suite does not claim zero schema maintenance. Later DDL and all business-row mutations remain forbidden. No migration or safety implementation is changed.

The two offline entrypoint/syntax regressions run in the standard Python suite; the actual browser runner is a separate focused local check because the hosted audit does not install Playwright. A passing hosted audit alone must not be cited as execution of this browser suite. Installed-site, real provider, supplier, artwork, rights and production certification remain unverified.

Finance contract extension: native review-only export and unsupported-provider validation POSTs are explicitly allowlisted only for the existing active-shop finance page. The client and loopback router both retain method/origin/shop restrictions. The runner checks export JSON bytes/SHA, review-only flags, filter retention, 390/768/1280 layout and unsupported-source denial. No financial declaration, order, listing or provider mutation is permitted after setup. This remains disposable local acceptance, not production or authentic provider certification.
