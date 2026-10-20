# FC01 native evidence packet — offline declaration contract

Base: draft #1207 `664ea1b4e5329339514a730bf9398c63f539ab7f`, audit #4739 PASS, head verified unchanged. Repository AGENTS.md is absent; available operating guidance snapshots remain proposed/unmerged. Current owner task authorizes this isolated offline engineering change. Blueprint section 14 and the original-source acceptance ledger remain authoritative. BP-template-qa-spelling, BP-printify-shop-scope and BP-portal-4-provider-connections remain UNVERIFIED. No ledger counts change.

## Demonstrated gap and scope

The existing FC01 concept pins its synthetic font/artwork and always disclaims certification. Existing reviewed contracts resolve scoped policies only; no typed FC01 native evidence packet could bind owner-supplied captures and render bytes to N01–N08. This adds one unregistered read-only class, `includes/POD/FC01NativeEvidencePacket.php`, without a REST route, table, migration, activation path, transport or new review system. No existing implementation or asset changes.

`reviewIdentity(packet)` returns the hash-pinned key and minimal typed payload for the existing `ReviewedLocalEvidence` policy store. It creates nothing. The review scope is **FC01_NATIVE_EVIDENCE_COMPLETENESS**, not linguistic review, licensing or compatibility approval. Existing author/reviewer separation, shop assignments, effective/expiry dates, revocation and audit-history validation remain required. `inspect(packet, sourceBytes)` performs reads only. It requires the exact current `PrintifyConnectionBoundary` context for personalized_pod and printify.catalog.read, including environment/installation/account/shop/integration. This local prerequisite grants no provider access and never retrieves secrets. It rechecks the binding and signed review before returning.

## Packet v1

Top-level exact keys: schema (`fc01-native-evidence-packet-v1`), shop_key (`personalized_pod`), evidence_kind (`SYNTHETIC_TEST_ONLY` or `OWNER_SUPPLIED`), concept_spec_sha256, connection, identity, sources, sections. Current retained spec SHA: `8d067fecfd8dee0ecc30ffb7ba7f0d27743092bf4ebc3eb517b13d484c0c39c2`. Existing product identity requires product_id, blueprint_id, provider_id, variant_id and placement. These are reviewed declarations, not inferred ownership or certified catalog records.

Sources contain exact id, kind, origin, observed_at, expires_at and sha256. Kinds: PUBLIC_DOCUMENTATION, DASHBOARD_CAPTURE, RENDER_CAPTURE, SYNTHETIC_TEST_ONLY. Origins must be HTTPS Printify/help/developer URLs without credentials, query, fragment or custom port. URLs are provenance only; no fetch occurs. Both timestamps require explicit timezone; future observations, invalid dates, expired sources, duplicate IDs, missing/extra byte entries and hash mismatches fail closed. Freshness is explicitly supplied/reviewed; no invented provider TTL. Bounds: 16 sources, 256 KiB each, 1 MiB aggregate, 32 KiB packet metadata, eight references per section. Large authentic exports require separately designed bounded handling, not silent truncation.

Each N01–N08 section is null (missing) or an exact object with reported_level (DOCUMENTED/OBSERVED/TESTED), source_ids and findings. Public documentation cannot masquerade as observed/tested evidence; synthetic sources cannot be mixed into an OWNER_SUPPLIED packet. Reported levels remain declarations, never authenticated provider observations.

| Section | Typed findings / required match |
| --- | --- |
| N01 | font_selection; layer_settings_sha256 |
| N02 | family_name_field_id; required=true |
| N03 | established_field_id; optional=true; conditional_omission=true; exact prefix `ESTABLISHED ` |
| N04 | max_code_points=48; exact_utf8=true; normalization=NONE |
| N05 | overflow=REJECT; auto_shrink=false |
| N06 | proposed_locale=en-US; glyph_coverage_sha256; no locale approval inferred |
| N07 | geometry_sha256; placement matches declared product placement |
| N08 | input_sha256; preview_sha256; final_sha256; parity_rule=SAME_FORMAT_BYTES; preview_source_id; final_source_id |

N08 additionally requires the referenced RENDER_CAPTURE bytes to match both declared hashes, decode safely as bounded PNGs with the existing RasterArtworkCodec, and have equal hashes. This supports same-format local artifact parity only. It does not establish native origin, provider print-file parity, input-to-render causality or cross-format equivalence. SVG/PDF/native designer exports remain unsupported in this packet version.

## Results and limitations

A matching section returns MATCHED_REVIEWED_DECLARATION with purpose_verified=false. Missing or incompatible sections remain UNKNOWN independently. Missing/stale/revoked binding or review, conflicting or unavailable source evidence makes the entire result UNKNOWN. Exact packet hashing preserves text bytes and JSON key order; reordering/reformatting metadata requires a new hash-bound review rather than borrowing approval.

Even eight matching sections ALWAYS return native_compatibility=UNKNOWN, acceptance_state=UNVERIFIED, rights_certified=false, linguistic_approved=false, production_authorized=false, external_execution_authorized=false and publishing_allowed=false. A font name, SHA, screenshot or signed completeness receipt cannot certify Printify capabilities or rights. No native font files are downloaded or reproduced.

The synthetic JSON fixture is deliberately not deployable: connection context, current timestamps and concept hash are supplied by isolated test setup. Its product IDs/font/layers are fictional. Tests use the existing FC01 PNG as synthetic parity bytes, not a Printify render.

## Verification and owner inputs

The new class-existence test failed before implementation. Focused selection passed: 71 tests / 775 assertions, followed by an added malformed-PNG parity regression. Final packet-focused results and exact-head hosted audit are recorded on the draft PR. WordPress-aware level-5 static analysis, baseline PHPStan, PHPCS and diff checks apply unchanged. Tests cover independent signed review, exact scope, missing/tampered/extra bytes, source expiry/timezones/future observations/duplicates, partial/incompatible N03/N05, source-level confusion, malformed artifacts, wrong shop/spec/authority claims, unauthenticated access, review and connection revocation, deterministic retry, no HTTP and no evidence/audit mutation. Existing reviewed-contract/resolver tests cover additional review expiry, stale/conflicting references and authority loss.

Minimum owner input remains an existing-product evidence packet: redacted store/product/provider/blueprint/variant/placement captures; native font/layer settings and Etsy field mappings; existing evidence for blank optional-line omission, Unicode counting, long-input overflow and glyph coverage; exact reviewed native preview/final artifacts if available; dated source provenance and independent reviewer scope/validity. Missing items stay missing. Do not create/edit/save/publish products or generate provider previews to collect this evidence under current restrictions. Native text auto-scaling and mandatory fields documented by Printify are not silently accepted as FC01 parity. Actual rights, linguistic approval, native compatibility, supplier geometry and installed-site/production authority remain external or separately reviewed evidence.

STOP ALL ON; external lock ON; automation OFF; RELEASE HOLD. Finance excluded. FC01 v1, retained v2 assets/renderer, existing production bindings and all prior PRs are unchanged. No production impact.
