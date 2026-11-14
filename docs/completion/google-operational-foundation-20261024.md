# Google operational foundation — 24 October 2026

Baseline: `b4c5997d06be824a22e6c796571a7c982dea5942`, draft #1241/#1242, audit #4790 PASS. This increment preserves that admission implementation unchanged. Finance excluded. No live Google calls, account changes, credential use, production/staging writes, merges, deployment or activation occurred.

## Implemented service boundary

- `DriveGoogleOAuth`: exact HTTPS callback/client binding, session-hashed ten-minute one-use state, S256 PKCE, encrypted verifier and server-only client/access/refresh custody using existing CredentialVault keys. The service never provisions a master key. Exact `drive.file` scope, strict token expiry, refresh claims, account checks via the token's `about.user.permissionId`, account-switch denial and generation rotation. A crashed/ambiguous code exchange cannot repeat; a refresh timeout remains UNKNOWN and requires local revocation/new separately authorized consent rather than blind retry. Local revocation clears ciphertext before any optional remote attempt; remote revocation under STOP ALL stays UNKNOWN.
- `DriveGoogleAuthority`: server-only issuance binds installation/environment/actor/shop/folder/product/version/revision/source and current account/connection generation; at most 900 seconds and14 attempts. It commits the exact record through INSERT/CAS before use, reusing DriveGrantJournal quota/replay/revocation protections. A review receipt or an unissued caller array is insufficient.
- `DriveGoogleDispatcher`: bounded canonical codec, trusted current record before credential retrieval and send; source hash, durable file/checkpoint dependencies, original-byte readback and native-version identity. Logical create claims survive new grant nonces, plan IDs and account refresh; empty/ambiguous reconciliation never permits another POST. Stale/corrupt/failed persistence and uncertain responses remain blocked/UNKNOWN. OBSERVED means a bounded synthetic wire observation, not source/artwork/application/commercial acceptance.
- `DriveWordPressGoogleGate`: current explicit Digital shop assignment/capability, source custody and installation/environment checks. A separately configured expiring Google-specific server authorization AND existing unlocked/armed Product Development controls AND committed uncached safety values are mandatory. No such Google authorization was installed. Other provider/local authority cannot supply it.
- `DriveWordPressGoogleHttp`: fixed Google origins and method/path allowlists, TLS verification, no redirects/cookies/streaming, bounded request/response, transient bearer header and sanitized failures. No automatic retries. No logger receives raw OAuth/provider bodies or credentials.
- `DriveWordPressGrantStore`: extended only to four Google namespaces, absent-record creation via unique INSERT and existing-record updates via CAS. Reuses the separate-autocommit RecoveryDispatchLedger; no schema change.

These are internal composable services, not a production activation. Existing `DriveArtifactTransfer::execute()` still denies; no REST callback/UI/queue/default transport was wired. That is intentional while separate operational authorization is absent, but end-to-end installed Google execution is NOT claimed complete. Secure server configuration, registered callback, genuine account consent and authorized folder visibility remain external setup/acceptance dependencies. An existing folder ID alone does not establish `drive.file` access. No ChatGPT connector token is used or accepted as backend custody.

## Verification at commit preparation

- Operational unit tests14/169 assertions PASS. Combined Google suite51/319 PASS.
- Three legacy suites PASS; configured PHPStan/style PASS; new core OAuth/authority/dispatcher PHPStan level5 PASS. Authority/clock reads marked impure to preserve repeated current-state checks.
- Full local unit suite1794/9494, one existing environment error: `OfflineCutoutPipelineTest::testPipelineRequiresExplicitSubjectAndNeverCertifiesSemanticHead` (offline worker not configured), existing skip1. Not a local full-suite PASS. No unrelated cutout gate weakened.
- New hosted WordPress DB tests cover real unique insertion, stale writes, revocation and a locked real gate/transport with zero HTTP calls. First exact-head audit #4791 on09ec46b ran hosted unit1794/9500 and WordPress1408/23602 (existing skips1/2), catalog42/690, scheduler4/75 and credential/security checks successfully, then failed at the outbound HTTP allowlist because the new sender was not admitted. The follow-up preserves the global deny, admits only the exact sender line/path, pins guard/TLS/bounds/origins and adds three executing scanner regressions (including rogue paths, lookalike filenames, duplicate calls and missing guards). Regression suite3 PASS; final exact-head audit is pending at follow-up preparation. No check skipped or broadly excluded.
- No illustrated source-admission tests or stable-editor tests were repeated locally.

