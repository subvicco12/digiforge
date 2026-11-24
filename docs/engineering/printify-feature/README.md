# Printify Product Intelligence — feature-only overlay

This is a reviewable, create-only feature package for the existing DigiForge plugin. It is **not a replacement plugin ZIP**, not a WordPress add-on plugin, and not an installer. Do not upload it through WordPress's plugin installer. No production action is authorized. STOP ALL ON; external safety lock ON; automation OFF; RELEASE HOLD.

## Exact scope and preservation

The payload allowlist contains 13 files: the eleven missing API/research/portal files from certified implementation `78ea96d94e1faa50eced02eb42c9f590e36a5439`, plus the feature registration adapter and a new scoped stylesheet. ProductIntelligencePanel adds a feature-only registration method; its original registration method still supports the certified standalone integration. Other research implementation remains unchanged.

| Directory | Create-only files |
|---|---|
| includes/POD | ProductIntelligence.php; ProductIntelligenceRepository.php; PrintifyResearchMapper.php; PrintifyResearchReader.php; PrintifyResearchGateway.php; PrintifyResearchBatch.php; PrintifyResearchDependencies.php |
| includes/REST | PrintifyResearchController.php; PrintifyCatalogShopAdmission.php |
| includes/Portal | ProductIntelligencePanel.php; PrintifyResearchControls.php; PrintifyProductIntelligenceFeature.php |
| assets | printify-product-intelligence.css |

`feature-manifest.json` records the exact source SHA and SHA-256/size of every payload file. Every operation is CREATE_IF_ABSENT, never overwrite. Any existing destination, including an identical file, stops installation for reconciliation. The two newly named adapter/asset paths have not been verified absent on production. All thirteen require current non-executing absence preflight, including verification that the plugin root and parent paths cannot redirect through symlinks or escape the approved plugin directory. The package builder never installs or contacts a site.

`preservation-manifest.json` protects **all existing paths**, not only an enumerated subset: no deletion, replacement, downgrade or copying of shared implementation. The installed Portal.php, Plugin.php, root plugin/bootstrap, catalog repository/policy/contract/client/controller, all security and schema code, existing stylesheet, unrelated modules, configurations and data are excluded from payload. No schema migrator, grant issuer, credentials, migration version update, global guard replacement or automatic activation entry point is shipped.

The reused Hostinger inventory returned 492/492 include files. Only a sanitized summary is retained here; it is not a complete file-hash inventory or evidence of root/assets/module equivalence. The original eleven paths were absent; fifteen shared support files were present. FirstPartyOwnerPolicy.php was also absent. Installed repository/contract/portal sizes differ from the certified candidate. Those facts do not establish exact source semantics or authorize replacing the installed lineage. The original 37-file acceptance manifest remains historical evidence for the certified implementation; this overlay's own manifest is authoritative for its payload.

## Registration adapter and smallest portal extension

`DigiForge\Portal\PrintifyProductIntelligenceFeature` exposes explicit `register()` and `render($baseUrl)` methods. Nothing invokes them automatically. The adapter calls `ProductIntelligencePanel::registerFeatureOnly()` and reuses existing catalog-controller registration; it does not register the existing controller again or attach the standalone Shop 2 filter to shared catalog routes. Existing catalog admission remains unchanged. Do not call ProductIntelligencePanel::register() in this installed profile; that method is retained only for the standalone branch integration. The bundled PrintifyCatalogShopAdmission helper is not activated by the feature adapter. Registration is once per request, rejects missing/incompatible known dependencies, and queues the feature stylesheet only for an authorized `personalized_pod` view. Existing controller/form/ShopAccess checks revalidate runtime access. No database/schema methods are called by the adapter itself.

After the installed extension point is independently reviewed, the integration owner may propose one call to `(new \DigiForge\Portal\PrintifyProductIntelligenceFeature())->register()` in the existing registration extension, and one call to `(new \DigiForge\Portal\PrintifyProductIntelligenceFeature())->render($baseUrl)` in the existing canonical Shop 2 render extension. These are **integration call contracts, not an approved production patch**. Do not copy the candidate Portal.php or assume its method/context names match production. If no extension point exists, a minimal owner-reviewed installed-source patch is a separate prerequisite; this ZIP contains no patch for an unknown file. Preserve every existing hook and global guard. The installed autoloader and asset URL base must be verified.

