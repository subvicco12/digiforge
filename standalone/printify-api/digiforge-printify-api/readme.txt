=== DigiForge Printify API ===
Requires at least: 7.1
Requires PHP: 8.3
Stable tag: 0.15.1

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
This synthetic journey holds no credentials and uses no provider transport.
It creates no scheduler or operational authority.

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

= Direct manual read profile =

The Standalone ZIP does not require Cloudflare, a replacement Core, the
Companion, or DigiForge AI. The Blueprint v1.3 direct read profile is
held by default. Offline qualification uses intercepted synthetic GETs.

On a separately approved disposable installation, use Tools → Printify API
and configure local account, shop, integration and opaque custody reference
metadata. The assigned operator needs manage_options and the corresponding
digiforge_printify_review_shop_<id> capability. The disposable harness
supplies the exact approved scope, synthetic credential and isolated fixture responses.
Production credential enrollment and outbound traffic require separate authorization.

A separately authorized production read pilot must first qualify the
server-side secret source on the target host, a least-privilege read-only Printify token, and
shop/operation scope. Keep the token out of WordPress options, UI, exports
and ZIPs. A WordPress or host compromise can expose a runtime-readable PAT.
Do not copy the synthetic harness or its hooks to a production site.

For an authorized install, verify the ZIP hash and take approved matching
files/database recovery points. Upload only this ZIP as a new plugin and
stop on any replacement prompt. Activate after independent coexistence
qualification. With production held, the panel may show offline metadata
and diagnostics only. Verify unchanged incumbent plugin states and files.
For rollback, deactivate Standalone and preserve existing scoped audit
evidence. Do not overwrite DigiForge or the Shipping Method plugin.

= Server-side configuration (Blueprint v1.3) =

For separately authorized direct reads, the hosting account operator creates
a private, regular JSON file outside both the web document root and WordPress
directory, at a canonical absolute path with mode 0600. On hosting with POSIX
available, the file must be owned by the site's PHP user. Define only the file
path in wp-config.php:

  define('DIGIFORGE_PRINTIFY_DIRECT_CONFIG_FILE', '/home/SITE/private/printify-read.json');

Use an exact JSON object with schema (integer 1), scope (an object containing
exact string fields account, shop, integration and custody), state (string
approved-read-only), expires_at (integer Unix seconds), token (the approved
read-only PAT), and three booleans stop_all, external_lock and release_hold.
Keep all three true while held. Use the same scope in Tools → Printify API.
The shop field is a numeric string and custody is an opaque custody-ref:
identifier. The operator independently verifies least-privilege provider
scopes before attesting approved-read-only; the plugin cannot inspect a PAT's
granted permissions. Never submit the token to WordPress admin forms.

Activation performs no connection check. Once separately authorized to lift
all three read holds, use the protected Shops Read operation for a manual
diagnostic. A scope or credential denial is not retried. Rotate by writing a
new private file and atomically renaming it over the approved path. To revoke,
reassert the holds or remove the file and revoke the PAT at the provider under
a separately authorized procedure. The plugin re-reads configuration before
dispatch and after the response. An already-issued GET cannot be recalled.

= Catalog and product review =

Use Blueprint Size Guide Read for documented measurements and units. Size
guides describe the product, while print placeholders describe artwork areas.
Neither establishes bleed, safe zones, DPI or physical production tolerances.
Use Blueprint Provider Read for decoration methods and any stated production
time. Variant detail shows the decoration method only when the source
placeholder supplies it.

Product SKU Read resolves an exact literal SKU within the selected shop,
product, blueprint and provider. It returns one enabled variant and its
product-linked print placements. Absent, disabled, ambiguous and mismatched
evidence denies. SKU values are strings, including numeric-looking ones.
Catalog rates describe a method, variant, currency and reported destination.
They do not establish address-specific shipping or fulfillment commitments.

