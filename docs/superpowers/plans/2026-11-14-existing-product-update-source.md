# Existing product update source implementation plan

> **For agentic workers:** Use the existing core lane and TDD; preserve the frozen composed acceptance worktree.

**Goal:** Add nonexecuting PRICE-UPDATE source evidence from an actual protected PRODUCT_READ→adoption→reviewed update plan chain.
**Architecture:** Reuse NativeSupplierEvidence, NativePlanEvidence and existing pipeline/read producers. Reject guessed provider IDs, unrelated read receipts, changed body/selection and missing/ambiguous adoption audits. Do not extend IMAGE source1.0 or advertise native mutations.
**Tech Stack:** PHP8.3, existing WordPress/InnoDB fixtures, synthetic controlled GET transport.
**Spec:** Owner sprint directive plus canonical Batch6 contract and current c502 protected supplier source contracts.

## Constraints and review focus
- No production, real credentials, provider writes/calls, policy changes or CI edits.
- All execution/full-CAS/native-counter/checkpoint flags remain false/null.
- Keep existing300-second freshness check at adoption; observations remain inspectable afterward, never live-currentness or mutation admission.
- Exact shop/product/blueprint/provider/variant and adoption producer actor must match.
- A separately valid same-data read receipt cannot substitute for the original adopted receipt.
- Original IMAGE source response remains unchanged.

## Task: protected existing-product source
- [x] Add an optional TEST-ONLY correct variant mapping mode before mapping approval; default fixture behavior unchanged.
- [x] Add failing NativeExistingProductMutationEvidenceTest covering actual synthetic GET, adoption, reviewed update plan and absent resolver.
- [x] Implement resolve(scope,actor,mapping,spec,updatePlan,productReadReceipt,expectedHash=null), exact request regeneration and minimized evidence.
- [x] Test product/variant/source/audit mismatch, stale expected hashes, revocation and STOP preservation; sender must see GET only.
- [ ] Run focused actual-source acceptance, complete unit/offline/static/lint and exact-head CI after separately approved timeout decision.

Focused acceptance: missing resolver RED1/1; final protected-source synthetic GET/adoption/price-plan GREEN1/72 (3:31). Full regression and exact-head certification remain pending.
