# Day 1 combined engineering acceptance

Authority: Issue #1252 and PERSONALIZED_POD_FOUR_DAY_MVP_BLUEPRINT_20261103.md.
Reviewed candidate: dd9b6d000847028298086932ee205b04e6556097.
Verified remote branch codex/day1-integration-1252 was identical to that SHA;
main was 0e15e4ccae430498c92a30ab1f8951171fa0ad13, with no divergence.

STOP ALL ON; external safety lock ON; automation OFF; RELEASE HOLD.
No merge, deployment, live provider call, publication, order or shop setting change.

## Independent review and confirmed fixes

A reviewer independently inspected the combined research contract, repository,
portal handlers, held package adapter and tests. No authorization bypass,
cross-shop read, mutable-version overwrite or external execution path found.
Two malformed-input defects were reproduced and fixed without broadening scope:

1. Empty SVG reached DOMDocument::loadXML and threw ValueError rather than a held
   validation failure. Reject empty bytes before invoking the XML parser.
2. Array-valued fixture ID and scalar native-fields/economics could throw TypeError
   beyond the authenticated wrapper. Validate structured field types and the
   fixture key before indexing or invoking typed helpers.

Regressions failed with the original ValueError/TypeError before the fixes.
Independent follow-up accepted the delta: focused unit 47 tests / 90 assertions.

## Approval and artwork boundary

Authenticated package entry resolves the current durable Lane A version through
OpportunityDesignRepository::read(), rechecking identity, both capabilities,
exact personalized_pod scope, stored-byte hash and immutable decision. Adapter
uses the exact JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES record hash and
HUMAN_APPROVED/APPROVE evidence binding. The environment is a review context,
not a supplier permission inferred from the artwork record.

Lane A accepts reviewed SVG up to 262144 bytes and durable record JSON up to
400000 bytes. Lane B intentionally has a smaller review-artifact budget:
65536 bytes per structured input/final package (using the existing Validator
JSON representation), and a separate 60000-byte raw SVG guard. The effective
artwork budget can be smaller because metadata and encoding also consume space.
This is a fail-closed compatibility limitation, not a universal 64 KiB raw-artwork
guarantee. Nothing is truncated, rescaled, rendered or silently promoted.

A real WordPress regression imports and approves a Unicode SVG exceeding 80000 bytes,
requests a package, receives a locked WP_Error and re-reads the exact original
SVG bytes, source hash and immutable approval unchanged. Existing smaller-artwork
end-to-end test confirms compatible approved inputs assemble held packages with
zero HTTP calls. Larger-package storage remains a separate gate; no production
readiness or supplier geometry is inferred from approval or preview SVG.

## Local combined verification

- Standard composer test: PASS, three legacy scripts; 1199 unit tests / 7430
  assertions; configured PHPStan and PHPCS.
- Combined WordPress suite on clean disposable digiforge_tests DB: PASS,
  211 tests / 1924 assertions. This includes current authorization, malformed
  evidence returning locked 409 errors, immutable approval and oversized-byte
  preservation. Local fixture and fault-injection SQL noise remain visible.
- Offline regression discovery: PASS, 61 tests.
- Adapter PSR-12 and git diff whitespace checks: PASS.
- Existing unit warnings/deprecations remain visible; no tests or gates weakened.

Logs: /tmp/digiforge-combined-composer.log, /tmp/digiforge-combined-wordpress.log,
/tmp/digiforge-combined-offline.log. Local runtime is bundled PHP8.4, MariaDB11.8,
WordPress development fixture; hosted audit uses PHP8.3 and its declared services.

## Hosted acceptance and remaining gates

One consolidated draft PR targets current main. The unchanged Engineering and
Safety Audit checks out github.event.pull_request.head.sha explicitly. Its final
exact-head SHA/run/conclusion will be recorded on #1252 and the PR after the run;
this document does not predeclare hosted success.

Engineering acceptance does not close authentic provider variant/SKU/catalog,
supplementary print geometry, native placement/overflow/Choice, mockup rights,
buyer preview, shipping/processing, economics/tax/FX or commercial owner approval.
Portal product-evidence controls and larger-artwork package support remain pending.
No live transfer, orders, activation or production readiness is authorized.
