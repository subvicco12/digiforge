# Mission B implementation and source ownership

## Governing originals read
- Ultimate Master Blueprint v6.0: https://docs.google.com/document/d/1isN_2m7eu5DTjykY7X67woiPx7wluhPc/edit
- Development Operating Manual v1.0: https://docs.google.com/document/d/1YBvHZktB__bf3iV8qM00hCAobayM-VM1/edit
- Master500v2 Research & Migration Plan: https://docs.google.com/spreadsheets/d/1IGtuARAZ1epBCbszFWgxH6weRj1Bhxvi/edit
- Existing repository Four-Day MVP Blueprint20261103, First Launch Master Planv1.1, Master500 source/promotion contracts and Day4 held-workflow acceptance.
- Dedicated Printify blueprint: https://drive.google.com/file/d/1zLxoFaW6-99C6_pYExjpgbHitBvHJJXb/view
Historical versions/control states in originals are not current runtime facts. Explicit current user safety boundaries override historical ON values. Master500 v1/v2 lineage is not modified or promoted. R4 qualified review package is owner-confirmed unavailable; no artwork or certification is invented.

## Source inventory and integration decision
main3758d1385f7d4a62947248822c1fe1c89ec9818e is the common merge-base of the three lanes, reverify before release. PR1253 OPEN/DRAFT head6e521719d925c75f27aed5ccdd47048cc1e5a15d; PR1256 OPEN/DRAFT headd32fc91ae7b559857b6a1dc81798eb6c3e45ed1f; PR1258 OPEN/DRAFT heade957ba096751dc34254cdbcbefed13b625f5e507, targeting the1253branch. These are development candidates, not production equivalence.

MissionB starts from qualified .16 source55cb4b6852d37942f75fb89fd8ca035ca5f4cfb8 (descendant of1253) on its own branch. .16 complete ZIP0de713d66c7e03a91d3a123e43b934340e75fe1bc3124e3e6c11b1e517a3cc52 and .17 complete ZIP5303ef7eb5ede7dab770599f5cfd279022c77d3d8665a3d5ab1d2fce5ef83dac differ only in version metadata and two scheduler runtime files. This establishes qualified artifact lineage, not exact current installed bytes.

| File/component | Owner/source | MissionB handling |
|---|---|---|
| Existing Printify catalog/product/transport, native ACL and credentials | PR1253 + qualified .16 installed-lineage composition | Retain byte-identical; consume existing contracts, no duplicate sender |
| ScheduledTaskReadModel/SchedulerDependencyPanel | MissionA e957ba09 | Explicit reviewed exact two-file import + exact tests, pinned by preservation manifest |
| ArtworkGeometryQa/PersonalizationPayloadContract | PR1256 d32fc91a | Selective pure validator import, byte-identical; no renderer/assets/migration/factory wholesale copy |
| Portal.php/authentication/GlobalOwner/Operators | Installed .16/.17 lineage | No source edit or replacement |
| ProductIntelligencePanel | Existing API lane | One registration call for new Shop2-only panel |
| New Personalized* preparation/repository/panel files | MissionB | Exclusive new offline contracts, ledger and UI |
| Schema23/24/25/provider1 guards | Shared existing implementations | No edits/new tables/migration |

Existing schemas are not marked installed by source inspection. New ledger uses only wp_options and audit_log, transactionally checks both are InnoDB and fails closed otherwise. Existing production schema25/legacy23/Etsy4/provider1 observations remain user evidence. No bootstrap or migration performed in production. No PR1253/1256/1258 merged or modified by MissionB; no new credentials/provider activity.

## Implemented vertical slice
Existing supplier snapshot + original concept lineage -> exact blueprint/provider/variant -> versioned imported native-layer template/fixed PNG -> typed buyer values -> local geometry and deterministic proof -> transactionally audited immutable review -> correction as child proof -> held order preparation -> existing Printify GET description/ownership mapping -> optional approved existing listing package. The exact field values are reused in proof/native instructions and hashed; no separate divergent production text rendering. Fixed art remains unchanged and separate from native text.

New private portal section appears only at df_view=pod_personalized&df_shop=personalized_pod for an editing actor with existing research/products/POD/listings permissions. Every POST validates nonce/shop/current profile/session/native capabilities independently. Provider preparation additionally requires connections permission. Viewer cannot use these controls. Existing product API write methods remain behind their original explicit authorization and safety interlocks; the new service has no transport, reservation or order-submit method.

API/behavior contracts retained: catalog blueprints/provider lists/variants/areas/size/shipping evidence; product reads, image upload/create/update and documented native options through existing governed pipeline; explicit manual provider-native personalization/mockups where API support is absent. Orders are prepared/held only; request address/payment/line evidence and separate production gate are never fabricated. Provider-side certification/live connectivity remain UNVERIFIED.

## Import/UI usage
Use the existing supplier workflow SHA, then submit JSON with exactly shop_key,fixture_id,template,artwork,values. Template requires exact product/version/source/layer digests, selected blueprint/provider/variant, width/height/dpi, three typed native fields and exact layer IDs/bounds/font sizes. Optional qa_geometry follows the existing PR1256 geometry contract. Artwork is canonical bounded fixed PNG base64+SHA256+native_text_embedded=false. FL03 limits13/13/18 are enforced; FL04 limits must come from the missing qualified artifact. No production defaults for FL04 fields or geometry. See the clearly synthetic MissionBFixture for the contract shape, NEVER use its layer geometry/artwork for production.

Local proof review does not approve provider geometry/fonts/rights; gates remain. Downloaded JSON is private evidence, not a submission permit. Existing product-package export can be linked optionally, with workflow/product-scope revalidation. Real order IDs, customer addresses, actual provider image IDs and accepted external mapping are deliberately not inferred.

## Remaining implementation/evidence gaps
R4 art/layer/font files/qualified constraints/rights missing; actual provider IDs/variant equivalence and geometry acceptance not supplied. Proof SVG uses browser text and declared boxes; glyph metrics, rasterized native output parity and legibility need actual fonts/provider acceptance. Photo rendering/cropping uses the existing manual workflow; unsupported photo inputs are denied here. Real paid-order resolution/submission remains on existing governed order services and requires accepted bindings. Commercial Etsy text proposals are unreviewed copy, not demand/keyword research. No rates/costs/taxes invented. Historical reservation22 remains PENDING/UNKNOWN and is never touched.

## Controlled next steps
1. Restore/find original R4 package; verify hashes, native layer/field constraints, fixed-only art and commercial rights without rebuilding approved art.
2. Import real template/art into this local proof path and retain exact provider/variant/area evidence. Accept mug wrap and poster trim/bleed from supplier sources.
3. Human review local proof, provider-native preview parity, qualified fonts and mockups; verify shipping/regions/fees/pricing and review copy.
4. Reconcile existing external product IDs with separately authorized read-only provider calls, exact account/shop binding and current variants. Do not Save/create duplicates.
5. Only after separate install/acceptance/activation grants use original controlled provider/draft pipelines. No publication/order/automation authority is created by this candidate.
