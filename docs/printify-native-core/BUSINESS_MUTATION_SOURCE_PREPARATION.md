# Core-owned business mutation source preparation

Baseline:234c0f6938fd616dba0421d97fba8968c891f826. Canonical Batch6 registry is unchanged; only GET_READ/PANEL remain advertised. This component supplies inert source observations and HELD candidate persistence, not native mutation admission.

## Fixed interface1.0.0

`NativeBusinessMutationEvidence::resolve(ShopBinding $scope,int $actor,int $mappingId,int $specId,string $planHash,?string $expectedEvidenceHash=null)` joins the actual current business/product/version/plan/mapping approvals to approved artwork/source-package evidence and the protected product operation plan plus current independently reviewed policy revision. The original sealed plan is the sole source of provider operation/wire data. No raw provider body, artwork bytes, credential or private plan body is returned.

Result schema:DF_NATIVE_BUSINESS_MUTATION_EVIDENCE_1; version1.0.0; exact scope/actor/shop/integration/credential/provider-shop/environment; actual stored business product_id/product_version_id; mapping/spec/plan/package/artwork identities and evidence hashes; operation; core-plan payload_fingerprint; current plan_policy_hash/plan_policy_revision and authorization_revision; evidence_hash. The only qualified source operation is IMAGE_UPLOAD. PRODUCT_CREATE, PRODUCT_UPDATE and publication are denied until actual saved-provider-product/mapping, canonical version and lifecycle/channel joins have their own conformance; sharing artwork/workflow/blueprint alone is insufficient.

`native_product_version` is null. `native_version_counters_verified`, `companion_payload_equivalence_verified`, `business_package_binding_approved`, `mutation_authorized`, `independent_checkpoint_verified` and `external_execution_authorized` are false; release_hold is true. A stored product_version_id is a business record identity, not a canonical mutation counter. A reviewed product plan is not approval of its new business/package association.

`register(scope,actor,mappingId,specId,planHash,expectedEvidenceHash)` requires the exact current source fingerprint and derives its own held binding and stable business-version/operation candidate key. No caller canonical binding or execution capability is accepted. It uses existing NativeHeldJournal::register SQL uniqueness, transactional audit and commit/readback; the existing table schema is never installed automatically. The HELD binding's zero counter values are explicitly unverified placeholders, not verified native version zero. Registration revalidates source before and after persistence. These checks are not a claim of atomic canonical business approval CAS: source may change while a held write is in flight, in which case return is denied/UNKNOWN and the record stays consumed.

Result schema:DF_NATIVE_BUSINESS_MUTATION_REGISTRATION_1; version1.0.0; scope_fingerprint/actor_id; source evidence; unchanged actual journal record/event_hash; evidence_hash. `native_product_version` is null; approval_verified, dispatch_permitted, native_version_counters_verified, companion_payload_equivalence_verified, native_contract_satisfied, independent_checkpoint_verified and external_execution_authorized are false; release_hold is true. Exact replay returns the actual journal state, including CANCELLED/UNKNOWN; it never resets it to HELD/PREPARED or grants a new fence. Changed source, wrong scope, policy or session denies; changed physical intent cannot reuse the consumed candidate key.

## Provider-wire reconciliation gate

Core PrintifyProductPipeline IMAGE_UPLOAD names its wire file `shop2-{package-prefix}.png`; the companion UploadContract names it `{asset-sha256}.png`. Therefore identical artwork can have different immutable wire fingerprints. This resolver reports the original core wire fingerprint only, with companion_payload_equivalence_verified=false. Never rewrite an approved/signed payload or compare these fingerprints as equal after stripping fields. Future canonical integration must choose one reviewed core-owned wire request before approval, then regenerate the corresponding companion intent and exact approval.

## Preserved failure boundaries

All ordinary STOP/external-lock/automation/release controls remain unchanged. Source preparation and local HELD journal are nonexecuting local evidence operations only. Native AUTHORIZE/REGISTER/CLAIM/RECEIPT_READ/RECONCILE/OUTCOME/CANCEL/RECOVER, publication, aggregate inspection/review/allocation and independent recovery remain denied. No dummy epochs, independent revocation generations or native dispatch receipts are manufactured.

The existing durability predicate remains strict: transactional tables must be real InnoDB (not WordPress temporary shadows), autocommit and transaction state must be compatible, innodb_flush_log_at_trx_commit must equal1, and enabled binary logging requires sync_binlog1. Reported Hostinger production value2 is still a blocker; this mission does not change hosting configuration. Coordinated all-root rollback and exclusive credential custody remain unqualified.

## Reproduction

`tests/wordpress/fixtures/NativeBusinessMutationFixture.php` exposes trait NativeBusinessMutationFixture::businessMutationFixture(PrintifyApiApprovalFixture), building actual isolated current approved source records under STOP ON with synthetic artwork/credentials only. It returns scope,mapping_id,spec_id,business_version_id,plan_hash,package_hash. `NativeBusinessMutationEvidenceTest` exercises source matching, non-disclosure, exact HELD registration/replay, terminal-state preservation, stale source/hash and revoked session denial, with HTTP prohibited. It removes only the existing WordPress temporary-table fixture filters so the real InnoDB predicates can be tested; no runtime predicate is weakened.

No deployment, production/staging installation, credential access, provider operation, merge, policy activation or historical UNKNOWN replay is authorized. STOP ALL ON; external safety lock ON; automation OFF; RELEASE HOLD.
