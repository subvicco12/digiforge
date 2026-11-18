# Shop 2 real HTTP buyer-to-held acceptance

Runs the existing application through a private, released WordPress installation.
No application response is mocked. The browser obtains native WordPress cookies,
REST nonces and an operator portal session through the actual email-code flow.
The fixture mail sink retains the code locally; no email is sent.

Required existing development dependencies: PHP/mysqli, disposable loopback
MariaDB, Composer test dependencies, released WordPress core with built assets,
its matching PHPUnit library, Playwright/Chromium and the existing offline artwork
processor. This harness does not install or activate anything on a hosted site.

```sh
DF_LOCAL_BROWSER_DISPOSABLE=1 \
DF_BROWSER_CORE=/path/to/released-wordpress \
WP_TESTS_DIR=/path/to/matching/tests/phpunit \
DF_BROWSER_TEST_CONFIG=/path/to/private/wp-tests-config.php \
DF_BROWSER_EXECUTABLE=/path/to/chromium \
DF_SHOP2_BROWSER_EVIDENCE=/fresh/evidence/directory/result.json \
node tests/browser/shop2-buyer-held.cjs
```

The configuration must name a database containing `tests`, with a TCP loopback
host. A random `bxxx_` prefix must be absent before setup. Only that prefix is
dropped after success. Failed fixtures and private diagnostics remain outside the
repository. Never upload their configuration, cookies, authority files or HTML.
The optional evidence directory must exist and have fresh screenshot paths.
`DF_BROWSER_PHP` chooses PHP; `DF_BROWSER_NO_SANDBOX=1` is for restricted disposable
containers. `DF_SHOP2_BROWSER_WIDTHS` may select unique members of `390,768,1280`;
the default requires all three. The 390 context enables mobile/touch emulation.

Each viewport exercises real buyer authentication and nonce denial, crop/upload,
separate operator input/layout/render review, exact PNG preview parity, buyer
digital preference, explicit plan review/approval, BLOCKED intent and held-job
creation. CLI inspection validates committed state using the actual browser-issued
operator cookies. Later viewport revisions invalidate earlier previews and held
plans. The last journey revokes access and verifies buyer/held denial. One-use
operator confirmations are replayed and must deny a second effect. Cross-shop
held preparation is denied; snapshots remain immutable. Existing independent
crash/concurrency tests are reused, rather than duplicated here.

Start/end hashes must match for runtime and harness bytes; every requested
viewport must finish. Results distinguish the HEAD runtime from an uncommitted
test harness. CI's generated, untracked `composer.lock` is separately fingerprinted
and must remain unchanged; tracked manifest/lock edits and untracked application
files still deny acceptance. Screenshots contain disposable fixture data only. The existing
native focused workflow runs this harness and retains its sanitized receipts.
Operator confirmation failures report only the fixed action label, HTTP status
and bounded server alert, never form values, cookies or raw HTML. Confirmation
navigation has a separate two-minute browser deadline; application lease,
currentness, authorization and replay deadlines remain unchanged. A denial or
missing success link still fails acceptance.

This is bounded acceptance evidence. Supplier/catalog observations and buyer
assignment are disposable local fixtures, **not verified Etsy buyer identity**.
It does not qualify all editor engines, physical devices, physical print quality,
native allocation/CAS, issuer/witness, sender/custody or production execution.
Four-photo reorder evidence remains the separate source-matched Lane A receipt.
Full S2-ART-14/S2-ORD-06 promotion requires independent original-criterion review.
STOP ALL/external lock stay ON; automation OFF; RELEASE HOLD; no external calls.
