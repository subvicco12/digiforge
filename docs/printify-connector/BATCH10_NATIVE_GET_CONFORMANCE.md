# Batch 10 — actual native GET conformance

Starting companion: `8e669c1aac5bd916769a6d25b07de9a620a17a9c` (PR1263). Independently inspected core: `9e9094097992453a8cb6ecddd7f936c3584eccef` (PR1264). Batch6 spec SHA256: `6d19ac1c9971d5dd730fa910d08b34ad868e7d0f351b5996029dd60322ed3434`. No core source changes are made here.

## Scope and trust decision

The seven actual core joins are SHOPS_READ, PROVIDERS_READ, PROVIDER_READ, PRODUCTS_READ, PRODUCT_READ, ORDERS_READ and ORDER_READ. They reuse PrintifyApiReadWorkflow sealed plans, independent review, native current session/owner authorization, exact connection/vault association, atomic one-use claims and existing transport. Native intent inputs are the connector grammar; PRODUCT_READ additionally requires blueprint/provider/variant evidence in its independently approved core plan. Caller-supplied source references cannot replace core-owned approval.

**Keep the companion's trusted runtime pin unchanged.** Core advertises PANEL only. NativePortAdapter intentionally requires aggregate GET_READ; partial implementation is not that capability. Even an explicitly configured test pin for the reviewed new SHA cannot admit a companion GET. No pin/capability override or new dispatch path is introduced. The positive results below are direct actual NativeCorePort::read workflows; they are not successful NativePortAdapter GET workflows. Six missing GET joins and canonical mutation/journal/receipt/snapshot/allocation/recovery remain blocked. Exact matrix: BATCH10_MISSING_PORT_MATRIX.json.

## Isolated conformance

New explicit test: tests/contracts/NativeGetWordPressAcceptance.php. It prepares real core approvals and immutable native bindings, invokes actual core port methods, asserts fixed GET endpoint/redirect denial, validates receipt identity and response hash, verifies replay consumes no additional request, and checks PII removal. All seven operations have independent data-provider cases. Products and orders use synthetic provider rows; no commercial product certification is inferred.

Negative cases cover wrong shop/integration/account, absent synthetic credential, disabled integration, STOP, external activation lock, revoked module and current WordPress session, malformed responses and rate limiting. Existing exact-core NativeReadGrantWordPressTest additionally covers renewed approval rejection, expiry during retry, revocation during retry, concurrent-winner SQL claim preservation, missing audit/persistence and post-send UNKNOWN preservation. Existing NativeCompanionWordPressAcceptance covers current panel/nonce, wrong pin, stale mappings and held candidate journals that remain non-authorizing.

Execution uses the independently verified core source-stamped offline artifact, actual companion classes and the actual core WordPress bootstrap. Test scaffolding lives outside immutable artifact runtime members. Docker network is disabled; HTTP and email are preempted. Only generated synthetic encrypted test material is consumed by the existing vault path. Real credentials, real provider calls and external dispatch are absent. Receipt request_attempted=true means the synthetic intercepted transport was invoked, not live connectivity. Positive fixtures temporarily unlock their isolated database only; no actual safety configuration is changed.

Core's normal safe GET path can retry bounded transient reads. A 500 then valid200 fixture must produce two synthetic attempts, followed by no grant replay. 429 Retry-After stops after one. Malformed provider data returns UNKNOWN and never restores the claim. This is ordinary governed read admission, not an implemented STOP exception.

## Reproduction and failure accounting

Verify exact remote heads and core Git object. Verify core artifact source-to-artifact.json, SHA256, CRC and each runtime member hash before use. Compose with private synthetic MariaDB via Unix socket, PHP8.3 and WordPress test library, no network. Set test-only DF_NATIVE_TEST_SHA to the exact reviewed core SHA. Run the new file, existing NativeCompanionWordPressAcceptance and exact-core NativeReadGrantWordPressTest together; use the actual core bootstrap rather than substituting a legacy test mode. Never add tests/scaffolding to production.

Initial local run's new callback accepted only one WordPress filter argument despite requiring three; resulting TypeErrors were safely caught by core and produced UNKNOWN. Correcting the test callback registration to accepted_args=3 resolves this harness error. No core or companion runtime change was required. This failed attempt remains in external logs and is not a provider/runtime defect.

The composed actual-core acceptance passed 36 tests / 457 assertions, including all seven positive operations, unchanged panel/held-journal denials and exact-core one-use/expiry/revocation regressions. Companion PHP unit passed 1,577 tests / 8,584 assertions. Final full WordPress, exact-head hosted CI and test-only artifact evidence are recorded in the PR qualification receipt after execution. Source CI is separate from locally composed native acceptance and installed-lineage extracted-package testing. Synthetic tests do not prove hosted durability, anti-rollback of all roots or exclusive credential custody. No installation-ready release is issued.

STOP ALL ON; external lock ON; automation OFF; RELEASE HOLD. No installation, merge, policy activation, live request, real credential or historical operation replay.
