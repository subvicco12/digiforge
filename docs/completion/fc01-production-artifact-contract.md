# FC01 bounded production-resolution preparation

Baseline: draft #1234 `b20ec531bc5bdae1c70ee0d3c981987f8b2739f8`, exact-head audit4770 PASS. This additive offline compiler leaves all v1, retained OptionA, text-only900x700 assets/renderers/manifests and existing production bindings unchanged. It does not register a route, queue, installer, database writer, delivery path or live action.

## Request and admission

`FC01ProductionArtifact::prepare()` accepts only exact shop_key (`personalized_pod`), template_key/version/SHA256, selected variant_id, position, decoration_method, geometry_review_scope and personalization (required family_name, optional established). It requires an authenticated explicitly assigned POD operator in the actual WordPress **local** environment. Production, staging and other runtime environments deny. Offline source evidence may explicitly describe sandbox/test/production; that source label never changes the runtime restriction.

The stored GEOMETRY_LOCKED/VALIDATED Printify template must reproduce its normalized fingerprint, exact current catalog bytes/provenance and existing single-shop template reservation. Its placement/method/dimensions and every selected variant binding must agree. No ZIP is required: catalog rectangles remain useful, but **rectangles alone cannot admit an export**. DPI, safe zone, explicit seam exclusions and complete bleed/trim/margins must satisfy existing ArtworkGeometryQa. Missing specifications block rather than default to300DPI, zero margins or guessed zones. Explicit reviewed zero margins are allowed; the synthetic fixture uses them and makes no supplier claim.

The existing independently signed **v2 BP-seam-panel-render** contract must still be current: exact environment, template hash, entire panel geometry, current observation window, catalog response hash references and valid alignment/parity declaration. Existing review authority, expiry, revocation and append-only audit validation are reused. No callback, caller approval boolean, parallel review store or new privilege is introduced. Review/reference hashes bind declarations; they do **not** independently authenticate supplier statements. Authentic source acquisition and certification remain separate gates, and opaque references are never claimed resolved. The contract is deliberately more conservative than a rectangle-only research preview.

Schema `fc01-production-artifact-request-v1` binds exact provider/blueprint/provider-ID/variant, method/placement, source-revision/response/placeholder hashes, dimensions/DPI/QA geometry, current review revision/hash/expiry, immutable FC01 identity, pinned font/license/provenance, compiler/request-builder source hashes, exact personalization bytes/input hash, text runs and regions. Canonical request SHA256 detects any byte-equivalent material change. Changing template/catalog/source/review requires a fresh request; a hash is not authorization.

## Export

`export()` launches only the explicit existing offline Python runtime, without a shell or inherited credentials. The pinned Pillow13.0.0/FreeType2.14.3 worker loads the bundled unmodified NotoSerif2.007 TTF and verifies its source/license hashes. It rasterizes fresh font outlines at target size, **not** an enlarged900x700 bitmap. The retained text-region proportions are uniformly centered in the supplied safe zone; fixed font sizes scale with that geometry. Long inputs never cause auto-shrink, wrapping, truncation, case changes, normalization, date parsing or glyph substitution. The existing concept eligibility/overflow guard remains an additional conservative prerequisite. Scales below the concept's original size are unsupported.

