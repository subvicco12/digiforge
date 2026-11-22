# Product Factory exact replay lineage

Blueprint §§9,17,31 require immutable version content and correct parent lineage. A previously accepted creation replay returned an existing entity based only on the key, even if the caller supplied another parent or changed version notes. Three failing WordPress regressions demonstrated that behavior.

Both early lookup and post-insert race recovery now require exact canonical creation content: sanitized label/text and the required parent identity. Missing or changed lineage/content returns HTTP409 `digiforge_idempotency_conflict`; persisted history remains unchanged. Sanitized equivalent input can replay the original identity. No schema change, history backfill, state-transition restriction or new external execution occurs.

Four behavioral tests cover parent substitution, changed immutable version content, omitted parent, equivalent sanitized inputs, and matching/conflicting injected insert winners through a second database connection. The injected winner test exercises recovery; it is not simultaneous-worker stress testing. This proves only the replay boundary, not every ProductFactory immutability or downstream ownership requirement.

Local validation passed: 1,152 unit tests / 7,340 assertions, legacy suites, configured PHPStan/PHPCS, changed-file syntax and diff checks; 203 WordPress tests / 1,862 assertions on a new disposable database. Independent review found no important issues. Publication requires independent exact-SHA verification and exact-head Engineering & Safety Audit. STOP ALL ON, external lock ON, automation OFF. Finance1147, recovery workspace, production and provider operations untouched.
