# Current core certification — 14 November 2026

Observed core PR #1264 head: `d8733ba6167a968575010a8ee53e936752e344b8`. Its runtime equals `2ac7e9183cf74a216c255138aaff1a2f615ec553`; the latter introduced fresh batched owner-ledger reads.
The companion's trusted core pin remains `7d624512a0fab416278b7d8f88208c9463224b6f`.
Companion 0.7.2 adds the read-only existing-product source component. The candidate pin is not promoted; all dispatch authority remains unchanged.

## Latest source qualification

Run `43626762812` completed WordPress **1,952 / 74,676** (five existing skips), catalog **58 / 268,509**, unit **2,239 / 10,866** (one existing skip) and Action Scheduler **5 / 87**. The overall audit failed its history credential gate: one generic detection is independently proven to be a qualified source-file SHA-256 in the preservation test, not credential material. A narrow historical-blob/location/checksum verifier and negative tests are under separate review. No gate has been bypassed and no full audit PASS or pin promotion is claimed.

Actual candidate conformance is GET **51 / 630**, fresh owner-ledger checks **13 / 53**, and the new protected price-only source component **1 / 91**. Provider responses were synthetic; the component made zero inspection DML, ciphertext SQL reads or HTTP. See [EXISTING_PRODUCT_SOURCE_INSPECTION.md](EXISTING_PRODUCT_SOURCE_INSPECTION.md). It is not a mounted operator route or canonical mutation capability.

The completed older built-artifact control remains bound to core `63953bfd…` plus its frozen companion runtime: **1,939 / 73,138**, four skips. The following timeout receipt is retained as history, not a claim about the newer head.

## Earlier hosted source audit

Engineering & Safety run `43611269255`, job `132496693397`, ended CANCELLED.
The GitHub annotation states: **The job has exceeded the maximum execution time of 2h0m0s**.
`composer test:wordpress` last printed 1,830 of 1,939 cases; neither a completed main result nor the required catalog result exists. Newline buffering prevents identifying the exact active case from that progress line. Later security/package gates did not run. This is not a passing audit or a demonstrated assertion failure.

Completed gates include unit 2,239 tests / 10,866 assertions (one existing skip), native boundary 17 / 95, and focused security 25 / 598 plus 58 / 1,834.
The owner subsequently approved the exact 120-to-180-minute budget change, published as `b370de4b88620b733b8d8d0082278e1bbb501f6b`. Its run `43623591451` was cancelled by a later independent branch push. The current head retains the approved budget. No test, gate, trigger or permission was removed by that change.

## Earlier isolated 63953bfd candidate conformance

The exact candidate core artifact and extracted companion runtime were tested with networking disabled, WordPress 7.1.4-src and synthetic provider responses. A private runtime copy changed only the trusted source pin to the candidate for the positive default-discovery and native-denial checks; the repository pin is unchanged.

| Profile | Result | Scope |
| --- | --- | --- |
| Candidate-pin native GET main | PASS 41 tests / 566 assertions | Actual core ports, synthetic transport |
| Native catalog GET | PASS 10 / 64 | Completes all 13 GET operations with main |
| Approved upload-wire descriptor | PASS 1 / 62 | Actual distinct-author review, immutable wire hash, stale-source and missing-rights/geometry denial |
| Mutation/source/journal | PASS 57 / 538 | Actual fail-closed missing-port denials and partial source/journal primitives; **not native mutation success** |
| Unmodified old-pin GET main | One expected failure, 41 / 563 | Default discovery correctly rejects the unpromoted candidate |

The older 63953bfd full WordPress control passed 1,939 / 73,138 (four skips), and its fresh catalog passed 58 / 11,810. Reused-catalog and final-head built-artifact qualification remain pending until their source-specific receipts are recorded. Previous complete paired runs retain their original, older source identities; they do not certify this head.

## Remaining software and release gates

Only GET_READ and PANEL are advertised. Canonical mutation registration/reservation, complete dependency/version CAS, durable native journal/receipt joins, publication, allocation and recovery ports remain incomplete or denied.
The v1 mutation response requires qualified recovery/checkpoint epochs even for successful REGISTER; local counters or hashes cannot supply those guarantees. The reviewed source-association counter is not a native product aggregate version.

Independent recovery anchoring, exclusive usable credential custody, actual account/credential scope association and reviewed Shop 2 binding remain unqualified. Installation, live GET acceptance, provider writes and activation are unauthorized. STOP ALL and external lock remain ON, automation OFF and RELEASE HOLD active. No historical UNKNOWN operation was replayed.