## Product evidence reconciliation

See `google-operational-acceptance-20261024.json`. Original corrected Letter/A4 DOCX hashes remain `0a9fabc469d217827ef50f645324c5bbda34f897d969092d21582034eaf10168` and `41170901605d7148ecb61c8ae32e3c6ab1ba9f1095a16dfb3a60831fe274a56c`. The embedded PNG in each is `e56c7d8b90f319810c375ea5140d121c7b70427f8e9144f54f5198ac541cc061` (1600×680). Existing source-bound technical evidence remains unchanged.

Fresh `pdfimages -list` observations on unchanged exact Letter/A4 exports find five images with masks, pages1/2/4/7/9. Pages2/7/9 have resized JPEG images, so byte/pixel equality is not established; no universal visual-parity threshold invented. Rendering/visual review and final exact-file owner approval remain separate. Prior stable LibreOffice25.2.3.2 evidence is reused. Word, Google Docs and ordinary-buyer interactive acceptance remain UNKNOWN. Brand KINETIQ MATRIX DESIGNS stays separate from DigiCraftifyGoods. Historical Product25 unchanged.

## Acceptance — no criteria promoted

Authoritative `docs/engineering/original-source/distinct-nonfinance-acceptance.tsv` remains unchanged:246/268 implementation candidates,22 mandatory non-Finance UNVERIFIED. Existing ten policy approvals are preserved; no reapproval requested. Google synthetic service coverage closes an implementation gap, not authentic production authority.

