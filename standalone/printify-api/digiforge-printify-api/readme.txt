=== DigiForge Printify API ===
Requires at least: 7.1
Requires PHP: 8.3
Stable tag: 0.10.0

The Tools menu displays local diagnostics to WordPress administrators.
Offline catalog validation additionally requires an exact configured shop
(DIGIFORGE_PRINTIFY_OFFLINE_SHOP) and the matching user capability
digiforge_printify_review_shop_<id>. This plugin does not grant that capability.
The validated result supplies a digest and indicates unknown freshness.
No provider authority, credentials, migrations, or scheduling are created.

== Synthetic journey ==
Use a disposable WordPress installation with WP_ENVIRONMENT_TYPE set to
local. Configure DIGIFORGE_PRINTIFY_OFFLINE_SHOP as a numeric fixture shop
string, DIGIFORGE_PRINTIFY_OFFLINE_ACCOUNT as a fixture account reference,
and DIGIFORGE_PRINTIFY_SYNTHETIC_JOURNEY as boolean true. The operator
needs manage_options and the matching shop review capability. In Tools,
open Printify API and select Run synthetic journey.

Each run creates a fresh in-memory shop, product and order. Fixture status,
quotes and tracking demonstrate the flow and carry no provider confirmation.
The tool holds no credentials and has no provider transport, job scheduler
or operational authority.

== Disposable workspace ==
The local operator workspace also requires DIGIFORGE_PRINTIFY_DISPOSABLE_STORE
and the exact printify_fixture database. Create the fixture-only InnoDB table
using the DDL in tests/disposable-workspace.php. No plugin hook creates it.
Select an operation, enter the typed fixture JSON and submit. The revision
shown in the form protects against competing updates. A recent audit view
includes only operation identity and disposition. A bounded workspace holds
100 attempts. Use a fresh disposable database for another acceptance run.
Configure additional fixture shops with DIGIFORGE_PRINTIFY_SYNTHETIC_SHOPS
as a shop-ID-to-fixture-account map. The selector lists only shops the
current WordPress operator is assigned to review.

Field-specific forms cover common catalog, artwork, product, order and
webhook actions. Use Advanced JSON for compound requests. Artwork accepts
a bounded fixture PNG. The in-process fixture webhook adapter covers
order:created, order:updated, product:created and product:updated.
