# FC01 v2 Option A Clear Center — offline concept candidate

Base: draft #1205 `bbb885a9c134c68b7fb06c85be24e313f3981b42`, exact-head audit #4737 PASS. Head verified unchanged. Repository AGENTS.md is absent; the available AGENTS/operating-manual/execution-ledger snapshots remain proposed until merged. Explicit current owner direction authorizes offline concept engineering only. Existing Blueprint v6.0/Master 500 source inventory and acceptance ledger are reused. The isolated branch retains all #1194–#1205 ancestry.

## Scope and provenance

New `includes/POD/FC01OfflineConcept.php` is an unregistered, read-only concept renderer. It has no REST/admin hooks, persistence, provider/credential access or production binding. It reuses `BitmapFontAdapter`, `LocalArtworkAdapter`, the safe generated-SVG inspector and existing raster codec. It does not register FC01 v2 in the v1 family library. All v1 files and existing production bindings are unchanged. Seven parent files are pinned and verified: original families/registry, FC01 SVG/PNG and preview manifest, original botanical SVG and component PNG.

All v2 assets live separately under `assets/pod-designs/fc01-v2/`. A single leaf path is derived from the original botanical source; the v1 source is not modified. The new ornament SVG and pinned RGBA PNG carry their own hashes. `spec.json` records the actual offline raster conversion method/version; runtime composition uses the pinned PNG, not an assumed generic SVG renderer. This does not establish cross-rasterizer visual certification or artwork rights.

The explicit bitmap font JSON contains hand-defined synthetic test glyphs: upper/lower ASCII letters, digits, selected punctuation and five precomposed accents (é, É, ö, ñ, Å). Upper/lower glyphs are distinct. No host font, substitution or commercial-font licensing claim exists. Unsupported characters, including emoji and combining acute marks, fail closed. The deliberately diagnostic bitmap appearance is not final typography or commercial quality approval.

## Owner direction implemented

- Shop identifier `personalized_pod`, family FC01, version 2 and exact spec SHA are required. Foreign/deferred shops, v1 requests, wrong hashes and extra authority/template/locale-approval claims are rejected.
- Fixed header: exact `OUR FAMILY`.
- Required `family_name`: nonblank exact UTF-8 bytes, maximum 48 Unicode code points. No THE, case/pluralization change, normalization, trimming, truncation or automatic shrink.
- Optional `established`: free text, maximum 48 code points. Missing or Unicode-whitespace-only values omit the entire line. Otherwise output is exactly `ESTABLISHED` + one ASCII space + the unchanged value; leading/trailing spaces in a nonblank value remain. No year/date parsing.
- Mixed control characters are unsupported; optional all-whitespace input can be omitted without trying to render invisible glyphs. Invalid UTF-8 is rejected. Limits apply before blank omission.
- Exact input hashing uses non-trimming UTF-8 JSON, separate from the existing Orders canonicalizer that trims string boundaries. Existing production canonicalization is not changed or silently adopted for this concept.

The 1000×1000 canvas is a concept canvas, not a supplier panel or print size. Regions (x/y/width/height): header 60/80/880/60; ornament 360/210/280/220; name 60/530/880/80; established 60/670/880/60. Fixed integer glyph scales are 4/3/2. Regions must be bounded and pairwise nonoverlapping; actual advances/heights must fit without shrinking. No supplier DPI, bleed, safe-zone or template compatibility is inferred.

`review.html` places the concept/fictional-input warning outside the artwork. Sample-only inputs are `Mira & Léon` and `2011`, not the v1 fictional sample copy or approved linguistic content. Preview/final SVG files are byte-identical to each other; preview/final PNG files are byte-identical to each other. SVG and PNG are different formats and do not share one byte hash. Both derive from the same pinned source state and exact inputs. No supplier print certification or cross-format perceptual metric is claimed.

## Asset hashes

Paths below are relative to `assets/pod-designs/fc01-v2/`.

