# DigiForge First Launch Master Plan v1.1

**Status:** Proposed launch overlay; approval for documentation-only draft PR does not authorize merge, deployment, Etsy publication, Printify production or automation activation.  
**Date:** 2026-11-02. **Authority:** Ultimate Master Blueprint v6.0, Master 500 governed workbook, current repository/runtime evidence and owner approvals.  
**Primary shop:** DigiCraftifyGoods (Shop 2, personalized POD, US market). Digital shop follows after first POD launch; future standard POD and jewelry remain deferred.

## 1. Mission and launch gates

Deliver the shortest safe path to **3–5 published, purchasable personalized listings**, then 10–15, then 15–25. The launch gate is verified live listing quality and safe fulfillment, **not** PR count, catalog size or feature completeness. Target windows (3–5 days) are conditional estimates, not promises. No launch without owner approval and product/fulfillment evidence.

Initial low-complexity products: personalized mugs, name/date posters, simple photo mugs and text/photo apparel. Defer complex pet portrait rendering, face socks, AOP, cross-product Etsy listing routing and full video automation if they block launch. Printify-native manual/automated personalization may be used where verified, with direct Printify-to-Etsy publication as a separately approved fallback if DigiForge's publishing integration is not ready.

## 2. Immediate P0: reconcile existing work, not rebuild

1. Verify current `main`, current certified integration head, open PR heads, checks, ownership and dependencies. Classify each relevant PR as merged/equivalent, ready, conflicting, blocked or irrelevant.
2. Prioritize Printify supplier/variant/geometry evidence, personalized artwork and preview parity, product mockups, Etsy draft/personalization sequence, order mapping, manual production controls and operator approval.
3. Reuse tested implementations; no overlapping writers, force pushes, broad PR cleanup, or redundant audits. Produce one coherent launch-focused integration candidate with exact-head CI and independent review.
4. Preserve all immutable evidence, STOP ALL, external lock, shop isolation and explicit authorization boundaries.

## 3. One complete product package per listing

Each approved launch candidate must contain: original licensed artwork; personalization input schema; exact Printify blueprint/provider/variant/region; provider-authoritative print geometry and DPI/bleed/safe-zone checks; matching preview and production payload; 5–8 accurate mockup/image assets where useful; optional short video; evidence-backed Etsy/Google keyword research; readable title; 13 relevant Etsy tags; accurate description and attributes; shipping/processing terms; competitive price, contribution margin and fee scenarios; QA and human approval evidence.

Research seasonal demand and competing listings before artwork finalization; never claim estimated competitor sales as verified. Rank opportunities by demand, differentiation, margin, development speed and **hard delivery-feasibility gate**. Do not copy competitor artwork or misleading mockups.

Reuse one design family across multiple physical products, but keep separate personalized Etsy listings initially. Printify multi-product listings are not assumed to support personalization. Use Printify-native previews/mockups where supported; DigiForge rendering and approval for complex or unsupported products. Do not build a new 3D mockup engine.

## 4. Printify provider strategy

Start with a few US-eligible providers and certified physical templates, not 25 providers. Score only eligible providers using quality, cost, shipping, variant/geometry evidence and availability. Key templates by provider, blueprint, variant set, print area, method and region. Provider switches require geometry, mockup, shipping and QA revalidation; never silently route geometry-sensitive products to a new supplier.

## 5. Pricing and finance minimum

Before publication, compare genuinely comparable Etsy listings and capture Printify product/shipping costs, Etsy seller-account fees, payment/FX fees, advertising scenarios, refunds and AI costs. Price competitively but above a governed margin floor. Introductory pricing and future repricing require owner approval; automatic repricing is deferred. Preserve Etsy, Printify, Payoneer and bank evidence from the first sale, including destination and tax-classification data. Indian GST treatment for offshore manufacture/delivery must be reviewed by a qualified professional; no automated GST filing or tax classification assumptions.

## 6. Independent shop research policies

Each shop has its own sources, interval in days (e.g. 7 to 10), seasonal lookahead, run quantity, shortlist cap, AI model policy, monthly budget, notifications and manual run controls. Future shops stay disabled. First launch uses manually initiated bounded research; scheduled research, auto-pricing and broader automation follow separately authorized acceptance. Never infer a product is selling solely from visible listings or trend indicators.

## 7. Working environments and autonomous development

- **ChatGPT Chat:** owner decisions, scope, priorities, launch coordination, gate reviews and concise progress reports.
- **ChatGPT Work:** source-backed Etsy/Google market research, supplier verification, competitor pricing, browser QA and independent evidence; does not modify code.
- **Codex / 4X:** isolated repository engineering, integration, tests, CI, draft PRs and defect repair, with explicit writable ownership.
- **GitHub CI/orchestrator (future separately authorized setup):** bounded task queue, dependency checks, two non-overlapping coding lanes, one independent review lane, limited retries, hard cost ceilings and daily reporting. No claim that ordinary Chat/Work sessions execute indefinitely or are automatically launched by GitHub.

Once separately configured and approved, nonconsequential engineering tasks can continue without repeated “Proceed.” No merges, deployments, credential activation, Etsy publish, provider production or spend beyond granted budgets without the appropriate owner gate.

## 8. Launch decision and fallback

- **G1:** Current PRs and production blockers triaged; select fastest verified publication route.
- **G2:** At least one complete product passes print-file, personalization, mockup, supplier, SEO, pricing and operator QA.
- **G3:** Approved Etsy/Printify draft and safe manual order approval demonstrated.
- **G4:** Owner authorizes exact listing publication; verify storefront and fulfillment settings.
- **G5:** Expand to 10–15, then 15–25 listings; move to digital shop only after the initial POD gate.

If G1/G2 cannot be met promptly, reduce to simpler Printify-native personalized products rather than expanding engineering scope. Do not defer the first launch for advanced video, automatic research, full Finance, additional providers, or 500-concept completion.

## 9. Source-of-truth and session start checklist

This file is the persistent **launch execution overlay**, not a replacement for the Ultimate Master Blueprint v6.0. At each session start: read Blueprint, this plan, AGENTS.md, current execution ledger, current GitHub PR/CI state; identify the smallest unblocked launch task; respect existing file ownership and safety locks; report exact branch/SHA, evidence, blockers, next action and production impact. If the plan and current evidence differ, do not silently invent implementation status. Update this plan only by reviewed versioned change.

**No live operation or activation is authorized by adopting this plan.**
