# Protected existing-product source inspection

Scope: consume the already implemented, versioned `NativeExistingProductMutationEvidence::resolve()` PRICE-only observation through the existing native PANEL authorization. No canonical mutation capability, product version, credential store, approval, route or sender is introduced.

1. Write failing tests for exact source shape, identity, immutable evidence hash, price-only semantics, timestamps, required false authority flags, unknown currency/version and malformed/executable input rejection.
2. Add a pure minimized read model. Hashes detect drift; they do not authenticate caller data. Require current core verification and manual review on every resulting view.
3. Add a protected read-only panel following HeldPreparationPanel: current PANEL proof, core version check, source read, expected-hash revalidation and final PANEL recheck. No controls or writable route.
4. Exercise the actual core source resolver and PANEL port with the existing business fixture, synthetic GET/adoption/review, STOP/lock restored ON before inspection. Test nonce, scope, source drift and authority denial. Label this a native source primitive, never mutation admission.
5. Preserve current full paired-run inputs. Run focused/full companion, static/security and extracted-package checks; promote a core pin only after exact-head core certification. Package only the final published source, test-only while release gates remain blocked.

The core's business product-version row is a reference, not a native mutation counter. The source's price-only payload does not prove a complete product-draft update. Provider currentness, provider version CAS, native product version, independent checkpoint and dispatch remain unverified/denied.