Product Artwork Read takes the exact product, blueprint, provider, complete
variant ID set and a linked image ID. It returns only the confirmed product
placements and safe source metadata for that image. Missing association or
mismatched scope denies the read. It never reads the account media library
or fetches an image from a provider URL.

Catalog shipping rates include the source plan ID and handling-time range.
The panel leaves delivery estimates and any undocumented unit unknown.
Order detail shows the scoped carrier, available tracking reference and
delivery timestamp as text. Missing shipment evidence stays unknown.

The panel retains up to eight redacted read outcomes for the same profile
and session, visible for up to one hour. The latest observation remains
separate from these summaries. A failed refresh can show explicitly stale
data only for the same request. No retry is automatic.

Product and order lists expose a manual Read next page button when a current
scoped observation has an admitted next page. End-of-list and empty pages
are visible in the panel. Provider continuation URLs are never followed.
Use Copy redacted evidence to copy the bounded current-session outcome
summaries. The JSON excludes receipt payloads, identifiers, and secrets.
Provider Retry-After delays, when valid, appear as bounded wait guidance.
Current catalog observations offer an operator-initiated path from blueprint
to provider to variant. Each button performs one newly authorized scoped
read, using the same profile, operation nonce and quota as the manual form.

= Guided Printify connection and one-request pilot =

Managed mode is opt-in. After a separately approved installation, configure
DIGIFORGE_PRINTIFY_MANAGED_DIR in server-side WordPress configuration with the
absolute canonical path of a dedicated existing directory outside WordPress
and the web document root. Use mode 0700, owned by the site's PHP worker. An
inaccessible directory, symlink or unsafe file fails closed. No directory is
created on activation. Remove obsolete legacy direct configuration after
revoking any old PAT. While the managed directory constant is defined, the
legacy direct and fixture paths are held.

Sign in over HTTPS as a WordPress administrator assigned to the active
Standalone shop and use Tools > Printify API > Printify connection. Set the
account/shop profile first, then enter a least-privilege read token through
the masked field. Saving it does not connect to Printify. Never put a token
in the database, wp-config.php, a ticket, or a copied diagnostic.

A separately authorized operator may arrange one server-side approval using
DIGIFORGE_PRINTIFY_SHOPS_PILOT, an array with:
* id: fresh 32-character lowercase hexadecimal random ID.
* actor: exact WordPress user ID integer.
* site: exact home_url('/') including trailing slash.
* scope: associative array with the exact shop, account, integration and custody strings.
* profile_revision: exact current revision shown under Approval details.
* issued and expires: integer Unix seconds, issued after the token file's
  modification second, expiry at most 300 seconds later.

Install this short-lived grant only after the owner separately authorizes the
specific live read. Do not enable it merely because a token is present. The
assigned operator clicks Test one Shops request. The grant is consumed before
the quota check and before dispatch. A failure or interrupted attempt uses the
grant and cannot be retried. Only the fixed HTTPS Shops GET is eligible, with
existing scoped quota and current session checks. The panel shows a redacted
result and remains held. Remove the server grant after the window; never reuse
its ID. Normal catalog, order and mutation dispatch stay held in managed mode.

Disconnect removes the active local token and any orphan replacement files
owned by the worker; it retains the nonsecret used-grant records. Then open
Printify account API settings, revoke that personal access token, and verify
it is revoked there. A local disconnect does not revoke it at Printify. Revoke
old tokens after rotation as well.

Custody on shared hosting: confirm with the hosting administrator whether
Hostinger backups, restore points, support access, request tracing and export
jobs cover this directory or request bodies/headers. A directory outside the
web root does not establish backup exclusion. Disable request body and
Authorization header capture for this route. Keep the token lifetime minimal,
disconnect and revoke promptly, and handle snapshot access as secret access.
If a backup may contain the token, revoke before restore and remove restored
credential and approval configuration before serving traffic. Treat any restored
used-grant state as untrusted; require a fresh approval ID and do not restore or
replay a prior grant. If that custody policy cannot be accepted, keep managed
configuration absent and the connection held.
