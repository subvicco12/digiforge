# Managed photo native association — MISSING TYPED BRIDGE / EXPECTED DENIAL

Baseline: `8b38453e612833f240d792f97878e1025fe1b27e`. No runtime source changed. The accepted unified photo integration test remains byte-identical to that baseline.

The external boundary test reuses the actual photo/native business fixture, creates a genuine governed offline `ORDER_READ` receipt and an approved managed-photo fulfillment plan, then calls `NativeBusinessOrderEvidence::prepareEvidence`. The correct present result is denial: catalog-only inputs provide no typed bridge to saved provider product identity. The test is outside the default acceptance suite, with no skip or xfail configuration. Run only on the exclusive disposable test database:

```sh
PATH=/workspace/.onboarding/bin:$PATH DIGIFORGE_CUTOUT_PYTHON=/workspace/.onboarding/cutout-runtime/bin/python WP_TESTS_DIR=/workspace/digiforge-platform-sprint/wp-develop-released/tests/phpunit /workspace/.onboarding/bin/php -d auto_prepend_file=/workspace/digiforge-platform-sprint/preload-clean-lane-b.php /workspace/digiforge/vendor/bin/phpunit -c phpunit.wordpress.xml.dist docs/engineering/shop2-platform-1266/evidence/CustomerPhotoNativeAssociationIdentityBoundaryTest.php --filter testCatalogPhotoMappingCannotBeAssumedSavedProviderProductIdentity
```

An initial exploratory **wrong success assumption** produced 1 test / 3 assertions / 1 failure in 8.046 seconds (PHPUnit exit 1), retained in `known-native-photo-identity-failure.xml`. The actual error was `printify_native_order_product_mismatch`, not fixture admission failure. That exploratory assertion has been replaced by the boundary test requiring this exact error plus `external_execution_authorized=false`, `network_request_attempted=false` and `retry_permitted=false`. Dedicated-config boundary verification: **1 test / 7 assertions PASS**, 5.974 seconds, PHPUnit exit 0; retained in `native-photo-identity-boundary.xml`. Rejection is the correct security contract, not a runtime regression.

The photo renderer's approved mapping uses catalog blueprint `101` and provider/variant `2:202`; the governed order-read fixture contains provider product ID `101`, numeric variant `202`, quantity `1`. Equal-looking product strings do not establish that a catalog blueprint is a saved provider product. `NativeBusinessOrderEvidence` correctly rejects the domain mismatch before its personalization projection runs.

`RenderArtifactVerifier::certifiedTemplate` and `ReviewedOwnershipBinding` bind the catalog blueprint plus provider/variant. `NativeSupplierEvidence::resolve` instead requires an actual governed `PRODUCT_READ`, saved product ID, raw numeric mapping variant, and observed `blueprint_id`/`print_provider_id` joined to approved artwork. A future positive requires NEW real product-read and approved typed-bridge inputs. The unchanged catalog-only fixture must remain denied; prefix trimming or equating blueprint IDs with saved product IDs cannot supply that proof.

The suspected all-approved personalization projection issue is **unreached and unverified**. No runtime normalization or projection patch was made. This boundary does not invalidate the separate local photo/render/package/held preparation proof, whose held result still requires artifact rechecking and confers no native epoch, allocation receipt or external execution authority.
