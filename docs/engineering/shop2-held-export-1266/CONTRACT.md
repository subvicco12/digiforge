# W-1266-03 — complete Shop2 held export contract

Scope: local nonexecuting held review artifacts, FL03 mug/FL04 poster. No native allocation, production, provider save/upload, publication or credential authority. All synthetic approvals in tests are explicitly synthetic. Current provider/media/geometry/typography/commercial/release gates remain separate.

## Server selection and package intake

Use the existing supplier-mounted source→concept→approved artwork journey. Map every native field, generate a valid bounded local proof, and record an explicit local APPROVE review. Lane A ArtworkPersonalizationPanel selects that exact reviewed proof through ArtworkPersonalizationIntake::selectReviewed. An already-reviewed legacy sample needs the explicit Select reviewed sample action. A GET, export request, filename or caller review flag cannot select anything.

For package_prepare on the existing digiforge_shop2_product_workflow POST action, retain shop_key=personalized_pod, supplier_workflow_hash, workflow_hash, factory_hash and complete evidence_json (unchanged65536byte budget). Supply these existing approved core record selectors:

- business_integration_id: current reviewed Printify connection ID.
- business_mapping_id: current approved provider mapping ID.
- business_asset_spec_id: current approved artwork spec ID, required by the current approved production plan.

Each ID is a positive bounded integer, never authority. NativeAuthorityAdapter, NativeBusinessEvidence and NativeBusinessArtworkEvidence independently resolve current signed scope/session, business/store/program, product/PV/plan/mapping/spec approvals and supplier/factory/artwork ancestry. Local mapping product key and variant must match the package's historical fixture anchor and selected supplier variant. This does NOT certify the saved provider product. Native question counts, required flags and limits must agree with the exact reviewed field schema; unsupported photo/overflow remains held.

personalization_hash is optional compatibility context. When supplied it must equal the SERVER selected proof. proof_hash/schema_hash/review_hash/intake_hash aliases may only match resolved evidence; raw proof/review/schema/intake/binding objects are rejected. The exporter NEVER calls selectReviewed.

The server inserts evidence.shop2_binding before preparing the package's immutable version. Imported old binding content cannot authorize a new association; it is reconstructed from current protected storage. A new package has no decision. The existing explicit package_decide review must approve its exact new hash. Changing selected proof or approved dependencies requires a new package/version/decision; old records and seals are not rewritten.

## Exact immutable output paths

- package_hash == immutable_package_record_sha256: original content-addressed package record, whose evidence includes shop2_binding.
- approval.evidence_hash: the same exact package hash; approval remains a held-artifact decision only.
- proof_business_binding: identical to persisted record.evidence.shop2_binding.
- proof_business_binding.schema: DF_SHOP2_PROOF_BUSINESS_BINDING_1.
- proof_business_binding.binding_hash: NativePortContract::hash(binding without binding_hash).
- proof_business_binding.business: selectors, business_id, store_id, product_program_id, product_id, product_version_id, production_plan_id, current approval event IDs/dependency hashes, approved spec/artwork dependency hash, supplier/research/factory/artwork identities, blueprint/provider/variants and reviewed connection binding/revision.
- proof_business_binding.personalization_intake: DF_SHOP2_VERIFIED_PROOF_REFERENCE_1 projection of the actual Lane A DF_ARTWORK_PERSONALIZATION_INTAKE_1 snapshot. It retains intake_hash, versions, selection, actor_scope, field definitions, proof SHA256 and review/factory/provenance references. It omits private sample text/SVG and raw source copies.
- personalization_intake.versions.{schema_hash,proof_hash,review_hash,selection_hash,research_hash,factory_hash,factory_review_hash,artwork_version_hash,artwork_approval_sha256,artwork_sha256,specification_sha256,supplier_workflow_hash,supplier_snapshot_hash} are direct verified source references.

The upstream intake_hash hashes the FULL original private body under CommercialResearchContract::json; it is not a hash of the reduced projection. Verify it by calling the actual server intake resolve(shop,server_supplier,server_factory,stored_intake_hash), never by inventing a projection canonicalizer. The binding hash uses NativePortContract::hash, whose canonicalization is distinct and explicit in source.

## Final boundary

package_export requires current approved package and exact factory/research context. It reconstructs the binding from stored selectors, verifies the current intake against stored_intake_hash, resolves current approved business dependencies, compares the immutable binding, uses the bound-record guard in the underlying repository export and revalidates all current evidence/decision/session after expensive assembly. Nonexistent/corrupt/unreviewed/stale/foreign/substituted proof, changed selection, stale product/spec/mapping/plan, scope/session revocation or inconsistent storage denies.

package_bundle uses the same immutable bound JSON and exact selected proof SVG (private local-review DO NOT IMPORT, never production art); each file has a checksum. The wrapper rechecks the complete current binding/decision after ZIP assembly. Existing low-level unbound worksheet/bundle APIs retain historical compiler coverage; the strengthened supplier-mounted complete export route denies unbound historical packages.

No migrations, approval repair, old-seal reuse, source-pin fallback or external transport is introduced. STOP ALL ON; external lock ON; automation OFF; manual buyer-specific production approval required; RELEASE HOLD.

Ordinary ProductPackagePanel exports for bound records delegate to the same orchestrator; ProductPackageRepository::export validates bound evidence after JSON assembly regardless of caller. Proof metadata on unbound ordinary exports denies rather than being ignored. These lower boundaries preserve the existing65536byte JSON budget.
