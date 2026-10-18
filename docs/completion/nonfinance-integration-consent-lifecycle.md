# Independent non-finance integration and consent lifecycle

## Verified dependency state

Read-only GitHub verification on 2026-10-17:

| Candidate | Exact head | Exact-head audit | Relationship |
| --- | --- | --- | --- |
| Main | `844107ab9baf7e1303cd47708e9eab3bc700b348` | Existing baseline; not re-audited | Target only; no merge performed |
| Draft #1194 | `135a3dc2bfd6602dc620ca6ea79f1072c4c3f024` | #4721 PASS | Ancestor of #1195 |
| Draft #1195 | `02a67a61b7247457546a4530d26ca971d3645ec5` | #4725 PASS | Contains #1194 plus nine shipping contract/test/documentation files |

Disposable `git merge-tree --write-tree` simulations for #1194/#1195 in both orders, and main/#1195, all produce the exact #1195 tree `4d18ab6365c8951cd869981bf71be610c6867d51`, without conflicts. There is no independent pair of divergent implementations to merge. Shipping edits retain their concrete grant/revocation/dispatch controls and strict GET boundary; supplier facts remain unverified. Do not reapply #1194 or independently merge divergent source branches. This follow-on branch is based on the verified #1195 head; it does not modify either prior PR branch.

## Smallest proven independent defect batch

The existing five-minute manual research consent reader used a strict-before expiry comparison and repeated wall-clock reads. At the exact expiry instant a sealed plan could still be accepted. The portal also offered confirmation for already-consumed consent, including after a failed attempt without changed usage, and did not check durable receipt-read availability before presenting that action.

The fix passes one current timestamp into the private validity check, denies at `expires_at <= now`, and checks receipt existence directly before rendering confirmation. Any receipt value or database read error suppresses the action and displays a read-only recovery message. Rendering never consumes consent or calls a provider. Existing execution-time atomic consumption, current quote/source/policy checks, expiry, actor/shop permissions, nonce, immutable signatures, REST admission and global safety controls remain authoritative.

Three regressions reproduced failures before the fix. The focused native `PortalResearchRunAcceptanceTest` suite passes 21 tests / 728 assertions, using synthetic local records and intercepted HTTP only. Existing quote-drift, cross-shop, stale-evidence, audit-failure, replay and successful synthetic journeys remain included. Final changed-head hosted audit is required and reported in the draft PR metadata; this document does not preclaim that result.

## Acceptance reconciliation

The existing original-source checker passes: 1,771 source records, 376 classified matrix records, 268 distinct atomic mandatory non-finance criteria. Counts remain **246 IMPLEMENTED / 22 UNVERIFIED (91.79% bounded candidate implementation coverage)**, not final-product or verified-main completion. Eleven require owner approval, seven external evidence, and four composite criteria require authentic ownership, licensed mockups, production authority and installed-site workflow evidence. The implemented manual-research leaf gains precise regression references; no open criterion is promoted.

Authentic Etsy/Printify identity, approved supplier/variant/geometry/shipping/rights evidence, deployment-site acceptance, operational authority and Finance authenticity remain outside this batch. Standard POD and Jewelry remain deferred operational scopes; four-shop architecture is preserved.

STOP ALL ON, external safety lock ON, automation OFF. No Finance changes, provider calls, credentials, activation, orders, publishing, production writes, merges or deployment. Release hold remains. The next batch should address only another reproducible software defect; owner/external evidence cannot be replaced by synthetic tests.
