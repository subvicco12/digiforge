# Private recorded-source research HTTP acceptance

This harness runs real WordPress authentication cookies, core `admin-post.php`, nonces, the registered `CommercialResearchPanel::submit` handler, redirect and persisted state. Only fixture provisioning and observation use private loopback endpoints. The final server-only `OfflineResearchTransport` is injected in configuration; no POST parameter can select it. Sources, concepts, quotes and token counts are synthetic, explicitly labelled, and do not establish commercial or live-provider acceptance.

Prerequisites match the existing image-generation-auth harness: PHP 8.3+, mysqli, local disposable MariaDB, the pinned WordPress test source, Playwright 1.62.1 and Chromium. No provider credentials are needed. The bounded evidence root is `/workspace/lane-a-ai-research-http-20261117`, database `ai_research_http_20261117`, and socket `db.sock` within that private root. The initial prefix is `raut19_`; initialization refuses populated prefixes. For a new rehearsal, version the private harness/root or choose a fresh explicitly allowed prefix and preserve previous receipts. Never run against a production database.

1. Set `PORTAL_CONTROLS_EVIDENCE` to that root and link `db.sock` to the disposable server socket.
2. Run `php tests/browser/research-execution-auth/init.php` once.
3. Start `php -d post_max_size=12M -d upload_max_filesize=3M -S 127.0.0.1:18856 tests/browser/research-execution-auth/router.php`.
4. With Playwright available in `NODE_PATH`, run `node tests/browser/research-execution-auth/acceptance.cjs`.

Setup may take up to three minutes; it creates a synthetic signed shop actor and policy, not an owner session. The browser checks enqueue, uploaded exact quote, durable wait/poll, strict three-concept intake and selection, stale replay, wrong-shop, nonce and revocation denial. Each real POST records an outbound WordPress HTTP counter, which must remain zero. WordPress fixture installation can attempt unrelated core update requests; all are blocked, recorded separately and never claimed as successful external actions.

Keep `core/wp-config.php`, authority keys/files, database files, cookies and state private. Share only sanitized request counts, test logs, screenshot, source and hash receipts. Synthetic selection/review never grants genuine owner, rights, supplier or release approval. STOP ALL, external lock, automation OFF and RELEASE HOLD remain in force.
