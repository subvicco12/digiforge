# Printify GET acceptance implementation plan

Goal: an actor/session/site-bound, independently reviewed acceptance overlay over existing GET pipelines. Owner explicitly directs autonomous implementation; no production activation or policy exception is approved.

Architecture: fixed contract; immutable HMAC-sealed session proposal and receipts; existing read/product/lifecycle/catalog dispatch only. WordPress ability registration is conditional on supported API presence and never implies connected-tool access. No arbitrary URL or credentials input. Existing runtime policy remains authoritative, including STOP ALL and disabled/unbound integrations.

- [x] Test fixed GET allowlist, bounded pagination and schema-only redaction; implement PrintifyAcceptanceContract.
- [x] Test independent authority, expiry, revocation, exact binding, session identity, one-use slots and account budgets; implement PrintifyAcceptanceBridge over existing workflows.
- [x] Expose exact catalog plan metadata without executing or revealing secrets; retain grants/exceptions and audited reservations.
- [x] Add a narrow ControlledTransport dispatcher, conditional WordPress abilities and offline discovery/permission tests.
- [ ] Run full source and extracted-package regression; preserve .22 baseline; publish one versioned package and receipts to canonical Drive.

Review focus: revoked owner during dispatch; caller-controlled headers/URL; unknown/partial receipts; concurrent slots; connector discovery versus actual deployed tool availability. No authority created here overrides native capabilities, signed delegation, source binding or global execution locks.
