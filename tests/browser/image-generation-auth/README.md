# Private authenticated image-panel acceptance

This is a focused private fixture, not an owner session, production login journey, live-provider test, or whole-portal acceptance. It creates a separate `iaut*_` WordPress table prefix on the portal-controls private MariaDB socket. Only GET `/setup` creates a synthetic actor, signed ACL, approved specification, disabled image policy and offline raster candidate, then issues genuine WordPress auth/session cookies and redirects.

POST `/wp-admin/admin-post.php` loads the actual WordPress core admin-post handler and the existing registered `CommercialResearchPanel::submit`. The router does not set the actor on POST, call `process`, replace nonce checks, register a nopriv mutation, or supply credentials/custody from a request. WordPress derives identity from its actual signed auth cookies/session tokens. Existing `check_admin_referer`, scope admission and redirects run unchanged.

The private generated configuration disables cron, notification email and unrelated core admin background-update callbacks. A rejecting `pre_http_request` filter counts all attempted WP HTTP requests. Suppressing those background callbacks is part of the fixture boundary; production runtime files are unchanged. Diagnostics `/status` and `/counts`, plus synthetic capability revocation `/revoke`, are private test-only GET paths requiring the actual fixture cookie. Revocation removes the synthetic actor's AI capability; it does not claim a signed-grant-revocation test.

Requirements: the exclusive private MariaDB socket `/workspace/lane-a-portal-controls-20261116/db.sock`, WordPress core under `.onboarding/wordpress-develop/src`, PHP under `.onboarding/bin/php`, Playwright and Chromium.

```sh
PORTAL_CONTROLS_EVIDENCE=/workspace/lane-a-portal-controls-20261116 \
IMAGE_AUTH_PREFIX=iaut2_ \
/workspace/.onboarding/bin/php tests/browser/image-generation-auth/init.php

PORTAL_CONTROLS_EVIDENCE=/workspace/lane-a-portal-controls-20261116 \
/workspace/.onboarding/bin/php -d post_max_size=12M -d upload_max_filesize=3M \
-S 127.0.0.1:18775 tests/browser/image-generation-auth/router.php

NODE_PATH=/opt/codex/cua_node/lib/node_modules \
PORTAL_CONTROLS_EVIDENCE=/workspace/lane-a-portal-controls-20261116 \
node tests/browser/image-generation-auth/acceptance.cjs
```

This is deliberately one-shot: initialization refuses an existing prefix without changing the active configuration; setup refuses replacing existing fixture state. Preserve prior evidence and select a fresh allowed prefix for a later run. Do not reuse these synthetic fixture auth keys or trust material elsewhere. The evidence directory contains synthetic sessions and private fixture state; it is not served as static content.

Current evidence: `/workspace/lane-a-portal-controls-20261116/image-auth/REPORT.md`.
