# Final software boundary implementation plan

> Implement inline using executing-plans; existing worktree is isolated. User requested autonomous continuation.

Goal: close independently implementable non-finance contracts while retaining explicit operational and finance deferrals.
Spec: ../specs/2026-10-11-final-software-boundaries.md
Tech: WordPress/PHP8.3+, MariaDB advisory locks, existing option evidence and shop usage tables; no new dependency.

Constraints: STOP ALL ON; external lock ON; automation OFF; no external operations, Finance1147 edits or protection changes.
Review focus: partial persistence retains holds; cross-shop request/quote/attribution conflicts deny; UTC rollover conservatively retains unresolved amounts; no float arithmetic for monetary authorization; recipe output cannot execute script or remote content.

1. Add failing WordPress tests for missing reviewed quote, atomic ceiling consumption, replay conflict, cross-shop and charge-overrun boundaries. Implement fixed-point decimal parsing, immutable quote receipts and reservation accounting in includes/AI/MonetaryReservationRepository.php; integrate optional request contracts with ShopAiGovernanceRepository/GovernedGeneration/OpenAIClient. Verify focused suite then full checks.
2. Add failing real REST tests for capability, explicit shop/review, immutable imports/replay and no HTTP execution. Implement includes/REST/AiEvidenceController.php and includes/Portal/AiEvidenceWorkflow.php; register existing plugin/portal. Verify server and portal error/access cases.
3. Add failing tests for approved canonical text/recipe deterministic SVG bytes, tampered artifacts, unsupported recipes and changed personalization/template. Implement includes/POD/DeterministicPersonalizationRenderer.php; integrate RenderArtifactVerifier and render evidence boundary. Verify existing POD gates remain intact.
4. Update source-ledger exact references/status boundaries; run integrated suites and browser fixtures, security scans and packaging. Publish verified commits on existing PR1161 branch, independently verify SHAs and require exact-head audit success. Keep merges pending unreadable governance.

Implemented scope additionally includes reviewed inclusive tariff ceilings with finite server tool limits; retained actual source documents; append-only late cost attribution; protected multi-panel text/photo SVG artifacts, authenticated panel previews and inspection links. All source IDs remain unpromoted. Specialized adapters, raster/PDF/font QA and complete commercial denominator joins are explicitly outstanding in the candidate report.
