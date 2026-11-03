# Atomic shop AI quantity reservations

Blueprint §7 requires stage ceilings to stop excess work. Previously a preflight followed by an unlocked usage insert allowed concurrent distinct attempts to observe the same remaining slot and both invoke AI.

The final policy read, monetary-budget refusal, stage preflight and durable usage reservation now share one shop-scoped MySQL advisory lock. Acquisition is nonblocking. Failure to acquire, uncertain reservation or uncertain release blocks the provider. Provider calls occur after release, and consumed quantities remain conservative if subsequent execution fails. Existing generation replay guards and STOP/scope gates remain intact. This introduces no external execution and no schema change.

WordPress behavioral tests use a separate real database connection to hold the shop lock, verify contention blocks a reservation, verify the slot becomes usable after release, reject a second distinct attempt at the final stage slot, and retain fail-closed behavior for active monetary budgets. Unit suite: 1,152 tests / 7,340 assertions, legacy checks, configured PHPStan/PHPCS and changed PHP syntax pass. Full WordPress results are recorded in the PR after execution. Independent code review found no important findings.

This closes the demonstrated generation race; it does not claim authoritative monetary actual-cost attribution or original-source template candidate cap enforcement. Those remain separate work. Exact-head Engineering & Safety Audit is mandatory before merge. No finance, production, provider operation or activation changes.
