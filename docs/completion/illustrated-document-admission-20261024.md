# Illustrated Digital source admission — bounded increment

Baseline9b00de3a43573726d334956f0cc742acaa6c2f41 / audit4789. No historical Product25 identity, original-byte change or commercial approval.

## Reproduced rejection and supported subset

Both exact recovered Wedding v0.5 DOCX sources contain `word/media/image1.png`, five inline picture uses, `word/header1.xml`, internal image/header relationships and explicit PNG/header content types. The old verifier rejected those parts and drawing/header references. Its layout heuristic also interleaved neighboring card columns despite intact content. The narrow illustrated path now checks raw PDF content-stream word order against the exact source sequence, with the original literal-glyph/Unicode-whitespace-only comparison and every word's page/body/header/footer bounds. Plain documents retain the original layout-order path.

Only non-interlaced8-bit RGBA PNG is supported: IHDR/consecutive IDAT/IEND only, CRC, exact decompression size, no trailing data, bounded dimensions/pixels, no ancillary/animated chunks. Only complete inline rectangular, zero-offset, uncropped, aspect-preserving image trees are supported; no floating anchors, effects, SVG, linked images or arbitrary drawings. Every media item must be bound to an internal relationship and referenced by a permitted picture. Maximum16images/drawings;4,194,304-pixel bound and existing8MiB member/32MiB total bounds remain enforced. Resource limits remain active.

Only one default plain-text header is allowed, explicitly bound to header1.xml and its header content type. Each exported page must contain exactly the source header text in the declared top-margin band; missing, forged, extra or misplaced header text rejects. Existing PAGE footer policy remains unchanged. External relationships, archive/relationship traversal, duplicate members/relationship identities, excessive compression, macros, executable/embedded parts, unsupported fields/active content and original negative paths reject. This is not a universal DOCX importer or PDF malware/sanitization certificate.

`source_layout_profile=INLINE_RGBA8_PNG_STATIC_HEADER_V1` identifies this narrow subtype under the existing source-text/body-bounds profile. `artwork_export_parity_verified=false`, `visual_approved=false`, `rights_certified=false`, `production_authorized=false`. PNG source integrity is bound by the original DOCX SHA; this does not prove image pixel parity in the PDF. The independent visual/content/IP/policy gates remain mandatory. No automated artwork or commercial approval is added. Old immutable receipts are preserved, not rewritten; the verifier hash pins fresh evidence.

## Actual exact-file trial

`bin/verify-wedding-v05-admission.py LETTER_DOCX LETTER_PDF A4_DOCX A4_PDF` pins all four hashes, verifies52distinct/55total fields in each source, nine-page source/export receipts, and rejects separately tampered header/PNG copies. Original bytes are checked unchanged afterward. Results: `wedding-v05-illustrated-admission-20261024.json`. Original product files are not published into this public repository; authorized local originals were used.

Letter source0a9fabc469d217827ef50f645324c5bbda34f897d969092d21582034eaf10168; exportef8b759106936b6e63d0874d61cbc379c7dd36c35f0806f67adf38bbc6052f7f. A4 source41170901605d7148ecb61c8ae32e3c6ab1ba9f1095a16dfb3a60831fe274a56c; export1420880bba0eeda13334269f9e6634dd54aa13fc312b4cedfe3e2d5ed504ef3e. Both exact trials PASS technical checks only, same normalized-text hash, no product registration.

## Validation and remaining work

Focused Python17tests PASS, including all11 existing negatives and six illustrated cases with adversarial subcases. Existing grant/admission PHP12tests/77assertions and all three legacy suites PASS. Configured static/style checks PASS. Two real WordPress custody/gate tests authored for hosted CI (not claimed locally). Local full unit reports the unavailable cutout-worker environment (the previous batch also recorded SIGBUS in the pinned OpenCV import); hosted exact-head audit is authoritative for mandatory suite completion. No CI/gate weakening.

Google trusted issuance/account binding, OAuth credential/session lifecycle and guarded dispatch remain NOT IMPLEMENTED; quota/replay/revocation journal is preserved, not a live authority. All22 non-Finance criteria remain UNVERIFIED pending exact semantic, rights, authentic provider/process and installed-site evidence. Finance excluded. STOP ALL ON; external lock ON; automation OFF; RELEASE HOLD; NO-GO. No live Google/provider call, production/staging write, publishing, order, merge, deployment or activation.
