# Inherited Drive HTTP client — narrowly scoped source-audit admission

## Reproduced failure and root cause

On the unchanged ZIP extraction, the original `External HTTP client scan` shell in `.github/workflows/digiforge-foundation-audit.yml` exited1:

```
includes/DigitalFactory/DriveWordPressGoogleHttp.php:13:        $response=wp_remote_request($url,$args);
Unexpected external HTTP client usage found outside audited integration clients
```

Its generic `grep -RniE` permits existing named audited transports but has no reviewed Drive classification. The entire original rule/allowlist and subsequent legacy safety assertions are retained byte-for-byte in `bin/artifact-audit/legacy-http-policy.sh` (SHA256204258a76fafdffb61c42a01c86fe35b2e93a5e8d4ad9ca69e9bd115fddad1a2). The source workflow now invokes the same `security.py` policy used against extracted artifacts. It does not maintain a separate weaker artifact-only classification.

Root cause established here is an inherited audit-classification gap. No new actual security defect was reproduced in the reviewed transport/production composition. This is not a claim of universal safety or proof against arbitrary malicious WordPress plugins.

## Reachability and trust boundary

Installed Core/Plugin.php registers DriveGoogleWordPress; register() installs three exact admin-post hooks and one job hook, without provider execution. Start/callback/queue/job handlers resolve server-side services. services() requires separately provisioned client ID and encrypted client secret, constructs one actual DriveWordPressGoogleGate, and injects that same gate into the OAuth/authority/dispatcher/HTTP composition. An optional composition closure is trusted server/test injection, not a request field.

Reachable HTTP exchange callers are DriveGoogleDispatcher::dispatch, DriveGoogleOAuth::account and formRaw (OAuth exchange/refresh/revoke). The dispatcher validates canonical source-bound plans, current authority, source digest, stage and durable attempt/operation identities, retrieves transient credentials, rechecks gate/authority, then calls the transport. OAuth uses session-bound consumed state, exact drive.file scope and guarded encrypted custody. The final Http::exchange calls valid() and the actual gate immediately before handing transient arguments to WordPress. No request parameter can select a transport URL or install a permissive gate in this production composition.

Gate allows() requires a separately provisioned Google execution authorization expiring within15 minutes and exact installation hash; Settings::safety_locked false, effective product development enabled, and uncached committed RecoveryDispatchLedger::googleExecutionAllowed true. The committed predicate requires stop_all=false, activation_authorized=true, automation_armed=true and product-development flags true. Ownership then rechecks actor/site/environment, signed Digital ShopAccess, settings capability, revision lineage and the exact local source file digest. Existing Settings::safety_locked is the externally_locked value exposed by DigiForge health/control readbacks; this package does not invent a second independent lock flag.

Under the reviewed production composition, active STOP ALL or the computed external lock denies before HTTP handoff. These checks do not prove the live site's actual settings; no production observation occurred. WordPress runtime hooks/code remain a trusted boundary, and tests do not establish immunity to arbitrary in-process code changes or post-check races.

## Reviewed HTTP/secret properties

- HTTPS only; exact oauth2.googleapis.com/www.googleapis.com hosts and bounded method/path classes. Userinfo, explicit port, fragment and foreign hosts deny.
- TLS verification required, redirects0, unsafe-URL rejection true, timeout20, response limit1..10000001, fixed approved headers and no header control characters.
- Transport forces cookies=[], blocking=true and stream=false. OAuth form body <=20000; multipart upload <=DriveTransferRequest::LIMIT+16384 with strict boundary syntax. No unsupported method/body combinations.
- Exact query/form/body/binding restrictions come from canonical trusted composers; transport origin/path validation alone is not a grant for arbitrary Google requests.
- Transient bearer/client-secret values are not logged/persisted by the transport; encrypted existing custody and SensitiveParameter attributes are retained. OAuth observations exclude tokens; only approved response headers are returned. No raw provider error surface is added by this change.

## Policy correction and negative tests

When the Drive transport exists, policy admission requires the exact hashes of all three files in `drive-transport-review.json`: Http, authoritative Gate and production composition. Only the exact reviewed path/line is removed from the generic unmatched-sender stream; all other original HTTP safety assertions still execute. Absent Drive transport receives no special sender admission. Any drift, symlink, missing reviewed dependency, extra sender, same basename elsewhere or changed gate/TLS/composition denies pending new review. No WordPress-function, directory, Google-host or blanket filename exclusion is added.

Four Python policy regression tests PASS: exact reviewed composition admitted; rogue sender/same filename/other Google sender denied; removed gate/disabled TLS/duplicate sender denied; authoritative gate and production composition drift denied. A real source-profile scan and exact unchanged ZIP scan both PASS with the corrected rule. The pre-correction rule is retained as before-evidence and failed as shown above.

Exact-ZIP WordPress tests:2 tests/55 assertions PASS against real installed gate, Settings, committed ledger and OAuth/canonical protocol classes. Each lock negative first asserts an all-open committed-ledger/effective-settings baseline true, ruling out a permanently unavailable DB as false-positive denial. Closing STOP ALL, activation, automation, product or product authorization makes the ledger deny; actual gate/HTTP transport deny with0 calls. Fixture writes are isolated local DB only, restored after testing; no production activation/grant/credential is provisioned. Full positive owned revision/source lineage is NOT provisioned: gate/transport binding[] assertions are denial-only, not full positive ownership/dispatch proof. Static exact-file pins and real safety-predicate tests provide the bounded admission evidence. OAuth broad scopes, malformed type/TTL, expiry/revocation/future time deny; observations redact tokens and altered canonical query denies.

The existing21 pure transport boundary assertions also PASS,0 transport calls. Seven existing admission-control tests PASS. No hosted exact-ZIP audit is implied by any of these results.

## Artifact and remaining gates

No plugin implementation file changed. Copied reviewed source appears only in non-packaged security fixtures; production entrypoints, settings, global guards, credentials and installed ZIP remain untouched. ZIP SHA256 remains aba29f64dd0a874635098492c4073012d219a404c676e2b237a346c6495b43e1. This policy/test change does not produce a new plugin candidate. Any later plugin remediation would require a new package checksum, verification and owner acceptance.

The earlier qualification's FAIL describes the old unmodified scan. This documented draft-PR correction supersedes that specific local policy finding; it does not rewrite historical receipts, change main without review or claim a hosted artifact PASS. Ordinary source CI and exact-artifact/local checks must remain separately labeled. Hosted intake remains blocked by uploads.github.com egress; empty private draft435449024 contains no uploaded asset. Current-source drift/preservation, actual schema/bindings as applicable, consistent checkpoint/tested restore, formal release-audit disposition and distinct installation authorization remain separate gates.

STOP ALL ON; external lock ON; automation OFF; RELEASE HOLD. No production action or live Google/Printify request occurred.