| Exact criterion | Status | Remaining evidence/action |
|---|---|---|
| BP-seam-panel-render | UNVERIFIED | Supply exact authentic criterion-specific source records, completeness and independent reviews; resolve remaining measurement eligibility/AI benefit rules where needed. Do not reapprove the ten already approved policy principles or infer certification from synthetic tests. |
| BP-ai-value-routing | UNVERIFIED | Supply exact authentic criterion-specific source records, completeness and independent reviews; resolve remaining measurement eligibility/AI benefit rules where needed. Do not reapprove the ten already approved policy principles or infer certification from synthetic tests. |
| BP-template-qa-spelling | UNVERIFIED | Supply exact authentic criterion-specific source records, completeness and independent reviews; resolve remaining measurement eligibility/AI benefit rules where needed. Do not reapprove the ten already approved policy principles or infer certification from synthetic tests. |
| BP-printify-shop-scope | UNVERIFIED | OWNER/EXTERNAL: supply authentic environment-bound provider account/shop/integration evidence. DF-OWN-002 synthetic cross-transport checks implemented; authentic evidence and installed-site acceptance remain required. Keep release hold and all safety locks. |
| BP-distinct-provider-shops | UNVERIFIED | Collect authentic authorized evidence identified in unresolved_classification; offline fixtures cannot certify it. |
| BP-etsy-shop-identity | UNVERIFIED | Collect authentic authorized evidence identified in unresolved_classification; offline fixtures cannot certify it. |
| BP-portal-4-provider-connections | UNVERIFIED | OWNER/EXTERNAL: supply authentic environment-bound provider account/shop/integration evidence. DF-OWN-002 synthetic cross-transport checks implemented; authentic evidence and installed-site acceptance remain required. Keep release hold and all safety locks. |
| BP-portal-7-mockups | UNVERIFIED | OWNER/EXTERNAL: supply licensed mockup assets with customer-display/derivative rights and reviewed exact variant/panel/UV/seam geometry. Synthetic acceptance is not commercial certification. |
| BP-portal-7-supplier-readiness | UNVERIFIED | Supply exact authentic criterion-specific source records, completeness and independent reviews; resolve remaining measurement eligibility/AI benefit rules where needed. Do not reapprove the ten already approved policy principles or infer certification from synthetic tests. |
| BP-portal-11-production-authorization | UNVERIFIED | OWNER/EXTERNAL: provide the separately approved production action/scope/expiry/revocation authority and authentic provider/supplier evidence when operational certification is authorized. Do not activate or execute during local acceptance. |
| BP-portal-17-conversion | UNVERIFIED | Supply exact authentic criterion-specific source records, completeness and independent reviews; resolve remaining measurement eligibility/AI benefit rules where needed. Do not reapprove the ten already approved policy principles or infer certification from synthetic tests. |
| BP-portal-17-defect-quality | UNVERIFIED | Supply exact authentic criterion-specific source records, completeness and independent reviews; resolve remaining measurement eligibility/AI benefit rules where needed. Do not reapprove the ten already approved policy principles or infer certification from synthetic tests. |
| BP-portal-17-supplier-performance | UNVERIFIED | Supply exact authentic criterion-specific source records, completeness and independent reviews; resolve remaining measurement eligibility/AI benefit rules where needed. Do not reapprove the ten already approved policy principles or infer certification from synthetic tests. |
| BP-portal-17-product-performance | UNVERIFIED | Supply exact authentic criterion-specific source records, completeness and independent reviews; resolve remaining measurement eligibility/AI benefit rules where needed. Do not reapprove the ten already approved policy principles or infer certification from synthetic tests. |
| BP-portal-17-shop-performance | UNVERIFIED | Supply exact authentic criterion-specific source records, completeness and independent reviews; resolve remaining measurement eligibility/AI benefit rules where needed. Do not reapprove the ten already approved policy principles or infer certification from synthetic tests. |
| BP-live-portal-normal-work | UNVERIFIED | UNVERIFIED COMPOSITE: authentic ownership, licensed operator mockups and production authority remain unverified. Installed-site authenticated workflow/certification evidence needs separate authorized environment access. Prepared-order and bounded policy leaves already verified; do not repeat their implementation. |
| BP-secret-no-chat | UNVERIFIED | Collect authentic authorized evidence identified in unresolved_classification; offline fixtures cannot certify it. |
| BP-quality-feedback | UNVERIFIED | Supply exact authentic criterion-specific source records, completeness and independent reviews; resolve remaining measurement eligibility/AI benefit rules where needed. Do not reapprove the ten already approved policy principles or infer certification from synthetic tests. |
| BP-digital-semantic-content | UNVERIFIED | Collect authentic authorized evidence identified in unresolved_classification; offline fixtures cannot certify it. |
| BP-digital-semantic-visual | UNVERIFIED | Collect authentic authorized evidence identified in unresolved_classification; offline fixtures cannot certify it. |
| BP-digital-semantic-ip | UNVERIFIED | Collect authentic authorized evidence identified in unresolved_classification; offline fixtures cannot certify it. |
| BP-digital-semantic-policy | UNVERIFIED | Collect authentic authorized evidence identified in unresolved_classification; offline fixtures cannot certify it. |

## Smallest next actions

1. Finish exact-file visual/content/IP/policy decisions on the existing v0.5 hashes; settle artwork export comparison/review, not another revision by default. Existing limited direction/provenance/license receipts are not replaced or upgraded.
2. When separately authorized, configure the backend OAuth client/callback and the expiring Google-specific execution scope through server custody, then verify the genuine account and folder with the least-privilege app. No manual source upload is requested; originals already exist. Installed callback/queue orchestration is the remaining engineering integration boundary before any live end-to-end claim.
3. Retain authentic fixed-provider POD geometry/rights/human review, ownership records, attributable metric completeness and separately authorized installed-site desktop/mobile acceptance. Finance remains excluded.

Engineering foundation: tested offline increment; first audit HTTP-allowlist failure preserved, focused boundary correction complete, final exact-head audit pending at follow-up preparation. Operational Google/complete MVP/commercial release: NO-GO. STOP ALL ON; external lock ON; automation OFF; RELEASE HOLD.

## Primary protocol references (documentation only)

- https://developers.google.com/identity/protocols/oauth2/web-server
- https://developers.google.com/workspace/drive/api/reference/rest/v3/about/get
- https://developers.google.com/workspace/drive/api/reference/rest/v3/User

Retrieved24October2026. Documentation is not authenticated account/provider evidence.
