# Additive core-owned upload wire preparation

A new preparation may consume a minimized current core descriptor; an existing approved MutationIntent cannot be renamed or rehashed. Legacy asset-hash.png preparation remains available. The new path uses the exact core plan filename and verifies the complete immutable POST body hash using already validated local artwork bytes. It does not receive image bytes or credentials from core.

Descriptor1.0.0 keys: schema=DF_NATIVE_IMAGE_UPLOAD_WIRE_1,version,scope_fingerprint,actor_id,source_evidence_hash,plan_hash,package_hash,asset_sha256,file_name,method=POST,endpoint=/v1/uploads/images.json,payload_fingerprint,descriptor_hash. Filename is shop2- plus first16 lowercase hexadecimal package hash plus .png. Payload hash is canonical SHA256 of {method,endpoint,body:{file_name,contents}}. Descriptor hash covers every preceding descriptor field. Only Shop2/personalized_pod is supported.

The descriptor is minimized evidence, not a cryptographically authenticated grant. Self-hash equality does not verify source or association approval. New preparations remain network_request_permitted=false, source_authenticated=false and current_core_verification_required=true. The core must revalidate the current association, source, actor, approval revisions, counters, STOP/lock and final dispatch fence before any future operation. No dispatcher or credential path is added.

Core producer: proposed additive NativeBusinessMutationAssociation::uploadDescriptor, available only after actual reviewed association/current-source validation. Synthetic descriptor tests exercise pure construction, not native approval or provider acceptance. Actual producer conformance follows only after its exact core SHA qualifies.

Canonical hashing matches NativePortContract1.0: recursively sort associative keys, preserve list order, encode using PHP JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION with default slash/unicode escaping, then SHA256 those exact bytes. The literal independent fixture validates this profile. Reordered descriptor maps produce identical intent identity.

Adversarial hardening in this component rejects decoded payloads above2MiB (including the base64-padding off-by-one), refuses PHP callbacks/non-JSON data before fingerprinting, and detaches referenced prepared/context arrays. Hash canonicalization does not mutate caller-owned referenced maps. Existing valid canonical hash bytes remain unchanged. These are input-integrity protections, never source authentication, custody or dispatch authority.
