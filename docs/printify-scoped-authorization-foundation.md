# Scoped Printify Authorization Foundation

This batch adds a non-user-writable, fail-closed authorization gate for the Printify capability. It does not authorize or execute provider orders, production, fulfillment, Etsy operations, Gelato, finance, GST, schedules, workers, or external HTTP.

Printify authorization requires effective Product Development first. Configuration alone remains insufficient. Protected-state recovery revokes Printify authorization. Order automation remains outside the effective capability allowlist, so the existing Printify mutation interlock continues to deny provider order and production mutations even when Printify is later separately authorized.

No production activation is part of this change.
