# Ordinary photo crop and slot ordering — accelerated batch

Scope: S2-ART-14 implementation progress; S2-ART-15/S2-ORD-06 remain partial.
Reuses November15 full151 audit, existing customer editor, protected storage,
normalizer, immutable head/transaction/audit, source slots and REST security gates.
No ledger row is promoted without independent acceptance. No commercial artwork,
catalog or publication work. AI execution and five transformation engines are not
implemented by this photo batch. Their existing deny/import boundaries remain.

## Executable journey

Authorized buyer opens the existing personalization editor shortcode/order link.
Choose ordinary photo mode, upload PNG/JPEG, select an integer crop rectangle using
pointer or numeric keyboard controls, confirm rights/processing, save. EXIF orientation
is normalized before crop. Existing pinned Pillow13 normalizer is required; unavailable
runtime fails closed, no fallback to unnormalized JPEG. Source bounds1,048,576pixels,
4.5MB and existing request/slot/revision bounds retained. Pure PhotoCrop copies exactly
the selected RGBA rows without interpolation/resizing; its receipt binds source,
rectangle and output. Original bytes are separately retained with decoder provenance.

Upload each image slot, then use Move earlier/later to choose placement order. Every
slot must have a valid retained output before ordering. Source→target permutation
is explicit and complete, current bytes are rechecked, and a new immutable version
records its exact parent. Replacement/reordering require current expected_head and
invalidate the prior local preview/approval; they do not erase history. Unsubmitted
text/photo changes must be saved before reordering. Missing/duplicate/foreign slot,
stale head, corrupted bytes and unauthorized actors deny without selecting a fallback.

Photo mode is explicitly not an alpha cutout. Compatibility output key `cutout`
contains processed bytes for the existing view renderer; `generation.mode=PHOTO`,
`alpha_cutout=false`, view.kind=PHOTO and crop provenance disambiguate it. It cannot
enter CustomerCutoutRepository via operatorIntake, nor the existing cutout-only
product-preview publication path. No false segmentation/face certification. Existing
MANUAL/AUTO cutout handling remains unchanged. A separately implemented reviewed
photo-to-render binding is required before a photo product preview becomes available.

## Stable interfaces and Lane B boundary

Existing POST /customer-editor/{session}/upload accepts mode PHOTO, with selection
exactly {x,y,width,height} in normalized pixels. Other input keys unchanged.
Existing POST .../fields accepts exactly ONE of:
{expected_head,fields} (legacy) or {expected_head,image_order} (ordered source slot keys).
No new REST route, security exception, handler or B-owned route inventory modification.
CustomerEditorRepository::reorderImages(session,expectedHead,orderedSourceSlotKeys)
uses the existing transaction/advisory lock/CAS and bounded immutable version history.

Existing `access(session)` authenticates current buyer/operator/order/line/schema and
revocation; `view(session)` returns the current editor version SHA, images, fields,
reviewed product_preview and history. expected_head selects only the current EDITOR
DRAFT version. This is not a canonical order-line personalization submission selection,
operator approval, signed provider permit, allocation or B PersonalizationCurrentness
acceptance. No such authority is fabricated here. B still needs a separate agreed
canonical submission reselection/supersession accessor and final renderer/verifier
currentness receipt. New photo final-artwork adoption is intentionally unavailable.

## Acceptance and next batch

Tests use synthetic source bitmaps/JPEG, actual WordPress REST dispatch and the
existing source→product→order/customer-access fixture. Browser evidence intercepts
fixture transport and proves desktop/tablet/mobile keyboard UI, not live buyer or
provider acceptance. No credentials or outbound provider calls. Exact-head hosted
engineering audit requested separately; never infer success from local assertions.

Next Lane A batches: reviewed ordinary-photo operator-to-render binding/current
buyer submission selection; controlled injectable image provider execution with
existing monetary reservation/usage and UNKNOWN/cancel recovery; source acquisition
freshness/dedupe/quantity adapters; five artistic execution paths; integrated normal
operator UI and independent acceptance. Existing151 audit CP01/02/03/09 estimates
remain the full-scope planning basis; previous4–7day estimate covered narrower slices,
not the full151. The48-hour sprint targets executable batches, not fabricated complete
capability/host/provider certification.

STOP ALL ON; external lock ON; automation OFF; manual approval; RELEASE HOLD.
No merge, deployment, provider calls/writes, Etsy publication, orders or production.
