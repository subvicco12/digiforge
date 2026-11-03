# Reference matte creation

1. Use an original with documented commercial rights, ownership/source, and subject consent where applicable. Avoid public photos with unclear rights.
2. Preserve an immutable original and record SHA-256. Make editing copies; do not modify the original evidence.
3. Annotate the intended head/hair/ears/fur, preserving naturally visible fine detail. For multi-subject images, specify the selected subject and exclude all others.
4. Export a **single-channel continuous 8-bit or 16-bit alpha matte** with exactly the source image's pixel dimensions, orientation and crop. Alpha 0=background, max=foreground, intermediate values=partially transparent. Avoid binary-only masks as reference for hair/fur.
5. Keep a separate annotated preview on checkerboard, light and dark backgrounds. Do not bake the checkerboard into the matte.
6. Record annotator identity, annotation software/version, source and matte hashes, and editing history.
7. An independent designated reviewer compares the matte to the source at full resolution, focusing on ears, whiskers, hair, halos, holes, and image-border contacts. Document disagreements and revisions.
8. Freeze the approved reference and bind its hash to review records. Any change invalidates prior review.

Reference mattes must be manually verified; model-produced predictions cannot be relabeled as ground truth without independent annotation and review. If fine whiskers cannot be reliably annotated, mark the region/metric NOT ASSESSABLE rather than silently treating it as correct.
