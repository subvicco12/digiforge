# Representative offline portal browser acceptance

Blueprint §§20,32 require mobile/tablet usability. Chromium using actual locally rendered WordPress views reproduced 390px horizontal overflow to 2,971px. The grid sidebar's default `min-width:auto` contributed its navigation's intrinsic minimum width. A one-variable diagnostic `min-width:0` reduced document width to390px without hiding content; navigation remains independently scrollable.

Several policy fields and the shop selector were19–21px high because core wp-admin input styles are not loaded in this frontend. Scoped portal inputs/selects/textareas/buttons now have minimum44px height and bounded width. Hidden inputs and native checkbox/radio inputs are excluded. No approval, POST handler, execution setting or external gate is changed.

`tests/browser/PortalBrowserFixtureTest.php` renders AI Budget, Research, Businesses and System from a disposable WordPress database and authenticated local test user, preserving STOP ALL. `tests/browser/portal-layout.cjs` tests all four views at390/768/1280px. It blocks all requests and form submissions, checks document overflow, navigation/control heights and budget/template input plus keyboard field navigation. The fixture directory is private scratch data, not a committed deliverable; generated local nonces/user information are not published.

Initial browser regression:73 failures across12 combinations. Fixed:12 combinations passed, zero network requests. Both original-main and integrated template-cap rendered fixtures were tested. The fixed sidebar behavior and control styles remain frontend-only.

Local unit validation:1,152 tests /7,340 assertions; legacy suites, configured PHPStan/PHPCS, fixture syntax and diff checks passed. The original-main browser fixture test passed1 test /10 assertions. Independent code review found no important issues. Final exact-head Engineering & Safety Audit is required; its configured WordPress suite remains unchanged.

This evidence covers representative offline layout and field/keyboard interaction. It does not certify every portal route, server-side form submission, full operator usability, deployed theme compatibility or production behavior. Finance1147 and its recovery workspace remain untouched. STOP ALL ON, external lock ON, automation OFF; no production deployment, provider operations or catalog promotion.

## Reproduction

Use a disposable WordPress test database configured by the existing test installer, with PHP/Composer quality tools and Playwright/Chromium available:

```sh
mkdir -p work/portal-fixture
DF_PORTAL_BROWSER_FIXTURE_DIR="$PWD/work/portal-fixture" WP_TESTS_DIR=/path/to/disposable/tests/phpunit vendor/bin/phpunit -c phpunit.wordpress.xml.dist tests/browser/PortalBrowserFixtureTest.php
node tests/browser/portal-layout.cjs "$PWD/work/portal-fixture"
```

`DF_BROWSER_EXECUTABLE` can select the locally installed Chromium binary. Sandboxing stays enabled by default. A disposable managed root container that cannot launch Chromium's sandbox can explicitly set `DF_BROWSER_NO_SANDBOX=1` for this blocked-network local test process; no CI/runtime policy is changed.
