=== DigiForge Printify API ===
Requires at least: 7.1
Requires PHP: 8.3
Stable tag: 0.15.0

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

= Direct manual read profile (offline qualification) =

The Standalone ZIP does not require Cloudflare, a replacement Core, the
Companion, or DigiForge AI. The approved Blueprint v1.2 direct profile is
being qualified with disposable WordPress and intercepted synthetic GETs.

On a separately approved disposable installation, use Tools → Printify API
and configure local account, shop, integration and opaque custody reference
metadata. The assigned operator needs manage_options and the corresponding
digiforge_printify_review_shop_<id> capability. The disposable harness
supplies the exact approved scope, synthetic credential and isolated fixture
responses. No token is entered through an admin form. The release candidate
does not support real credential enrollment or outbound provider traffic.

A separately authorized production read pilot must first qualify the exact
server-side secret source, a least-privilege read-only Printify token, and
shop/operation scope. Keep the token out of WordPress options, UI, exports
and ZIPs. A WordPress or host compromise can expose a runtime-readable PAT.
Do not copy the synthetic harness or its hooks to a production site.

For an authorized install, verify the ZIP hash and take approved matching
files/database recovery points. Upload only this ZIP as a new plugin and
stop on any replacement prompt. Activate after independent coexistence
qualification. With production held, the panel may show offline metadata
and diagnostics only. Verify unchanged incumbent plugins and checkout.
For rollback, deactivate Standalone and preserve existing scoped audit
evidence. Do not overwrite DigiForge or the Shipping Method plugin.
