# Persisted date-policy portal integration

Ledger first recorded against422cd010 in PLATFORM_FEATURE_LEDGER_20261115.json.
Confirmed gap: the approved date formatter existed only as CLI, so saved portal
schemas/proofs could not explicitly select it. This slice closes that reusable
mug/poster text-engine gap; it does not complete the entire AI factory.

`ArtworkPersonalizationRepository::define(shop,factory,fields,parent=null,datePolicy=null)`
adds an optional versioned schema selection. Existing calls produce identical legacy
schema data. New schemas can pin `digiforge-en-month-day-year-v1`; policy requires
required first_name/second_name/date, name caps <=13 mug/16 poster and ISO date10.
Unknown policy, incompatible field shape/profile/caps or tampering fail closed.
UI defaults to legacy behavior; opt in explicitly when saving a new schema. Existing
schema edits produce a new immutable version. From a proof, the schema editor uses
that proof's actual schema fields/policy instead of silently reverting policy.

Existing result.samples/fields/sample_values_sha256 retain CANONICAL input meanings.
New result.date_display contains policy/hash, display samples/hash, canonical hash
and bound offline receipt hash. Existing result.svg/proof_sha256 use rendered display.
Readback recomputes the full result from current approved art, original schema,
canonical inputs and exact policy. Changing policy/input/art needs fresh proof,
review and selection. Old reviews do not approve new versions; unsupported glyph
and layout HOLDs still cannot be approved. No source data or original artwork is
rewritten, no blanket glyph/punctuation expansion is introduced.

Lane B intake envelope, selection storage, supplier ancestry, business/product IDs
and held exporter are unchanged. Consumer should use canonical samples for input,
explicit date_display for reviewed display, and existing exact proof hash for media
identity. Do not treat old canonical-only media as new policy proof. No new export
or POST handler was added; existing nonce/shop/actor/supplier routing is reused.

Operator: Research -> approved editable art -> Customer fields -> choose English
spelled month v1; set native name caps within supported limits -> Save field schema
-> enter ISO date -> Save immutable local proof -> inspect printed date and ISO
side-by-side -> local review -> explicit existing intake selection. DO NOT IMPORT;
local generic-font proof is not native Printify or physical print acceptance.

Tests use synthetic fixtures, mock/source imports, local reviewer roles and disposable
WordPress only. STOP ALL ON; external lock ON; automation OFF; manual approval;
RELEASE HOLD. No provider, commercial artwork or catalog work.

Remaining software estimate: selected slice complete. Provisional4–7working days for
separately bounded AI mock/budget end-to-end qualification, reusable photo portal
proof/crop intake and reviewed async research/design adapters plus independent QA.
These are partial areas to verify, not all proven P0 defects. No live-provider or
production readiness estimate is implied; external qualification is separate.
