# Main DigiForge recovery and POD development — 12 November 2026

Engineering only. STOP ALL / external lock ON, automation OFF, RELEASE HOLD. No install, migration, merge, deployment, provider request, listing publication or order is authorized or performed.

## Source identity and baseline decision

Fresh GitHub reads: main `3758d1385f7d4a62947248822c1fe1c89ec9818e`; POD1256 `d32fc91ae7b559857b6a1dc81798eb6c3e45ed1f`; frozen1260 `f0ab0f74e2263e2bd7f7ce9c8003907524ce8f24`; blueprint1262 `761fb79abb2813455b7a90a56aa1fac8e6994f22`. Companion1263 observed at `4c7aac5c0eab21d10eb9e38997c6723667856eb4`; it may advance independently. No connector branch/file changes.

Production authenticated metadata and source read: **1.0.103.17 active**. SELECT confirms `digiforge_db_schema_version=25`, legacy `digiforge_db_version=23`; these are separate markers. STOP ALL true, automation_armed false, activation_authorized false. External lock remains governing ON; no lock write or separate host-lock attestation performed.

Canonical .17 receipt nominates source `e957ba096751dc34254cdbcbefed13b625f5e507`, artifact `digiforge-1.0.103.17-consolidated-update.zip`, SHA256 `5303ef7eb5ede7dab770599f5cfd279022c77d3d8665a3d5ab1d2fce5ef83dac`, 3,201,579 bytes, 741 members. Its acceptance report explicitly says installed-source equivalence NOT TESTED. This session independently downloaded the package and verified its archive hash and **all 741 member hashes** against the canonical preservation manifest.

Canonical .25 artifact: source component `b7d60b0349e54f400fd4bc0ec781edbb7da4b518`, SHA256 `98cf08aeb8bdf08617808751257e098a1fef37b60a14ce7400fe08122d6ba45e`, 3,267,861 bytes, all **767 member hashes** verified. Both manifests explicitly state `composition_is_exact_checkout=false`. Neither nominated commit nor frozen PR checkout is the complete package. File-level comparisons against four exact commits are in [source-lineage.json](source-lineage.json); absence from a commit is not evidence a deployed feature is absent.

Decision: start from verified POD1256, retain every existing path, overlay the exact preserved .25 package (which cumulatively retains .17 functionality), and recover matching .25 source tests from b7d60b03. This preserves full schema25/Digital/POD modules absent from the provider-focused branch while recovering later portal-owner, scheduler, supplier selection, real mockup import and review-export functionality. No deletion or provider-module disablement. Package-overlap bytes are authoritative for those later modules; d32fc91a is authoritative for retained additive modules. The resulting combined tree is a **new engineering composition requiring fresh combined tests**, not a previously certified release or a production installer.

**Raw production equivalence remains UNVERIFIED.** Supported WPVibe reads are masked/line-numbered; its current listing returned no inventory and cannot establish absence. No installed build manifest was available at the probed path. No on-host PHP snippet or credential access was introduced to obtain hashes. A supported plugin-root raw SHA256 inventory and metadata-only schema/asset inventory are still needed before deployment. No existing inspected GitHub branch is proven to contain every installed byte; no inferred version equivalence is called source identity.

## Reused evidence and authority

- Canonical Drive folder: https://drive.google.com/drive/folders/1bbbVgrB8G4ekbk5itrH2asTu_yLnbbPg
- .17 focused acceptance: https://drive.google.com/file/d/13AG0lZCBzk9GtkXYREAMie5n9pLISHO9/view (scheduler QUERY_PASSED; nine historical FAILED actions; no retry; owner four-shop regression; displayed safety verified).
- .17 qualification: https://drive.google.com/file/d/1K55rLBLTlSD07D5UUIf7_CvWgZFhLY8h/view (historical exact-source audit PASS; 146 package tests/1504 assertions; not installed identity).
- .17 preservation: https://drive.google.com/file/d/1bVt5oovTbarLBnyagTTRITlY0SmQ_B1-/view
- .25 preservation: https://drive.google.com/file/d/1ZUQtT032hRTHgTvGWIQSLcKK_gDzOBgl/view
- Canonical Ultimate Master Blueprint v6.0 DOCX: https://drive.google.com/file/d/1isN_2m7eu5DTjykY7X67woiPx7wluhPc/view. Downloaded/read as DOCX; current user instructions and runtime/repository facts precede its historical implementation snapshot. No older blueprint substituted.
- Launch authority: `docs/launch/FIRST_LAUNCH_MASTER_PLAN.md` v1.1 and `PERSONALIZED_POD_FOUR_DAY_MVP_BLUEPRINT_20261103.md`.
- Existing Day1–Day4 and schema25 acceptance ledgers remain unchanged. Their historical passes do not certify this changed tree.