| File | SHA-256 |
| --- | --- |
| `spec.json` | `19cdf8ba9755e91a8417591f74e4e0fff27e168506f491cc44510f623fd9cdb0` |
| `font.synthetic.json` | `04fa859f350adcab39b44f182d061a940b4050adc402ca6aa84a3e8c02749b6e` |
| `ornament.svg` | `4953c8f0a08eb4ea8b40c7e43272045a81efde9d371a7e6153adda13a6042a1d` |
| `ornament.png` | `c8419eb7199f59fca98994e18eec82064d64628f2122a6fe489bae350b4dbba6` |
| `sample-preview.svg` | `f24427f171a1076b82020c13eee037a96ec1174a3dce6a69c52c9c7b5a08e87f` |
| `sample-preview.png` | `f2373bb365d9ca98117ab1934731dddb0afe9a322604256d51857df4176b74f2` |
| `sample-final.svg` | `f24427f171a1076b82020c13eee037a96ec1174a3dce6a69c52c9c7b5a08e87f` |
| `sample-final.png` | `f2373bb365d9ca98117ab1934731dddb0afe9a322604256d51857df4176b74f2` |
| `provenance.json` | `7c44e2f9d798e5b093073f6b10528c95536637cd51275455cac7258dbad70d82` |
| `review.html` | `67d3f7a4141e6e83035bfe0083e0bbf979ab06986a1a49a8fc4a9724e2641a78` |

`provenance.json` additionally binds raw sample inputs, text runs, seven parent hashes, source spec/font/ornament identities, renderer source hash, review-overlay hash and artifact hashes. It has no fabricated timestamp, reviewer or receipt. Output remains acceptance UNVERIFIED, linguistic review MISSING, rights/supplier certification false and production/external execution/publishing authority false. en-US is only a proposed review locale, not an approved locale or linguistic finding.

## Verification

Tests first failed because the renderer was absent. Final focused native selection: **31 tests / 289 assertions PASS**, including 11 new concept cases and reused reviewed-contract, clipping, tampering and stored preview/render parity coverage. Related unit selection: **31 / 66 PASS**. Legacy suites, standard analysis/standards, additional WordPress-aware level-5 static analysis and `git diff --check` PASS. The local synthetic database container was restored before testing; no production/staging system was accessed.

The new cases cover required/blank name; absent/empty/Unicode-whitespace established; exact spaces/case/prefix; 48/49 code points; accents versus decomposed unsupported glyphs; emoji; punctuation/injection; bad UTF-8/control text; wrong shop/version/hash; no forged review/production claims; actual geometry overlap/clipping/overflow rejection; asset tampering; deterministic retry; v1 immutability; and external-only disclaimer. Negative geometry tests alter only the v2 spec temporarily and restore it in finally; v1 is never edited. Normal CI reads and byte-compares committed fixtures; `DF_FC01_CONCEPT_FIXTURE_DIR` is an opt-in local regeneration destination, not a production write API.

Reproduce focused concepts with `vendor/bin/phpunit -c phpunit.wordpress.xml.dist --filter FC01OfflineConceptTest` under the existing isolated WordPress test installation. Fixture regeneration uses the same test with the explicit destination environment variable; ordinary hosted tests do not regenerate or skip fixtures. Final exact-head hosted audit and independently verified remote SHA are recorded in the draft PR without an audit-only commit.

## Acceptance and owner actions

`BP-template-qa-spelling` remains **UNVERIFIED**; the matrix remains **246/268 implemented candidates, 22 UNVERIFIED**. No coordinator ledger, Finance, shared CI, migrations, v1 registry or existing PR is modified. This is an independently testable offline concept candidate, not a production-capable template or new ownership authority.

Next owner action: inspect `review.html`/sample images and give explicit visual/text revision findings. Commercial typography needs selected licensed font/glyph evidence; artwork derivative/display rights and independent linguistic review must bind exact artifacts and text/locale/version. Real template/panel/variant geometry, installed-site acceptance and production authorization remain separately unresolved. No live action is needed or authorized for this review.

STOP ALL ON; external safety lock ON; automation OFF; RELEASE HOLD preserved. No Finance changes, credentials, provider calls, staging/production writes, activation, publishing, orders, merges or deployments. Production impact: NONE.
