# Current core certification — 14 November 2026

Observed core PR #1264 head: `63953bfd9b87a6c365443cd1c66199ca76cee6e2`.
The companion's trusted core pin remains `7d624512a0fab416278b7d8f88208c9463224b6f`.
This test/documentation follow-up preserves the runtime from companion `a36379a2313903506788f312e890b63c40174e59`; it does not promote the candidate pin.

## Hosted source audit

Engineering & Safety run `43611269255`, job `132496693397`, ended CANCELLED.
The GitHub annotation states: **The job has exceeded the maximum execution time of 2h0m0s**.
`composer test:wordpress` last printed 1,830 of 1,939 cases; neither a completed main result nor the required catalog result exists. Newline buffering prevents identifying the exact active case from that progress line. Later security/package gates did not run. This is not a passing audit or a demonstrated assertion failure.

Completed gates include unit 2,239 tests / 10,866 assertions (one existing skip), native boundary 17 / 95, and focused security 25 / 598 plus 58 / 1,834.
An external-only, independently checked proposal changes only the existing job budget from 120 to 180 minutes. It is **not applied** and requires separate review/approval. No gate, test, trigger or permission is proposed for removal or alteration.

## Isolated candidate conformance

The exact candidate core artifact and extracted companion runtime were tested with networking disabled, WordPress 7.1.4-src and synthetic provider responses. A private runtime copy changed only the trusted source pin to the candidate for the positive default-discovery and native-denial checks; the repository pin is unchanged.

| Profile | Result | Scope |
| --- | --- | --- |
| Candidate-pin native GET main | PASS 41 tests / 566 assertions | Actual core ports, synthetic transport |
| Native catalog GET | PASS 10 / 64 | Completes all 13 GET operations with main |
| Approved upload-wire descriptor | PASS 1 / 62 | Actual distinct-author review, immutable wire hash, stale-source and missing-rights/geometry denial |
| Mutation/source/journal | PASS 57 / 538 | Actual fail-closed missing-port denials and partial source/journal primitives; **not native mutation success** |
| Unmodified old-pin GET main | One expected failure, 41 / 563 | Default discovery correctly rejects the unpromoted candidate |

Full current built-artifact WordPress and fresh/reused catalog qualification remain pending until their source-specific receipts are recorded. Previous complete paired runs retain their original, older source identities; they do not certify this head.

## Remaining software and release gates

Only GET_READ and PANEL are advertised. Canonical mutation registration/reservation, complete dependency/version CAS, durable native journal/receipt joins, publication, allocation and recovery ports remain incomplete or denied.
The v1 mutation response requires qualified recovery/checkpoint epochs even for successful REGISTER; local counters or hashes cannot supply those guarantees. The reviewed source-association counter is not a native product aggregate version.

Independent recovery anchoring, exclusive usable credential custody, actual account/credential scope association and reviewed Shop 2 binding remain unqualified. Installation, live GET acceptance, provider writes and activation are unauthorized. STOP ALL and external lock remain ON, automation OFF and RELEASE HOLD active. No historical UNKNOWN operation was replayed.