## Compatibility and schema gates

- ProductIntelligence requires existing MasterCatalogV2Reference::CATALOG_KEY; mapper/repository retain concept lineage, hashes and unknown values. Storage uses existing non-autoloaded WordPress options and audit log. No new feature table migration is needed.
- Gateway/batch require compatible existing catalog repository/policy/contract/client, ownership, ShopAccess, canonical grant/receipt formats, reservations, audit and quota semantics. Reuse them; do not replace them with the standalone candidate's different files.
- FirstPartyOwnerPolicy.php is absent from the installed inventory. It is deliberately **not bundled** or impersonated. Current feature readiness refuses missing prerequisites. The security owner must reconcile the installed signed/native ownership model, either through an explicitly reviewed API compatibility adaptation or a separately reviewed additive shared dependency. No owner identity/configuration is created, and no requirement is silently removed.
- Installed global REST/admin/target guards are present. Verify their six exact research route mappings, four form-action mappings, fixed personalized_pod targeting and catalog admission. Method presence/readiness is not installed-source or production acceptance. No unmapped routes/actions may be exempted or global guards bypassed.
- Known-interface checks in register() are only a runtime fail-closed check. They do not prove semantic compatibility, installed schema, authority or source review. Human installed-source acceptance remains required even when registration returns true. Do not invoke this method in production as a diagnostic under the current authorization.
- Core23, additive catalog24, reported lineage25 and provider-connection1 remain unresolved actual database state. Do not downgrade options/version constants or run boot/migrateIfNeeded as a probe. An absent provider connection table/index would require a separately authorized additive migration, only after verified absence and lineage compatibility; never assume CREATE/ALTER is harmless or reversible.

## Before any installation authorization

Obtain platform-approved non-executing evidence, without retrying blocked endpoints through another route: (1) installed source hashes/interfaces for bootstrap/autoload, Portal, catalog and ownership/global guard components; (2) active database/prefix identification among the two assigned databases, SELECT-only migration/column/index/engine observations and compatible options/audit/registry storage; (3) whitelisted actor/shop and Printify integration/account/external-shop/business/store/program bindings without tokens; (4) target absence and exact feature artifact/source/checksum review; (5) approved minimal portal wiring and a verified recovery checkpoint/restore receipt. No WordPress bootstrap, REST/preview diagnostic or credential extraction is permitted for these observations.

A future authorized deployment must stage the feature outside active code, verify every digest, reject collisions, and use a reviewed publication procedure that cannot expose a partially wired feature. Existing sources and data remain unchanged except the separately approved minimum wiring. No copying of the complete #1253 plugin ZIP. A reconciled shared-component adaptation would change the feature source/package and require its own focused validation; certification of the old standalone ZIP is not certification of that composition.

## Rollback and recovery prerequisite

No existing backup or restore capability is presumed verified. Before installation approval, the release owner needs a fresh consistent checkpoint covering full existing plugin/site code, database and required protected assets/configuration with keys retained only in approved custody; hashes, retrieval, retention, RPO/RTO and an independently verified isolated restore. Creating/restoring that checkpoint is separately authorized work, not performed here.

The future rollback manifest must list precisely the added paths and approved wiring edits with their pre/post hashes. After disabling the newly introduced wiring under approved rollback authority, remove only newly created files whose hashes still match this feature release; restore only approved wiring preimages. A changed destination stops rollback for review. Never delete existing audit/research evidence, drop schema objects, rewind migration options or restore grants/exceptions as authority. Blind whole-database rollback can lose unrelated subsequent activity; it requires a separately approved recovery plan and reconciliation, not this overlay. Keep all safety controls locked throughout.

## Offline build and verification

Build only from an exact committed source:

```
python3 bin/package-printify-feature.py --repository . --ref <40-hex-feature-source-sha> --output /private/printify-feature.zip
```

The output is the feature ZIP and a `.sha256` sidecar. Build is deterministic and reads committed Git objects only. The ZIP contains payload/, two manifests and this instruction document; no installer or bootstrap script. Tests cover allowlist injection, existing-path collisions, incomplete evidence, deterministic hashes, absence of shared replacements, registration idempotency, catalog route reuse, missing-owner denial before hooks/HTTP, existing capability/shop isolation and regression behavior. No production request, catalog grant, exception, reservation or migration is part of this work.
