# Etsy Adapter Contract — Locked Foundation

This sub-batch connects the Etsy-specific integration boundary to DigiForge's existing provider-neutral controlled-execution subsystem.

Contract:
- Etsy adapters implement the existing DigiForge POD ExecutionAdapter contract.
- A future adapter receives only a consumed ControlledExecutionGate permit.
- This interface contains no HTTP client, OAuth mutation, webhook registration, media upload, draft creation, or publish operation.
- The generic execution subsystem remains responsible for authorization, nonce consumption, and execution evidence.
- The Etsy-specific policy remains responsible for the Etsy capability boundary and explicit human approval.
- External execution remains disabled while the global safety lock and effective capability switches are off.

Required future implementation stages are intentionally outside this sub-batch: provider credentials, shop/account scoping, idempotent operation records, reconciliation-before-retry, provider response normalization, immutable receipts, failure/dead-letter handling, and separate publish approval.
