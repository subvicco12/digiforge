# Owner-supplied Printify guide ZIP validation

Base: draft #1216 `3768c8f06f3b9512ccb3c26ff2e4eb02c4a4097b`, exact-head audit #4749 PASS; main `844107ab9baf7e1303cd47708e9eab3bc700b348`. Owner input was accessible in this execution environment. No provider request or account access occurred.

## Original bytes and provenance

`69721e8e710d4f37a20a8cc2.zip`, **353,969 bytes**, SHA-256 **`1e474c5c4c652f36ca02d061ee5331c93deec349ce1fb4d442a51ccf49c316a6`**, matches the supplied digest exactly. Bounded inspection rejected traversal, absolute/backslash paths, duplicate archive names, symlinks and encryption; every file was read with ZIP CRC verification before selected PNGs were written to a separate scratch directory. Seven PNGs passed independent bounded Pillow structure/CRC and complete pixel decoding. Original bytes were preserved. No archive scripts were executed. Original assets, resource-fork bodies and private filesystem metadata are not committed.

The generated companion JSON records **all 16 file-member hashes**, dimensions, PNG chunks, nominal embedded DPI and source/compatibility classification. The archive has **17 entries: seven PNGs, nine macOS metadata files, one directory**. It contains no supplier JSON manifest, authoritative catalog IDs or version record. The generated manifest is forensic observation, not a supplier-signed specification.

**OWNER_CONFIRMED provenance:** direct download from Bella+Canvas 3001 Product Creator. This does not independently verify shop, product, blueprint, provider, variant, region, placement API identity, supplier version, rights or production authority. The original screenshot is not attached in this task; **3951 × 4800 front print area is OWNER_REPORTED**, not independently observed screenshot evidence. The ZIP filename is not inferred to be a product or supplier ID. Download time and supplier template version remain UNKNOWN.

| File | Actual PNG canvas | SHA-256 |
| --- | --- | --- |
| Embroidery info.png | 1000 × 1000 | `9a157cd0fe32daceb108912c5844c3ce39f97f4d01923576972a4ea69fa89a07` |
| Template info.png | 1000 × 1000 | `086189a393a5fbc38f9ac3fc602b6d463dc71ed00af1906a29a5760992344b95` |
| Right_Sleeve_Embroidery_Template.png | 600 × 900 | `fde58f100ec8b5bf07bf3693aa50d532495ac1985366170f6a7f01d7f7b1f5b3` |
| Left_Sleeve_Embroidery_Template.png | 600 × 900 | `1ba40153df2037439fa3ce3a3e74cc50a38823ef7907aa392c1b5e929a4e99de` |
| Center_Chest_Embroidery_Template.png | 1200 × 1200 | `998c49458a773ce79f8521340ca67b0c663335838d0c9811d71898e07d85f26b` |
| Left_Chest_Embroidery_Template.png | 1200 × 1200 | `ac03547fafb7eb0932791654f3c3e9860ce74c0bf1e0d9853ae0a24e305df6ff` |
| Large_Embroidery_Template.png | 3000 × 1800 | `6bae8d0407a949ac28f25ffa7833ae8e5f66b0f4708688267f7dccf5cb8dde33` |

## Placement compatibility and source limits

Retain **all five separate source guides**; the presence of other placements does not invalidate their file-level evidence. Names describe embroidery placements, not verified Printify placement identifiers. None has a 3951 × 4800 canvas, and none may certify that front print area. Decoration method and selected placement must match independently bound provider evidence even if dimensions happen to coincide. No provider placement IDs are invented from these names.

The information sheet specifies up to six embroidery palette colors, avoiding small text/thin lines, internal transparent spaces, distressed/textured graphics, photographic images and gradients. These are observed source instructions, not execution authorization or automatically verified artwork. The second sheet distinguishes full upload size, print area, safe area and bleed area. **PNG canvas size is not a measured print/safe/bleed rectangle**. No numeric rectangle or seam geometry is inferred from colors or a mockup.

Embedded PNG metadata is approximately 300 DPI for the five guides and 72 DPI for the two information images. This is observed file metadata, not authoritative required DPI for the reported front print area or every variant.

The existing bounded production raster codec rejects all original guide PNGs: two information images and sleeves encounter unsupported `pHYs` ancillary metadata (guides also contain `iTXt`); the two 1200-square guides and the large guide exceed its 1,048,576-pixel bound first. This is **not corrupt source evidence or grounds to discard the archive**: guide records are retained separately from customer artwork. No decoder allowlist, pixel bound or production certification gate is relaxed. These originals are not approved production artwork.

## Demonstrated defect and focused tests

`ProductionTemplateContract::fromPrintifyVariants` cast caller dimensions to integers before comparing them to bound catalog dimensions. Four width cases (`1800.5`, `1800garbage`, `1800.0`, `1.8e3`) were accepted on unchanged #1216, contrary to the existing integer dimension contract. Reuse `positiveInt` for both dimensions: fractional, suffixed and exponent values now reject; legacy digit-only integer strings remain compatible. No geometry, provider ID, status, version, method or placement is inferred or rewritten.

`PrintifyNativeTemplateGeometryContractTest` now covers width/height malformed values, integer-string compatibility, matching five-placement **synthetic** bindings, wrong method despite matching pixels, the reported 3951 × 4800 mismatch and duplicate placement rejection. Synthetic API slots/IDs are explicitly fictional, not bindings for the actual archive. Existing missing/forged source, wrong variant, unlisted placement, immutable-version, catalog drift and stored panel tests are reused. The constructor still requires complete authoritative production geometry; a source ZIP bundle is not silently converted into such a template.

Focused units **58 tests / 110 assertions PASS**; native WordPress drift/mapping/panel checks **6 / 46 PASS**; targeted WordPress-aware static analysis and diff check PASS. Required final exact-head audit is recorded on the PR/issue after publication, without altering the audited tree just to record its run.

## Acceptance and smallest owner evidence

New verified facts: original archive digest, member bytes/hashes, decoded PNG canvases/content and retained five-placement source inventory. **Supplier compatibility remains UNVERIFIED.** No source is automatically bound, activated, promoted or certified. The authoritative matrix remains 246/268 implemented candidates / 22 UNVERIFIED; relevant seam/supplier rows gain only this limited evidence reference.

Minimum next input is a dated redacted existing-product record tying the selected shop/product to exact blueprint/provider/variant/region/decoration/placement, and the authoritative front-print guide/specification for 3951 × 4800 (or evidence explaining the difference). Also needed: supplier template revision, explicit safe/bleed and resolution specifications, commercial/derivative/display rights, and independent review. A separate embroidery binding may use retained embroidery evidence once its actual identities/specifications are verified. No need to redownload or discard this valid source archive, infer a front-print template from embroidery, or approve production.

STOP ALL ON; external lock ON; automation OFF; RELEASE HOLD. Finance excluded. No real credentials, live provider calls, production/staging writes, product changes, orders, publishing, merges or deployments. NO RELEASE.
