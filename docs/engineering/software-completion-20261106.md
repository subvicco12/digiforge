# Portal and Printify software completion — 1.0.103.5

Scope follows the nine dedicated blueprint groups, PR1253 and Issue1252. There is no required endpoint count. No live supplier operation or production enrollment has occurred.

| Blueprint group | Implemented / reused | Remaining acceptance |
|---|---|---|
| Transport and identity | Existing credential-vault binding, strict GET endpoint contract, TLS/redirect denial and governed scoped pipeline | Verify installed account/shop ownership and approved binding externally |
| Catalog | Added explicit blueprint-index scope and source-backed index mapping; reused blueprint, provider, variant, size-guide and print-area projections | Obtain actual provider observations under separately approved grants; pixel dimensions do not certify physical templates |
| Shipping | Existing service/destination quote evidence; supplier review distinguishes index-only evidence from exact variant destination quotes | Destination coverage, units and current rates remain unverified until observed |
| Cost / tax | Existing raw evidence projections and explicit unknown production cost/tax | No Finance authority or invented economics |
| Master 500 | Existing immutable import, original concept lineage and source hashes | Actual retained research completeness; historical counts are not certified current totals |
| Governed collection | Existing ten-request ceiling, safety exceptions, reservations, receipt hashing, idempotency and interruption/resume | No grant, exception, reservation or live call issued by this work |
| Shop 2 UI | Existing mobile panel; new exact supplier-selection review alongside installed factory workflows | Approved template/native-personalization/mockup/product-package gates remain mandatory; no automatic promotion |
| Exports / imports | Added bounded server-side XLSX alongside existing nonce-protected CSV/JSON and local research import | Drive authorization is not transferable to supplier or runner credentials |
| Security / isolation | Existing deny-by-default role/module/shop guards and Ed25519-only authority retained; new owner preflight uses canonical authority resolution | Production owner enrollment still requires verified native account, trusted signed envelope, matching approval and explicit enrollment |

## Integration and preservation

Build uses the verified installed-lineage 1.0.103.2 ZIP (SHA-256 `800a29e7bca92bcf76804747d4a23f15329b38606649d570df25e1f109fe7677`), never an entire source-checkout replacement. 686 existing files retained byte-for-byte, six intentional modifications, eighteen additions, zero deletions. Portal.php and all unrelated factory, publishing and Finance components remain installed-baseline bytes. Existing restrictive ACL and Ed25519 verifier retain their exact hashes. No new schema migration.

Six modifications: version/bootstrap; restrictive native ACL consumer overlay; exact admin-handler guard integration; Printify catalog contract (explicit index plus retained source pagination denial); research mapper index projection; existing Shop 2 panel (XLSX and supplier evidence review). API overlay preimage/candidate hashes are pinned in `printify-software-overlay.json`; the original shared-core manifest remains intact with a reversible, explicit catalog-scope overlay receipt.

Login/logout, native WordPress password recovery, manual private invitations, owner-managed roles, exact shop/module permissions, revocation and DigiForge-only disablement are reused from PR1258. Owner preflight now reports specific blockers and requires explicit base/settings capability selection. It grants nothing. Production signed authority is never generated or provisioned by this feature.

## Release boundary

STOP ALL ON; external safety lock ON; automation OFF; RELEASE HOLD. No production modification, merge, supplier request, publishing or authority provisioning. Source CI and extracted-package tests are different evidence. No hosted exact-ZIP audit PASS is claimed; inherited private-intake limitations are not bypassed. Full-site recovery and current installed-source/schema acceptance remain installation gates. The software candidate does not authorize installation.
