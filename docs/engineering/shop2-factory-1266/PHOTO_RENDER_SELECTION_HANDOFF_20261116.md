# Shop 2 immutable photo selection and local render handoff

Candidate implementation on Lane A PR #1267; independent capability acceptance pending. No provider operations, release permission, physical certification or owner artwork approval. STOP ALL and external lock remain ON; automation OFF; manual approval; RELEASE HOLD.

## Normal operator and buyer journey

Use the existing authenticated customer-editor order link/shortcode. An authorized buyer uploads/crops every ordinary PHOTO slot, saves text and ordering, then chooses **Submit current photos for operator review**. The server resolves retained assets; request-supplied source references are never selected.

A different, currently signed Shop 2 operator opens the same link and sees a review screen: inspect each photo and position, enter a note and explicitly approve/reject current inputs. Select an existing validated template. Use an existing immutable reviewed recipe, or explicitly approve the image-only contained-photo layout. The latter requires retained verified local safe-zone AND bleed geometry, one print area, no seam collisions and exact family slot counts (SINGLE_PHOTO:1, PHOTO_COLLAGE:4, MULTI_PHOTO:2/3/4). It neither guesses supplier margins nor positions arbitrary text. Other layouts remain held until an explicit reviewed template/recipe exists.

Prepare local artwork, inspect the exact PNG and separately approve that local artifact. The buyer refreshes the same link and may record a digital-preview preference. The displayed PNG and final LOCAL PNG have identical hashes. This is not a product-context mockup, native supplier preview, physical-print approval or order-execution permit.

No raw JSON or database editing is required after the existing order grant, approved schema/mapping and qualified template are available. Order import, grant provisioning and missing supplier/template qualification retain their existing gates.

## Consumer contract (Lane B)

`DigiForge\POD\CustomerPhotoSelection` exposes:

- `forOrder(string $shop, int $order, int $line, ?int $expectedRevision=null): array|WP_Error|null`: authenticated current signed POD operator, exact Shop 2 order/line binding; requires a current independently approved selection. Supply expectedRevision for explicit CAS/currentness consumption. Missing selection is an error, not a legacy fallback.
- `forRender(array $render): array|WP_Error|null`: used by renderer/evidence verifier; returns null only for unmanaged legacy inputs with no managed audit marker. A missing managed head, currentness mismatch or ambiguous managed selection fails closed.
- `submit(session, editorVersion, expectedRevision)`, `review(session, selectionHash, expectedRevision, decision, note)`; `approvePhotoLayout`, `prepare`, `approveRender` and `buyerPreview` implement separate explicit transitions.

Returned selection v1 binds revision, parent, selected canonical submission ID, authenticated session/access identity, editor version, order/line/customer, product version, approved mapping ID+complete mapping hash, approved schema ID+definition hash, typed canonical payload/hash, slot order, crop and original/source/result hashes. Records and reviews are sealed, audited, append-only options with a CAS head; no new tables/migrations or duplicate canonical payload storage.

The existing UNIQUE(line,schema,payload) canonical row is reused for identical bytes. A new editor version still gets a NEW selection and independent review epoch, even if pixels/payload are identical. Selection rejection does not mutate an old canonical approval. Canonical rejection requires a changed payload/schema. Each new render evidence identity and deterministic recipe binding includes selection SHA; identical pixels cannot inherit an old render approval. Existing approvals stay immutable and become unusable when stale.

Lane B must explicitly consume this contract in its owned currentness/held-export path and independently accept the combined candidate; this commit does not change its consumer or pins. Do not trust client-supplied submission IDs, selection records, source references or proof metadata. Revalidate at consumption; receipt retention is not a permanent authority grant.

## Failure and authorization behavior

Signed POD and orders permissions plus a distinct reviewer are required for input review. Approved reads recheck reviewer authorization. Existing buyer ownership, nonce, scoped permission and access-revocation checks remain. Changed mapping/schema/access/editor/photo bytes, missing slots, replaced or reordered assets, stale selection revision/hash, deleted head, missing/corrupt audit/review and revoked roles block reuse. Managed photo artifacts require deterministic current proofs. Digital orders cannot be selected through this interface.

Line-scoped database locks, transactional options/submissions/render/audit tables, bounded records, immutable inserts and CAS are used. Audit failure rolls back. A lost COMMIT acknowledgement returns `photo_selection_commit_unknown` with no automatic retry; explicit replay resolves the durable current head without granting approval. This is tested synthetic crash-window behavior, not host crash-durability qualification.

## Reproduction and scope of evidence

See the accompanying receipt/logs for exact commands/counts. WordPress PHPUnit runs the registered REST routes against an actual isolated MariaDB database and real retained image bytes; it is not a public HTTP installation test. Browser tests exercise the actual markup/JS/CSS at 390/768/1280 widths with fully intercepted synthetic responses, including keyboard submission and stale-preview disabling. Independent authenticated HTTP/browser acceptance is requested separately.

Intentional audit/COMMIT query failures are retained in successful negative-test logs. Initial UNIQUE canonical-payload, derived rejection-state and disclaimer regressions were corrected. Earlier overlapping disposable DB runs are retained as invalid concurrent-fixture runs; final regression uses one fresh private socket/database serially. Missing local runtime/module setup failures are also retained, with configured reruns. The negative-role fixture initially removed a direct capability while the administrator role still granted it; the corrected fixture sets an explicit denial. The existing keyboard reorder browser test now awaits the actual reorder response instead of matching an unchanged status string before its asynchronous request finishes.

No 151-ledger requirement is promoted by this implementation. S2-ART-14 and S2-ORD-06 are candidates for independent local photo capability review only; supplier, production, buyer identity provisioning and combined held-export gates remain separate. Artistic cutouts/engines, arbitrary mixed photo/text presets, AI provider execution, research orchestration and budgets retain their existing independent requirements.

## Additive resolution contract v1 (Lane B request 6907176879)

`resolveForOrder(string $shop,int $order,int $line,?int $expectedRevision=null): array|WP_Error|null` is the authoritative managed/unmanaged discriminator. Exact current signed POD actor plus stored shop/order/line admission is required before reading state. A current managed selection returns the same independently approved immutable record. `null` means an admitted legacy order with no selected head AND no retained managed-photo audit marker. An expected positive revision never yields legacy null. Unavailable/corrupt evidence, a managed marker without a head, stale revision or foreign scope returns WP_Error. Historical markers are order-scoped: uncertainty involving another line holds the order, rather than guessing line-level legacy eligibility. Existing `forOrder()` stays strict and converts only authoritative legacy null into selection_required; consumers must never translate arbitrary errors to legacy fallback. `forRender()` reuses the same retained-marker reader. No Lane B consumer or pin is modified here.
