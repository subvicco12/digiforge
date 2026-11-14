# Google callback and durable queue integration — 25 October 2026

Baseline `d5a79aab5485c223390bc4d08242a276b0796e30`, existing draft #1241/#1242. Exact baseline audit #4792/run40116165230 independently verified completed/success. Continue the same branch; no new PR, merge, deployment, production/staging write, live Google request, real credential handling, Etsy publication or Printify production. Finance excluded.

## Implementation

- `DriveGoogleWordPress`: three exact authenticated admin-post handlers (start/callback/queue) and one dedicated Action Scheduler hook. Boot registers callbacks only; no OAuth, grant issuance or scheduling on construction. Start/queue require POST, current connection/settings capability, current Digital product assignment and WordPress nonce. Callback requires authenticated WordPress user, GET and the same logged-in session token bound to the one-use saved OAuth state. Callback plan/account/PKCE comes from stored state; caller account/source/grant values are not accepted. Success redirects only to a fixed local destination with a non-secret plan reference, never OAuth code/state/provider error text. No anonymous handler or global redirect-host exemption.
- Server composition uses only `DIGIFORGE_GOOGLE_CLIENT_ID` and encrypted `DIGIFORGE_GOOGLE_CLIENT_SECRET_CIPHERTEXT`; absent/invalid configuration denies. Client custody context remains `google:oauth-client:` + SHA256(clientID + NUL + exact callback URL). Existing key must exist; no key provision, secret entry UI or connector-token reuse. Registered callback URL: `admin_url('admin-post.php?action=digiforge_google_oauth_callback','https')`. OAuth constructor was minimally extended for this exact action query on `/wp-admin/admin-post.php`; arbitrary/duplicate/redirect queries still reject. This was a reproduced integration incompatibility, not a rewrite of the audited OAuth flow.
- `DriveGoogleWorkflow`: single selected state per immutable source-bound plan; callback establishes a connection but never grants or queues automatically. Explicit owner queue action invokes the audited issuer. Seven bounded stage jobs use stored Google-specific grant/account/generation and existing dispatcher. Final state is **TECHNICAL_QA_COMPLETE**, with commercial/production authorization false. Missing authority/configuration blocks; expired/revoked/mismatched grants cannot dispatch.
- `DriveWordPressQueueJobs`/`DriveQueueJobs`: existing JobRepository idempotency, dependency checks, leases and state transitions plus official Action Scheduler. Payloads contain transfer/stage/Digital reference only. Durable CAS maps bind job IDs to stored transfer/actor/grant; worker identity comes only from that map, current shop/source rights are rechecked, and original WordPress actor is restored in `finally`. The general Scheduler remains inert. Unavailable scheduler/configuration and malformed/foreign jobs do not send HTTP.
- Durable workflow claims are recorded before sending. Interrupted attempts become HUMAN_REVIEW/UNKNOWN; never automatically retry creates. Explicit `reconcile=original_upload|native_conversion` schedules only the existing audited reconciliation GET. All previous logical create claims survive new grants.
- Explicit `refresh=connection` refreshes custody only; never grants/schedules. Explicit `renew=grant` checks current owner/account, rejects active RUNNING jobs, revokes the old issued grant and creates a fresh bounded grant while retaining old attempts, source, folder, job identities and UNKNOWN/create claims. This enables reviewed read-only reconciliation after grant expiry; no silent extension of authority. The existing separately expiring Google server permit and global safety controls remain mandatory for every external attempt.
- `DriveWordPressArtifacts`: protected immutable PDF export custody and original-source hash, then existing full ExternalDocumentEvidence QA after native-final/version checks. PDF/source hashes and page counts are compared; no visual/artwork/licensing approval inferred. No original Wedding source files or historical Product25 records altered.

The exact Google admin actions are added to ShopAccessAdminGuard's reviewed scoped entry points; each resolves current stored Digital ownership independently. There is no broad future-action exemption, generic scheduler enablement, new schema or Finance modification.

