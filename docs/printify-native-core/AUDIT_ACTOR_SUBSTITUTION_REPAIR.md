# Producer audit actor-substitution fixture repair

Starting core: d698836ec18973121a4296dd88442731f3428099, draft PR1264. Companion005a89521403239219bbb59598aefcb432cd54c3 and its qualified509abc0e pin are not changed.

## Root cause

WordPress defines the generic object_id field as an integer. Its wpdb update/delete helpers use that format unless callers explicitly supply WHERE formats. DigiForge's audit object_id is a string hash. The product producer test attempted to change an actor using an untyped hash predicate, and did not check whether the SQL changed the intended row. Numeric coercion can leave the valid producer record unchanged, so receiptSource correctly returns its valid array rather than a WP_Error.

Real isolated WordPress/MySQL regressions demonstrate both failure modes: two alphabetic hash identities become numeric zero and an update affects two rows; a hash beginning with scientific notation1e99 is coerced differently by PHP's integer formatter and MySQL and affects zero rows. Both cases fail the new exact-one-row assertion before the fix. The original end-to-end test passed locally1test/56assertions with a different random plan hash; its failure is intermittent, not a reproducible absence of the producer guard.

The existing runtime verifies two ordered producer anchors with equal actors, the original sealed authorization actor, and the consumed nonce actor. Its audit query already uses string predicates. No production guard or runtime change is required. This is a fixture mutation defect, not evidence of a successful authorization bypass.

Concurrent product-fixture repaird8262e70 was preserved by fast-forward, including its explicit object type, controlled integer metadata and checked mutations. This continuation adds the alphabetic-hash case, immediate actor readback/valid restoration and the identical order-source fixture correction.

## Correction and regression

Product and order audit fixture mutations/deletions now use explicit string WHERE formats. Actor updates use integer data formats. Tests require exactly one affected row and read back the substituted actor before the existing WP_Error assertion. Product restoration must affect one row and restore a successful read. The deterministic two-hash regression proves an unrelated producer row is untouched. The same unsafe pattern in the order source fixture is corrected to avoid the identical intermittent failure. No original assertion or test group is removed.

Fast product-hash plus order evidence regression passes12tests/52assertions. Full product/order focused, complete unit, WordPress/catalog, offline, extracted-native and exact-head Engineering & Safety results are recorded in the PR qualification report after execution. Source files under includes, portal authentication, native GET joins, PANEL/GET_READ capability advertisement, provider safety controls and all dispatch code remain byte-identical to d698836e.

The correction does not prove production durability or authorize a provider request. No database migration, installation, credentials, provider execution, merge, activation or historical operation replay. STOP ALL ON, external lock ON, automation OFF, RELEASE HOLD.
