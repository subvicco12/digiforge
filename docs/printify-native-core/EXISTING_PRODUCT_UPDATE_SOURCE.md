# Protected existing-product price-update source (1.0.0)

`NativeExistingProductMutationEvidence::resolve(scope, actor, mappingId, specificationId, updatePlanHash, productReadReceiptHash, expectedEvidenceHash = null)` is a read-only source resolver. It does not admit, reserve or send a mutation.

It requires the actual governed PRODUCT_READ producer, exact adopted read and adoption audit, current reviewed update plan, business mapping, sealed package/artwork and independently scoped provider identity. It regenerates the existing core PRICE_UPDATE wire request from observed variants and the reviewed integer price. It rejects stale source hashes, substituted product identities, missing/ambiguous producer audits and revoked policies. The original IMAGE_UPLOAD evidence API is unchanged.

The existing 300-second freshness requirement applies when the protected read is adopted. Historical observations may remain inspectable afterward; `provider_currentness_verified=false`, `provider_version_cas_verified=false` and `revalidation_before_mutation_required=true` explicitly require fresh validation before any future mutation. Canonical native product version remains null. All mutation, dispatch and external execution flags remain false; STOP ALL and external lock remain effective.

This is not the companion's full product-draft UPDATE contract, which additionally requires title, description, print areas, geometry and an authoritative native product version. No provider ETag, currency, price cost, supplier geometry or approval is inferred. Version1 requires the same sealed package for source adoption and update; ancestor-package source reuse is unsupported.

Focused synthetic acceptance: 1 test /72 assertions passed, using actual core producer/adoption/plan interfaces and a controlled GET-only sender. No external provider request, credential access or operational approval occurred. Production readiness and canonical mutation capabilities remain blocked.
