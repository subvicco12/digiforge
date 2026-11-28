# FC01 native evidence: bounded source timestamps

Issue #1209 engineering lane A. Baseline: PR #1208, `2a234de16cd9dfb9573f16d51bafb44dfdfd5f50`.

## Demonstrated defect and correction

The packet's absolute timestamp syntax admitted UTC offsets `+24:00` and `-24:00`. PHP's DateTimeImmutable accepted both without warnings, so an independently reviewed synthetic packet using either malformed observation timestamp returned all eight matched declarations. This is invalid source provenance, not authentic provider evidence.

Restrict timezone offset hours to 00–23 and minutes to 00–59 before date parsing. Existing impossible-calendar, missing-timezone and expiry checks remain unchanged. No new TTL or provider policy is introduced. Both observed_at and expires_at use the same validator.

## Evidence

New `FC01NativeSourceTimestampTest` covers invalid positive/negative offset-hour overflow, offset-minute overflow, impossible calendar/time values, and missing timezone. Valid UTC, +05:30 and -04:00 timestamps preserve their actual instant and continue to match reviewed declarations.

- Before correction: 11 cases / 209 assertions, two expected regression failures (both 24-hour offsets were accepted).
- After correction: new suite plus unchanged FC01NativeEvidencePacketTest: **25 tests / 510 assertions PASS** in isolated local WordPress with actual HTTP blocked.
- Hosted exact-head audit is a separate publication gate; not claimed by this local record.

## Acceptance and safety

N01–N08 remain reviewed-declaration checks only. Native compatibility remains UNKNOWN; acceptance remains UNVERIFIED. BP-template-qa-spelling and ledger counts unchanged. No source assets, FC01 layout/field rules, ownership/review schemas, APIs, tables, provider operations or Finance code changed. FC01 v1 hashes and production bindings are untouched. STOP ALL/external lock ON; automation OFF; release hold preserved. No production impact.
