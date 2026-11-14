# Shop 2 native business resolver — Issue 1266

Preserve audited05091b95 and frozen1265. Lane B owns new NativeBusinessEvidence.php and NativeBusinessEvidenceTest.php; portal/research/design/shared package files remain Lane A-owned.

First increment: authenticate exact reviewed ShopBinding, query genuine approved product-version, product/family, production plan, Printify mapping and unique approved ACTIVE business/store/program ownership. Read a consistent InnoDB snapshot without committing caller work; after commit re-read the dependencies and scope/session. Return actual stored version IDs and bounded canonical dependency hashes; reject stale expected hashes, ambiguity, revocation, cross-shop identity and uncertain storage. Do not invent order versions, artwork links, provider facts or allocation authority. Provider-dependent operations remain held.

Tests first: positive records created/approved through existing repositories in disposable WordPress; negative changed version/ownership/mapping, ambiguous ownership, revoked scope, stale hash, read/commit failure and concurrent post-snapshot changes. Then implement the resolver and run focused tests. This feeds subsequent approved artwork/template/listing binding, not a replacement workflow or API plugin.


Second increment — versioned approved source binding (Lane B-owned new NativeBusinessArtworkEvidence.php):

An asset spec stores `content_requirements.native_artwork_binding` with exactly `schema=DF_NATIVE_ARTWORK_BINDING_1`, `shop_key=personalized_pod`, `design_factory_hash`, `supplier_workflow_hash`, `artwork_version_hash`. Hashes must be genuine current workflow outputs, never labels or caller approval flags. The spec must be APPROVED, 300 DPI SVG at the exact technical canvas; its approval and required production-plan link must precede the plan approval. The resolver reuses DesignFactory handoff and supplier workflow/context/geometry readers, then revalidates the business dependencies after the read-only snapshot.

`resolve(scope, actor, mappingId, specId, expectedHash?)` returns CURRENT_PROTECTED_SNAPSHOT evidence, real artwork version/hash, catalog selection and native regions. It does not prove independent durability, saved-provider-product mapping, safe areas, typography, allocation or provider certification. Stale expected evidence is denied. No capability is enabled or grant issued.

Held composition reuses the existing approved package export and supplier workflow export. It must match the bound artwork and design exactly; the original package seal remains unchanged. Any surrounding business association is explicitly technical and unapproved, not a substitute for a human commercial decision. Missing approval denies export; unknown supplier/fee/routing facts and existing blockers remain present. No automatic publication, order submission or provider mutation is introduced.

Focused verification: 23 WordPress cases / 262 assertions PASS for current increment, including FL03/FL04 with three separate text regions, existing full approval-to-held-package workflow, changed source/spec denial, and the independently reproduced final-export session revocation race. The race was RED (1/37, returned success after revocation), then fixed by final business/artwork and live research/listing authorization checks after both exports. Synthetic approvals exist only in disposable tests. Complete extracted-artifact and exact-head audit acceptance remain required; no execution capability enabled.
