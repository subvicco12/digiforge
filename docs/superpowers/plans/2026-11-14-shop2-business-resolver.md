# Shop 2 native business resolver — Issue 1266

Preserve audited05091b95 and frozen1265. Lane B owns new NativeBusinessEvidence.php and NativeBusinessEvidenceTest.php; portal/research/design/shared package files remain Lane A-owned.

First increment: authenticate exact reviewed ShopBinding, query genuine approved product-version, product/family, production plan, Printify mapping and unique approved ACTIVE business/store/program ownership. Read a consistent InnoDB snapshot without committing caller work; after commit re-read the dependencies and scope/session. Return actual stored version IDs and bounded canonical dependency hashes; reject stale expected hashes, ambiguity, revocation, cross-shop identity and uncertain storage. Do not invent order versions, artwork links, provider facts or allocation authority. Provider-dependent operations remain held.

Tests first: positive records created/approved through existing repositories in disposable WordPress; negative changed version/ownership/mapping, ambiguous ownership, revoked scope, stale hash, read/commit failure and concurrent post-snapshot changes. Then implement the resolver and run focused tests. This feeds subsequent approved artwork/template/listing binding, not a replacement workflow or API plugin.
