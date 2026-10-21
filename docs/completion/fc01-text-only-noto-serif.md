# FC01 text-only licensed-font engineering candidate

Baseline #1233 `974906c10c83206073ffe9bc890f3f8955b107a5`, audit4769 PASS. Existing issue1209 observations and source assets were reused. No separate Workstream1/2 Noto evidence packet was present in the issue comments, so official font bytes and license were obtained directly and pinned; no missing receipt is invented.

## Separate immutable concept

`assets/pod-designs/fc01-v2-text-only/` is FC01v2, design variant `text-only-noto-serif-v1`. It does not replace retained OptionA, v1, the family registry, any production template or a provider product. The botanical ornament is omitted entirely. Fixed header OUR FAMILY, exact required family_name and optional free-text established remain; both customer fields are bounded at48 Unicode code points. Empty/Unicode-whitespace-only established omits the entire line; nonblank uses exactly `ESTABLISHED ` plus the unchanged value. No case conversion, date parsing, normalization, truncation, substitution or automatic shrinking.

Diagnostic canvas900x700; header30px, name48px, optional line22px. These are concept typography sizes, not physical print resolution or supplier geometry. Exact nonoverlapping regions, glyph bearings, baseline metrics, advances and actual image dimensions are checked. Text may fail spatial fit before48 characters; count eligibility is not a promise that every48-character name fits. Unsupported scripts and decomposed combining sequences fail closed. The bundled157-character glyph subset supports ASCII and selected precomposed Latin-1 letters; it does not claim the complete Noto font's coverage. Individual BASIC glyph layout intentionally has no contextual shaping, kerning or ligatures; independent typography/linguistic review remains necessary.

## Official font evidence

Unmodified [Noto Serif Regular](https://github.com/notofonts/noto-fonts/blob/ffebf8c1ee449e544955a7e813c54f9b73848eac/hinted/ttf/NotoSerif/NotoSerif-Regular.ttf), embedded version2.007. Upstream commit `ffebf8c1ee449e544955a7e813c54f9b73848eac` is pinned rather than a mutable branch. Full [upstream license](https://github.com/notofonts/noto-fonts/blob/ffebf8c1ee449e544955a7e813c54f9b73848eac/LICENSE), font copyright, source URLs, version and hashes are preserved in `font/`.

- Font SHA256 `c8f669ceb2c9c60ccf55198b305e08a997ffca79a38cc7eeb551e643cbe66505`.
- SIL OFL1.1 license SHA256 `0dab92d0544f7b233403f14b84a663bdbfa746982eda629e7f4f9ffe1b036feb`.
- Glyph atlas SHA256 `40db3af24bfca02ef9abc32fffbf69fe7fb1816c775443939854346c4ff83e75`.

Pillow13.0.0/FreeType2.14.3 build actual transparent glyph pixels from the pinned font; no synthetic glyph substitution or system fonts. The existing offline pinned Pillow runtime suffices; no new production dependency, network worker or font API is added. `bin/build-fc01-text-glyphs.py` is an explicit offline rebuild tool; it does not download sources. The selected subset was checked against the actual font cmap using fontTools4.61.1 during acquisition. A byte-identical rebuild test uses only the pinned runtime and bundled sources.

The runtime renderer uses existing RasterArtworkCodec/LocalArtworkAdapter composition and the existing autoloader. It verifies source/license/provenance/atlas hashes and immutable parent hashes, rejects malformed or missing glyphs and out-of-region ink, and emits preview and offline-final from one canonical SVG/PNG payload. There is no REST registration, database, privileged action, provider request or automatic approval. Font license evidence is not whole-artwork rights certification or a legal warranty; no modified font or Printify font is redistributed.

## Review and acceptance boundary

`review.html` provides an owner-facing comparison outside the artwork. The candidate uses fictional sample text only; exact render/source hashes are in spec/provenance. Required reviews: visual balance and legibility, independent en-US linguistic review of exact text/allowed exceptions, permitted commercial use of the selected final design, and exact reviewed artifact hashes. All reviews remain MISSING; overall rights_certified=false, production binding UNKNOWN and acceptance UNVERIFIED. No mockup license is claimed because no product mockup is supplied.

Focused regressions cover deterministic real-font rendering, same-format preview/final bytes, optional omission, whitespace, accents,48/49 code points, long-name overflow, unsupported shaping/glyphs, injection, ink clipping, nonoverlapping regions, wrong shop/version/source, license/font/atlas tampering and retained-parent immutability. Original FC01 source/renderer/assets and production bindings are unchanged.

Supplier dimensions, exact provider/variant/method/placement, authoritative safe/bleed/DPI, native personalized inputs/overflow/optional omission and provider-render parity remain missing. Observed3951x4800px/300DPI and embroidery guides are not used as this concept's print specification. Actual Printify personalization remains disabled; no Etsy listing or provider edit is made. Preserve all22 non-Finance and23 Finance-dependent UNVERIFIED criteria; source/license integrity and local tests do not grant authentic acceptance.

STOP ALL ON; external lock ON; automation OFF; RELEASE HOLD; NO-GO. No Finance changes, credentials, live provider calls, orders, publishing, production/staging writes, merges, deployments or activation.

Focused final verification: all FC01 WordPress scenarios55tests/1224assertions PASS; real-font offline2tests PASS; configured PHPStan8 and coding standards PASS; protected acceptance matrix checker PASS with classifications unchanged. Preview/final PNG SHA256 `f01e0a280f4477a6d35efb911d6354cf38ab14383d9094e6fb0c30134f0086b2`; SVG `4238e82633c55b03634b9cff08c0c6a7e28878499d065f9d1527440dd26e338e`; spec `551cad607cec07315d5335a768d8759685a9486039c62146003d149979740d8c`. Full exact-head hosted audit remains required; its receipt belongs in issue1209 and PR metadata without a self-referential follow-up commit.
