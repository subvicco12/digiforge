# Etsy Operation Ledger Contract

This locked sub-batch defines the lifecycle that a future persistent Etsy operation ledger must enforce.

States:
NOT_SENT -> SENT -> CONFIRMED_SUCCESS | CONFIRMED_FAILURE | UNKNOWN.
UNKNOWN must enter RECONCILIATION before a terminal outcome can be recorded.
RECONCILIATION may become RECONCILED or remain UNKNOWN.
RECONCILED may become CONFIRMED_SUCCESS or CONFIRMED_FAILURE.

Retry policy is deliberately fail-closed: retry is permitted only after CONFIRMED_FAILURE. UNKNOWN and RECONCILIATION are never retryable because provider state must be reconciled first.

This contract does not create a database table, execute Etsy HTTP requests, mutate OAuth credentials, create drafts, publish listings, register webhooks, or enable any external capability. The later persistence layer must bind each operation to shop/account scope, intent, draft package, idempotency key, authorization/evidence hashes, provider reference, request fingerprint, actor, timestamps, and immutable audit evidence.