## Typed owner/backend entry contract (not authorization to execute)

| Action | Request | Required bound inputs | Result |
|---|---|---|---|
| `digiforge_google_oauth_start` | POST + nonce of same action | `revision_id` decimal, `request_id`64 lowercase hex; product/version/source read from stored revision | One-use OAuth authorization URL, only after source/Google/safety gates |
| `digiforge_google_oauth_callback` | Google's authenticated-session GET | Saved state + code, same WordPress session; exact callback/client/PKCE | CONNECTED only; no queued execution |
| `digiforge_google_queue` | POST + nonce of same action | Stored `plan_id`64hex | Explicit grant + seven-stage queue |
| same queue action | POST + nonce | `plan_id`, `refresh=connection` | Refresh custody only |
| same queue action | POST + nonce | `plan_id`, `renew=grant` | Revoke old grant, issue new bounded grant; no job automatically started |
| same queue action | POST + nonce | `plan_id`, `reconcile=original_upload|native_conversion` | Read-only recovery of an existing uncertain create |

No public secret-management endpoint, activation UI or manual source upload is added. New portal button/form design and Google-specific Action Scheduler dashboard cards are outside this slice: the existing U3 ScheduledTaskReadModel remains U3-only; ordinary durable job attention still uses the existing jobs table. Do not claim installed portal acceptance from registered hooks.

## Test evidence at commit preparation

- New queue/callback unit11tests/106assertions PASS. Combined Google62/425 PASS.
- Full local1805/9600: existing cutout-worker environment error in `OfflineCutoutPipelineTest::testPipelineRequiresExplicitSubjectAndNeverCertifiesSemanticHead`, existing skip1. Not a local full-suite PASS; unrelated gate unchanged.
- Three legacy suites, configured PHPStan/style and new workflow PHPStan level5 PASS. Existing outbound boundary regression3 PASS; no additional HTTP sender introduced.
- New hosted WordPress tests exercise real authenticated cookie/session callback, generic safe redirect, actual queue DB/idempotency/dependency/lease ownership, actor restoration and held/missing-config paths with synthetic transport only. Additional official Action Scheduler test proves dedicated hook dispatch remains blocked without Google config; no genuine provider claims.
- Mandatory final exact-head audit pending at commit preparation. Final receipt belongs on #1209/PR after actual completion. No old admission/editor/full-audit result reconstructed.

## Acceptance and minimum external actions

All22 exact IDs in `google-callback-queue-20261025.json` remain UNVERIFIED; authoritative TSV unchanged.246/268 implementation candidates is not production acceptance. Existing owner policy and limited provenance/license/direction decisions preserved without reapproval.

Reused artwork evidence: actual v0.5 exact PDF images on pages1/4 match source PNG; pages2/7/9 are resampled/JPEG. Whole-design visual fidelity remains UNKNOWN. Prior stable LibreOffice25.2.3.2 evidence is reused; ordinary-buyer interactive, Word and Google Docs compatibility remain UNKNOWN. No new Wedding revision or artistic certification.

1. Only after separate explicit operational authorization: install backend OAuth client ciphertext/config and exact registered HTTPS callback, current Google server permit and genuine account/folder visibility; validate real callback, conversion, export and recovery in an authorized environment. No current live execution is permitted. Existing originals need no manual re-upload.
2. Final exact-file content/visual/IP/policy and buyer-usability decisions remain required. Limited previous approvals do not become final approval.
3. Authentic POD geometry/rights/human review, account/shop identities, attributable metric completeness and separately authorized installed-site desktop/mobile evidence remain external/process gates. Do not restart research or infer supplier certification.

STOP ALL ON; external lock ON; automation OFF; RELEASE HOLD. Operational provider acceptance and commercial release: NO-GO. Final hosted audit is evidence of engineering only, not permission to release.
