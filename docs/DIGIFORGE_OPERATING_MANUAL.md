# DigiForge — Shared Development Operating Manual
Version: 1.0 | 2026-10-17 | Status: proposed, documentation-only

## Authority
- Governing product authority: **DigiForge Ultimate Master Blueprint v6.0**; companion **DigiForge/DigiCraftifyGoods Master 500 v2** for catalog decisions. This manual is operational guidance, not permission to supersede either source.
- Repository: `subvicco12/digiforge`; production: `https://digiforge.converentis.com`.
- Source hierarchy: Blueprint and catalog master > approved project governance > certified main code/evidence > this operating manual > current task instructions. Flag contradictions rather than silently reconcile them.
- The Blueprint distinguishes production-certified state from engineering checkpoints and defines a four-shop target, hierarchical controls, immutable versioning, DigiForge website portal, finance evidence, and release/acceptance gates.

## Roles and tool boundaries
- **ChatGPT Chat — coordinator:** inspect live GitHub state, prioritize, partition tasks, review outputs, resolve conflicts, prepare owner decisions. Chat conversation memory is not the authoritative status ledger.
- **Codex — engineering:** isolated repository tasks, code, focused tests, draft PRs, exact-head CI and engineering evidence. Read `AGENTS.md` first.
- **ChatGPT Work — research and cross-app QA:** source-grounded Master 500/provider research, browser-based public QA, document reconciliation and evidence packets. No source edits without explicit scoped assignment.
- Tools and conversations do **not** automatically share all state. Read this manual and the ledger at session start; update evidence in GitHub.

## First-cycle lanes and exclusive writable scopes
1. **Codex A — Finance:** `includes/Finance/**` and finance-specific tests. Start from draft PR #1147. Reconcile Etsy payment/fee provenance, actual POD costs, immutable reconciliation, finance-period hashes and approval controls.
2. **Codex B — Printify:** `includes/POD/PrintifyCatalog*.php`, `tests/printify-catalog-*.php`, `tests/printify-catalog-preview-smoke.php`, `tests/printify-catalog-read-policy-smoke.php`. Start PR #1189; inspect dependencies #1171/#1172. Offline-only boundary; no live access.
3. **Codex C — integration review:** write new `docs/completion/**` only. Inspect issue #959 and all open PRs; prepare requirement/PR/CI/acceptance mapping and conflict-aware integration order.
4. **ChatGPT Work — Master 500:** research/evidence artifacts only. Priorities PP-002, PP-011, PP-003, PP-009, PP-013; US, Canada, UK, EU/Germany, Australia. Unverified variants/geometry/shipping = UNKNOWN.

**Collision rules:** One writer per file or subsystem. Shared CI workflows, installers, migrations and cross-cutting policy changes require coordinator reassignment. Large PRs must be checked for overlapping history and changed files. Never merge independent branches merely because each CI passes.

## Safety invariants
- STOP ALL ON; external safety lock ON; automation OFF. Do not change controls to run research or tests.
- No production writes, deployment, merges, Etsy publishing, international selling activation, Printify live requests, TinyFish live sessions, orders, credential extraction, or certification/approval inference without separately scoped owner authorization and the required gates.
- Use sanitized data; never request, print, or persist credentials. Approval and release status must be grounded in authentic evidence.
- Keep the four-shop target; phase-one active shops are Digital and Personalized POD. Protect shop ownership and separation.
- Passing CI is **not** release certification, production deployment, or authorization.

## Engineering delivery protocol
1. Read Blueprint sections relevant to task, `AGENTS.md`, this manual and latest execution ledger.
2. Fetch fresh main SHA, branch/PR heads, changed files, dependencies and exact-head CI before editing.
3. State deliverable, writable scope, acceptance tests, prohibited operations and dependency assumptions.
4. Implement a substantial coherent slice with focused tests. Prefer existing code and shared contracts; avoid duplicate policies or speculative rewrites.
5. Run focused tests during iteration; full required CI at meaningful checkpoints and final exact head. Never disable or weaken checks to save cost.
6. Deliver PR link, exact SHA, tests, evidence, blockers, residual risk, and smallest owner action. Update ledger with timestamp and evidence links. Do not claim success for unverified steps.
7. Integration reviewer validates overlap, dependency order and Blueprint acceptance before any merge request.

## Cost/throughput controls
- Begin with **two concurrent coding lanes**; third Codex lane is review-only. Work research can proceed independently.
- Batch related fixes, avoid redundant research and frequent no-op polling, and run targeted tests before expensive full CI.
- Prefer completing existing PRs to launching new competing features. Escalate only genuine blockers.
- Track requirements accepted, end-to-end workflows, critical blockers and evidence quality—not commit counts.

## Completion and release
- Use Blueprint v6.0 sections 25–32 and issue #959 as acceptance framing.
- Status vocabulary: VERIFIED ON MAIN, IMPLEMENTED IN OPEN PR, CI VERIFIED, INTEGRATION PENDING, EVIDENCE BLOCKED, NOT IMPLEMENTED, UNKNOWN. Do not collapse these states.
- Production activation is a distinct owner-authorized process after all relevant technical, security, approval and evidence gates.

## Required handoff format
- UTC timestamp and repository/main SHA.
- Lane, owner, branch/PR, exact head SHA, exclusive files.
- Completed and independently verified items with CI run IDs.
- Blockers/UNKNOWN evidence and conflicts.
- Next executable tasks and minimal owner action.
- Safety state and explicit declaration of production impact.

## Maintenance
- This manual changes via reviewed documentation PRs. Ledger updates record evidence, not assertions.
- DOCX copies are convenience exports; GitHub Markdown is authoritative for this operating model.
