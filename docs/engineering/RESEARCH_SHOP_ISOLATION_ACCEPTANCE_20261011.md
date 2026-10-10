# Immutable research shop ownership

Blueprint §§4–6,10,31 require shop-scoped opportunities. Previously same-title candidates deduplicated globally, allowing one shop's review to become another's research decision.

New sources and candidates require one explicit canonical shop. Ownership is preserved in existing immutable config/score_inputs JSON; fingerprints and idempotency include shop scope. The five numeric scoring signals and weights remain unchanged. Observations/evidence inherit source ownership; cross-shop evidence links fail closed. Scoped reads cover sources, observations, evidence, candidates and reviews through their parent lineage. REST errors propagate with their original status rather than masquerading as HTTP200. Portal candidate filtering respects the selected shop.

Generation/development checks candidate scope before provider work and asynchronous persistence. ApprovalAutomation resolves the candidate's immutable owner instead of whichever source evidence happens to be first. Existing `goods` workflow input maps to `personalized_pod`; future shop profiles receive no activation or new execution support.

Migration boundary: no schema change or backfill, no legacy fingerprint/record rewriting. Unassigned legacy research remains visible to all-shop reconciliation reads but cannot be linked, reviewed, promoted or developed as an owned candidate. Malformed ownership JSON is preserved and excluded from a scoped view; it does not become a default shop. Product promotion keys are scoped, preserving distinct opportunity IDs. These changes do not certify comprehensive downstream product/listing/order ownership or external provider behavior.

Behavioral tests: same-title two-shop candidates and independent reviews; cross-shop evidence rejection; explicit scope requirement; scoped candidate lists and development ownership; immutable legacy history refusing promotion; HTTP idempotency independent across shops; invalid-scope HTTP errors; malformed JSON preserved. Unit 1,152 tests / 7,340 assertions; WordPress 207 tests / 1,870 assertions; legacy, configured PHPStan and PHPCS pass. No live provider call was made. Exact-head audit and integrated dependency testing are required before merge.

Finance #1147 and recovery workspace untouched. STOP ALL ON, external lock ON, automation OFF remain required. No deployment, catalog promotion or Etsy/POD execution. AI actual-cost settlement remains a separate demonstrated gap; monetary-budget generation remains blocked, not represented as complete.
