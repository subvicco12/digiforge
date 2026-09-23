# Etsy Persistent Operation Record — Pre-Persistence Contract

This sub-batch defines the canonical record that must be accepted before a future database-backed Etsy operation ledger is introduced.

Each operation is scoped to:
- shop_reference
- approved Etsy intent
- approved draft package
- operation type
- unique idempotency key
- request fingerprint
- authorization hash
- evidence hash

New records always begin at NOT_SENT. Their deterministic identity includes the shop, intent, package, operation type, and idempotency key so the same key cannot silently cross shop/account scope.

This layer intentionally performs no database write and no external request. Persistence will be added only with an additive schema migration and WordPress integration tests. The persistent repository must enforce uniqueness, compare-and-set lifecycle transitions, immutable request identity, provider reference capture, reconciliation-before-retry, and audit evidence.
