# Protected existing-product price-source inspection

The companion exposes `Plugin::existingProductSourcePanel()` as a server-side read-only component. It does not mount a route, register an approval action, create a mutation intent, dispatch HTTP, change mappings, access credentials or schedule work.

The optional core dependency is `includes/Connector/Printify/NativeExistingProductMutationEvidence.php`, version `1.0.0`, from an independently qualified and pinned core build. The component verifies that the loaded class belongs to that core path. Missing or incompatible core support fails closed; it is not an owner-enrollment prompt.

The host must supply its current validated ShopBinding, actor and native PANEL context for the `unknown` view, plus the exact mapping ID, asset-spec ID, reviewed update-plan hash and product-read receipt hash. The core validates the existing WordPress session, shop/module authority and CSRF proof. The component checks PANEL access, resolves the current source, revalidates its evidence hash through the core, and checks PANEL access again. Nonces and session fingerprints must stay in the authenticated server/request boundary, never URLs or exports.

The strict 49-field source contract describes a reviewed **price-only** update derived from a governed product read and adoption receipt. It does not establish a full product-draft payload, current provider state or a native product aggregate version. The minimized view preserves observation/adoption timestamps, selected provider identifiers and the proposed price in minor units. Currency and native version remain UNKNOWN; a business-version row is displayed only as a reference. Self-hashes detect drift and do not authenticate caller-supplied data. Mutation, dispatch, checkpoint, provider-version CAS, supplier certification and full-draft equivalence remain false/unverified.

There are no approval, retry or submission controls. The host still needs to place this component in its protected operator UI; this change does not create a public endpoint or activate a GET-only exception.

## Offline evidence

- TDD: missing-view RED; strict projection/identity/authority/expiry/shape/timestamp/reference/callback tests GREEN, 35 tests / 86 assertions.
- Full companion unit suite: 1,736 tests / 9,220 assertions.
- Actual core `2ac7e9183cf74a216c255138aaff1a2f615ec553` (runtime-identical to `d8733ba6167a968575010a8ee53e936752e344b8`) plus native PANEL/source producer: 1 test / 91 assertions, including zero inspection DML, ciphertext SQL reads and HTTP. Setup used one synthetic GET and actual local adoption/review contracts. STOP ALL and external lock were ON during inspection.
- The actual source profile is `tests/contracts/native-existing-product-source-wordpress.xml`; it requires the isolated actual core and synthetic fixture environment. It is not a mocked native mutation pass.

The repository's trusted core pin must be promoted separately after exact-head certification. Until then the default factory rejects an unpromoted core. Installation, live provider acceptance and release remain unauthorized; canonical mutation ports, independent recovery and credential custody remain separate blockers.