## Feature and module reconciliation

“Verified” below names its evidence boundary; historical source tests do not imply current production acceptance.

| Module | Classification | Authoritative implementation and remaining boundary |
|---|---|---|
| Active .17 version/schema | IMPLEMENTED AND VERIFIED | Fresh authenticated metadata/SELECT; installed bytes unverified |
| Owner/access four-shop model | IMPLEMENTED AND VERIFIED historically; combined regression pending | .17/.25 native GlobalOwnerAuthority, PortalAccess and signed ACL; never restore older owner bootstrap |
| STOP ALL/automation/activation safety | IMPLEMENTED AND VERIFIED displayed/SELECT | Existing core/scoped safety retained; no operational authority added |
| Scheduler diagnostics | IMPLEMENTED AND VERIFIED historically | .17 ActionSchedulerReadiness/ScheduledTaskReadModel; task execution is BLOCKED |
| Digital/product factory/Drive/documents | IMPLEMENTED BUT UNVERIFIED in combined tree | d32fc91a full modules retained; do not replace with reduced provider checkout |
| Governed catalog/research/three concepts | IMPLEMENTED BUT UNVERIFIED in production | Existing research repositories plus later supplier snapshot binding; research evidence is not measured demand |
| FL-03/FL-04 editable artwork import | IMPLEMENTED AND VERIFIED in local contract tests | DesignFactoryContract/Repository, exact mug2475x1155/poster2418x3018 profiles; human artwork selection still required |
| PNG validation | IMPLEMENTED AND VERIFIED in local contract tests | Existing complete CRC/decompression/density validation; actual supplier authenticity is separate |
| Native input constraints | PARTIAL | Existing package/native-field contracts; real provider mapping and buyer preview parity unaccepted |
| Local text proof | New implementation; exact test evidence in acceptance report | Additive LocalPersonalizationProof; generic font; never a print-ready provider proof |
| Photo proof/crop/order | MISSING from this new text path | Existing photo/render modules retained; FL-01/05 still need accepted placement/cropping |
| Manual mockup import/integrity | IMPLEMENTED BUT UNVERIFIED in production | .17 Shop2MockupRepository; immutable bytes, design binding, rights note; 5+ real reviewed mockups needed |
| Supplier mockup authenticity/parity | BLOCKED | Human/provider evidence required; never inferred from imported bytes |
| Price, fees and margin scenarios | PARTIAL | Existing ApprovedDesignListingPackage economics; missing actual costs remain gates; manual owner price decision |
| Etsy listing preparation/13 tags/held review ZIP | IMPLEMENTED BUT UNVERIFIED in production | Existing package compiler, Shop2 workflow/review bundle; no publication or draft submission |
| Provider API transport/synchronization | OWNER APPROVAL REQUIRED / isolated companion work | Preserve existing Printify source/tests; future versioned DigiForge-controlled boundary per1262; companion1263 owns new connector |
| Printify-to-companion migration | BLOCKED | No tested replacement/rollout path yet; no existing module removed or disabled |
| Shipping/processing/manual order hold | OWNER APPROVAL REQUIRED | Existing held worksheet; exact storefront/provider evidence still required |
| Production source/backup/restore readiness | BLOCKED | Raw installed hashes and qualified restoration remain external release gates |

## Migration risks and next batch

This is deliberately not an installable release recommendation. The composite package preserves files that do not occur in the nominal source branch. Native owner semantics supersede historical per-shop OWNER fixtures; test authorities must model the current policy, not weaken it. Old package hashes must remain readable; new proof files are generated at export time and do not rewrite immutable package records. Generic-font proof layout cannot establish production typography, safe zones or provider parity.

Next batch: finish combined owner/delegate and module-isolation acceptance; obtain supported installed hashes; qualify FL-03/FL-04 actual supplier geometry/fields/rights-reviewed mockups and commercial artwork. Adopt companion interface only after exact-SHA compatibility/migration tests, leaving provider transport isolated. Any hosted installation or live operation requires separate owner approval.
