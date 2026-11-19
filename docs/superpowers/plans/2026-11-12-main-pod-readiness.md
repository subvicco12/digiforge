# DigiForge main POD readiness implementation plan

Goal: recover cumulative main-plugin source and add a useful offline FL-03/FL-04 text-proof path.
Authority: owner mission in this session; canonical v6.0 Drive document 1isN_2m7eu5DTjykY7X67woiPx7wluhPc; First Launch Master Plan v1.1; Personalized POD MVP Blueprint.
Architecture: existing PHP/WordPress protected artwork, supplier workflow, mockup imports and immutable listing packages remain authoritative. A pure proof renderer validates the existing specification and artwork, adds escaped sample text to reserved regions, and returns an integrity-bound SVG and minimized technical receipt. A server-side adapter resolves current approved artwork and exact supplier/research context before rendering. Portal POST uses the existing signed session and nonce. Proofs are local review aids; font metrics, supplier parity, safe zones and publication remain unverified.

Alternatives considered: rebuilding provider preview would overlap PR1263 and require live authority; replacing current mockup/package code would discard accepted work. Extend the existing local review path instead, as directed by the owner.

Constraints: no installation, migration, merge, connector-owned changes, provider calls or deployments. STOP ALL/external lock ON; automation OFF; RELEASE HOLD. No buyer text in audit logs or durable storage. Preserve old records and all prior runtime paths. Unsupported image regions and overflow deny explicitly; never silently crop, scale or truncate.

- [ ] Recover .17 and .25 package bytes, verify every manifest hash, preserve d32fc91a additive modules; reconcile source-test fixtures and run baseline unit/integration tests.
- [ ] Add LocalPersonalizationProofTest: real mug/poster SVG, deterministic hash binding, escaping, unsupported image region, missing/unknown fields, invalid UTF-8/control text and overflow. Observe RED; implement LocalPersonalizationProof::render(array $spec, string $artwork, array $samples): array; observe GREEN.
- [ ] Add authenticated Shop2ProductWorkflow::proof(array $link, string $design, array $samples): array|WP_Error. Resolve approved handoff, research lineage and geometry. Test cross-workflow, unapproved/digital access denial and no HTTP; wire proof_preview through existing panel and add proof/receipt to held bundle without changing saved package semantics.
- [ ] Run full composer, WordPress, offline/security and package checks. Review exact diff, commit and publish separate draft PR; inspect exact-head CI and report honestly.

Review focus: stale/cross-shop approval, script-like text, long wide-character text, image regions, changed artwork/geometry. All proof metadata must remain non-authorizing and must not claim accurate provider typography or production readiness.