PNG background is transparent; header/name/optional-line positions and text color follow the text-only concept. This is a separate unreviewed export choice, not a revision of the cream-backed concept or approved production artwork. Same-format preview and offline-final are **identical full-resolution PNG bytes** from one request. This proves local artifact parity, not native Printify parity. Per-glyph bearings/ink, safe-zone bounds and seams are checked before drawing; aggregate ink is rechecked by the existing PHP geometry QA. PNG pHYs carries the supplied DPI (PNG's integral pixels-per-metre conversion has normal rounding).

Supported local raster methods: dtg, dtf, sublimation; no embroidery stitches, AOP/UV warps, Choice routing resolution, physical tolerances, native text layers, contextual shaping, kerning/ligatures or automatic fit. The same157-character ASCII/precomposed Latin subset remains supported; decomposed accents/complex scripts/emoji fail closed. Required family_name and optional free-text established remain exactUTF8/max48codepoints; whitespace-only established omits its whole line. Count eligibility does not guarantee spatial fit.

Bounds: dimensions<=10000 per axis and<=25million pixels;768MiB worker address space;20CPU seconds;30second parent deadline;128KiB JSON input;8MiB PNG/12MB worker response. Excess/unsupported resources deny without fallback or automatic retry. Source paths and font are fixed, not supplied by the caller. Worker errors are generic and omit buyer text. No network library, provider client or credentials are used. After the worker, the PHP boundary re-reads all admission state and withholds output on drift/revocation/expiry. Returned artifacts are in memory, UNREVIEWED/UNVERIFIED, rights/supplier/production/external/publishing authorityfalse. Review, custody and provider delivery are not performed.

The CLI is a local compiler of **untrusted requests**, not an authorization oracle: its receipt cannot be imported as an approval. Only the PHP admission/revalidation path checks current local records. Direct CLI synthetic tests prove rendering, not a live binding.

## Reproducible synthetic evidence

`tests/offline/fixtures/fc01-production/` contains an explicitly fictional1800x1400px/300DPI geometry declaration, request, identical transparent preview/final PNGs and receipt. These numbers are **test inputs**, not Bella+Canvas or any selected supplier specifications. Test-only review times/identities and source hashes are synthetic; no authentic approval is fabricated. They do not create WordPress records or pass installed-site admission on their own.

Use the existing pinned offline Python runtime:

```
python -B -m unittest discover -s tests/offline -p test_fc01_production_artifact.py -v
python -B includes/POD/offline/fc01_artifact.py < tests/offline/fixtures/fc01-production/synthetic-request.json
```

Focused native WordPress tests use the real stored catalog/template and independently reviewed contract lifecycle, including HTTP-attempt denial, scope loss, catalog revision drift, missing evidence, unsupported text, real Python export where configured and revocation during compilation. Without the processor, the export denial is tested; the dedicated offline real-font tests remain mandatory. No CI changes, security skips or new dependencies.

## Blockers and acceptance

The actual Shop2 Bella+Canvas product still lacks independently authenticated exact provider/blueprint/variant/method/placement and supplementary safe/bleed/DPI evidence. Dashboard3951x4800/300DPI and the separate embroidery ZIP are not promoted into this contract. Choice routing/scaling, native personalization/optional-line/font behavior, actual provider preview/final parity, source-art commercial review, independent linguistic review, approved locale, mockup rights, buyer-specific artwork approval and production authority remain unavailable. An explicit reviewed fixed-provider rectangle cannot imply a Choice product mapping.

Owner next action: supply exact selected-variant official catalog/placement evidence plus separately sourced DPI/safe/bleed/seam specifications (or explicit authoritative non-applicability), with version/date/source bytes and independent review. Then review the exact generated text-only artifact and proposed typography/locale. No provider activation is needed or authorized for collection.

All22 non-Finance and23 Finance-dependent UNVERIFIED criteria remain unchanged; Master500 remains500identities/360boundedrecipes/140blockedartistic concepts. Only local software evidence notes are extended. STOP ALL ON, external lock ON, automation OFF, RELEASE HOLD; **NO-GO**.

Focused evidence:63FC01 WordPress cases/1305assertions PASS (local processor-unavailable branch); dedicated real-worker WordPress8cases/94assertions PASS including mid-render revocation and Unicode line-separator parity;9FC01 offline tests PASS, including the7new export tests and2existing real-font tests. Configured static/standards checks PASS (the configured PHPStan scope remains its existing narrow scope, not a claim of full new-class analysis). Synthetic PNG preview/final SHA256 `577ff7687f57c6c4e6efc11e89540b07ab31cf071acc04ff126a0fecbebae38f`; request file SHA256 `7e234c6b08106c24bd13138fcc9f90608f8e5bdbcc5da731bb1caf063cb928ba`. One real CLI sample run measured0.215seconds wall and28548KiB peak childRSS on this workspace; this is not a general worst-case or supplier-quality benchmark. Mandatory hosted exact-head audit is recorded in the PR/issue receipt after publication.
