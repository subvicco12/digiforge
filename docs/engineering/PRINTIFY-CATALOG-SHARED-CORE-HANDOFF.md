# Narrow Printify catalog dependency handoff

This package is an engineering candidate for the PR #1242 owner to accept for offline consumption by PR #1253. It does not assert human approval, runtime authority, or release approval. The exact immutable commit is supplied in the owner handoff after verification.

Selection: one narrow commit based on main `7f30ad6aa37f58eca5302710c5f4583cabc45dfa`, with shared source `fac3634615c72ffdb0fdbce8ef1fbd3ae3c9e4c0`. Consumer #1253 remains at `6a32479e848e63149815d946044b77887642457b`. No cumulative integration is selected.

## File ownership

| Action | Owner | File |
|---|---|---|
| retain-unchanged | main-maintainer | `includes/Core/Capabilities.php` |
| retain-unchanged | main-maintainer | `includes/Core/Config.php` |
| retain-unchanged | main-maintainer | `includes/Core/Settings.php` |
| consume | shared-1242 | `includes/Database/ProviderConnectionSchema.php` |
| consume | shared-1242 | `includes/Database/QueryEvidence.php` |
| retain-unchanged | main-maintainer | `includes/Database/Tables.php` |
| retain-unchanged | main-maintainer | `includes/Integrations/CredentialVault.php` |
| retain-unchanged | main-maintainer | `includes/Integrations/ProviderCatalog.php` |
| retain-unchanged | main-maintainer | `includes/Integrations/Repository.php` |
| retain-unchanged | main-maintainer | `includes/Listings/Validator.php` |
| retain-unchanged | main-maintainer | `includes/POD/BusinessScope.php` |
| consume | shared-1242 | `includes/POD/PrintifyCatalogClient.php` |
| consume | shared-1242 | `includes/POD/PrintifyCatalogGetSafetyExceptionContract.php` |
| consume | shared-1242 | `includes/POD/PrintifyCatalogReadContract.php` |
| consume | shared-1242 | `includes/POD/PrintifyCatalogReadPolicy.php` |
| consume | shared-1242 | `includes/POD/PrintifyCatalogReadRepository.php` |
| consume | shared-1242 | `includes/POD/PrintifyConnectionBoundary.php` |
| consume | shared-1242 | `includes/POD/ScopedProviderConnection.php` |
| consume | shared-1242 | `includes/REST/PrintifyCatalogReadController.php` |
| consume | shared-1242 | `includes/Security/FirstPartyOwnerPolicy.php` |
| retain-unchanged | main-maintainer | `includes/Security/Logger.php` |
| consume | shared-1242 | `includes/Security/ReviewedLocalEvidence.php` |
| consume | shared-1242 | `includes/Security/ShopAccess.php` |
| consume | shared-1242 | `includes/Security/ShopAccessAuthority.php` |
| consume | shared-1242 | `includes/Security/ShopAccessPolicy.php` |
| consume | shared-1242 | `includes/Security/ShopAccessRepository.php` |

The JSON companion records SHA-256 for every file. Fifteen consumed files are byte-identical to the pinned shared source. Only PrintifyCatalogReadContract.php extends the shared code: exact blueprint size-guide GET scope and bounded source-envelope validation. All ten retained files are byte-identical to pinned main.

## Integration instructions

1. The #1242 owner must explicitly accept the candidate commit and the 16-file list; this is the smallest remaining cross-workstream approval. Coordinate with #1253 before changing its branch.
2. Consume the single accepted narrow commit, preserving the ten main files. Do not merge the cumulative #1242 branch. Test patch applicability and rerun the consumer dependency probe with the accepted commit and its updated contract hash.
3. No cumulative REST/admin guard mappings are required by this selection. This commit does not register controllers or run schema migrations. Review the existing consumer registration boundary; register the catalog controller at most once only in a separately authorized integration change.
4. Independently verify installed main-site configuration, repository schema, reviewed ownership and Shop 2 assignment before runtime use. Source classes and test fixtures confer no grants, exceptions or ownership. No schema migration or data backfill is authorized here.
5. The consumer Portal.php conflict with advanced main remains with the existing portal/factory integration owner; that owner must be designated before resolving it. This package does not touch Portal.php, routes bootstrap, CSS or other factory files.

## Scope and verification

The size-guide scope is GET `/v1/catalog/blueprints/{blueprint_id}/size_guide.json`, documented by https://developers.printify.com/. Response sizes/types and ordered from/to ranges remain source evidence, including null/empty missing bounds and units. No geometry certification, inferred dimensions, costs or conversions are introduced. Empty sizes/types are accepted as missing evidence.

Existing ten-request maximum, one-hour expiry, exact account/shop/ownership binding, durable reservations, idempotency, rate limits and audit checks remain unchanged. No HTTP calls, grants or exceptions are issued by the new tests. STOP ALL, external lock, automation OFF and RELEASE HOLD remain required.

Focused contract and WordPress denial tests accompany the package. Exact-head CI and offline consumer compatibility results belong in the immutable commit handoff. Acceptance of source consumption does not authorize live Printify execution, merges, deployments or production activation.
