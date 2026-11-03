# Local artwork and commercial KPI implementation plan

> **For agentic workers:** Use superpowers:executing-plans inline, task by task.

**Goal:** Add reproducible local artwork adapters and authoritative non-finance commercial AI cost KPIs to PR #1161.
**Architecture:** Small bounded codecs/font adapter feed the existing immutable recipe and protected artifact certification. A read-only KPI projection joins reviewed charge evidence to existing scoped lifecycle records.
**Tech Stack:** PHP 8.3+, WordPress, MariaDB, native zlib/DOM, PHPUnit.
**Spec:** docs/superpowers/specs/2026-10-11-local-artwork-commercial-kpi.md

## Global constraints
Finance #1147 untouched. STOP ALL ON, external lock ON, automation OFF. No network rendering, production, promotion, merge bypass or CI weakening. Preserve original source evidence.

## Review focus
- Corrupt PNG chunks, expansion bombs and unknown features must not pass header inspection.
- Fonts missing actual glyphs must not substitute a host font.
- PDF parity must bind reproduced pixels and exact canonical document bytes.
- Pending provider charges and multiple currencies must not become zero or summed spend.
- Cross-shop, ambiguous and out-of-period lineage must not contribute a denominator.

### Task 1: Local artwork adapters and certification
Create RasterArtworkCodec, BitmapFontAdapter and RasterPdfAdapter under includes/POD; test LocalArtworkAdapterTest. Integrate optional immutable bitmap font recipe and output formats into DeterministicPersonalizationRenderer, DeterministicRenderWorkflow, RenderArtifactVerifier and RenderArtifactStore.
- [x] Write behavior tests for real PNG decode/encode, CRC/truncation/bounds, actual font glyph rendering/missing glyph, canonical PDF corruption and format reproduction.
- [x] Run tests RED before implementation.
- [x] Implement bounded adapters and exact-source certification; update invalid legacy PNG fixtures to valid PNG bytes.
- [x] Run unit and WordPress artifact tests GREEN; no provider execution.

### Task 2: Commercial KPI joins
Create CommercialCostKpiReadModel; integrate it into CostKpiReadModel and the read-only portal. Tests: CommercialCostKpiTest using real database records and original statement receipts.
- [x] Write tests for reviewed charge/source provenance, product/opportunity/listing/order joins, UTC half-open periods, shop/currency separation, pending and database failures.
- [x] Run RED, implement exact lineage with failure-versus-zero states, run GREEN.

### Task 3: Integrated verification and publication
- [x] Reconcile original-source evidence and remaining gaps without blanket PASS.
- [x] Run full unit, WordPress, offline, browser, configured static/lint, dependency/security and package checks.
- [x] Obtain independent code review; fix important findings with regression tests.
- [ ] Commit/push substantial candidate, independently verify exact remote SHA and exact-head mandatory audit.
- [x] Document protection/artifact read blockers and precise owner action. Leave merge pending where governance cannot be verified.
