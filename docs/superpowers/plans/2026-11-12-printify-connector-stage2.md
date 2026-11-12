# Printify companion Stage 2 preparation

Goal: port the existing Stage 0/1 connector additively onto the exact qualified 1.0.103.17 package, without installation or provider access.
Architecture: thin companion and DigiForge-owned host module; preserve all baseline members except reversible version metadata and the reviewed additive catalog-client method. Governing PR1262 requires unconditional STOP ALL/external-lock denial. No live-read exception is authorized or implemented.

- [x] Verify PR1263 head and exact local .17 ZIP identity/CRC.
- [x] Identify existing .17 native authority, connection, vault and missing upload ownership contracts.
- [x] Add failing then passing policy/host-discovery behavioral tests; expose non-authorizing compatibility diagnostics.
- [x] Add strict reproducible two-plugin composition, preimage checks, preservation manifest and rejection tests.
- [x] Exercise extracted .17 host and companion with WordPress and denied external HTTP/mail.
- [x] Run full unit, WordPress, offline, lint/security and exact-head CI.
- [x] Produce final test-only package identity and rollback instructions; report all live approvals separately.

Review focus: no wholesale main-over-production replacement; no grant from diagnostic evidence; unknown scopes remain denied; upload ownership absent is not inferred; no schema or bootstrap migrations; no network during isolated tests. No production-equivalence claim without current production hashes. No rollback-recovery qualification is claimed by file restoration instructions.

Qualification boundary: full extracted package remains BLOCKED: all6errors/14failures also reproduce on unchanged .17 control; no failure suppression or installation readiness claim. Hosted exact-head CI is recorded in the PR receipt after commit.
