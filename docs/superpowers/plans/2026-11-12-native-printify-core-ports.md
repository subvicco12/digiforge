# Native Printify core ports implementation plan

**Goal:** Expose the pinned Batch6 typed core boundary without inventing operational authority.
**Spec:** PR1263 a7dca70902f755c38f04aa7793040a8253a3056d, CORE_IMPLEMENTATION_HANDOFF_BATCH6.md and canonical JSON digest 6d19ac1c9971d5dd730fa910d08b34ad868e7d0f351b5996029dd60322ed3434.
**Architecture:** Lazy companion interface discovery; immutable shared value contracts; current core session, signed shop ACL, reviewed connection and owner checks. Native mutation admission remains non-executable until durable native identity joins and independent revocation exist. No new authority/store/transport, options journal or passive execution of ControlledExecutionTransaction.
**Execution:** Native implementation in the explicitly authorized isolated PR1264 worktree. No extra approval gate is introduced by this plan.

## Constraints

STOP ALL/external lock ON, automation OFF, RELEASE HOLD. No credentials, provider calls, installation, activation or migrations. PR1263 unchanged. No edits to vault/transport/settings/portal/schema.

## Tasks

- [ ] Pin shared value contracts and bounded canonical schema. Tests: malformed input, altered schema, absent/incompatible companion, no discovery side effects.
- [ ] NativeAuthorityAdapter and NativePanelAccessAdapter: current validated WordPress cookie/token, signed module ACL and independently reviewed exact provider mapping/revision. Nonce action binds version, scope and view. Tests: valid session without provider binding denies; wrong actor/shop/module, stale/revoked grants and destroyed session deny; no admin-owner fallback.
- [ ] NativeCorePort/NativeBridge: all exact composite signatures, supported capability disclosure, bound source manifest generated from committed Git blobs. Tests: malformed/stale/cross-shop/journal/receipt/allocation/recovery claims never acknowledge; repeated emergency events cannot rearm or assert generation/drain success. No callback receives credentials.
- [ ] Bridge GET compatibility: fixed grammar and current scoped authorization; no provider execution without independently resolved operational grant. Explicit missing native read-grant join denial instead of inferred permission.
- [ ] Verify tests against pinned actual companion interfaces; syntax, existing scoped/provider safety regressions, artifact/source hashes. Document per-method blockers and independent-checkpoint/credential-custody limitations. Push only existing PR1264 branch after rechecking head. Upload verified receipt and update Issue1252.

## Review focus

Forged snapshot hashes are not authority. Missing audit/journal is never an empty success. Local panel access is not publication/order approval. Failed persistence/rollback cannot revive native authority because this implementation grants none. Source identity cannot be caller-selected. Capability presence does not enable provider execution. Pure schema checks do not replace semantic validators.
