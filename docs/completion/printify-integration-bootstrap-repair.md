# PR #1192 WordPress fixture integration repair

Audit #4716 ran exact head 32d6e83e87a6100e63ceb3e4a625c7bad6a75c5f.
The WordPress bootstrap failed before plugin tests with `WP_Secrets_Store not found`.
The installer downloaded released core but exported trunk PHPUnit fixtures.

Authoritative upstream definition: WordPress trunk
`src/wp-includes/secrets/interface-wp-secrets-store.php` (`@since 7.2.0`).
Trunk `tests/phpunit/includes/secrets/class-mock-store.php` implements that
interface; trunk bootstrap requires this fixture at line 352 (observed
2026-10-17). Released 7.1.3 core lacks this interface. The passing disposable
local environment uses 7.1.3 with release-compatible fixtures. This is not a
DigiForge autoload or plugin-package interface: supplying a stub or copying
trunk security code into released core would conceal the mismatch.

Reuse the installer repair already present in #1189: read the actual installed
core version and retrieve includes, data and config from that exact release tag.
Reject non-release versions; missing release fixtures remain fatal. No fixture
or production security interface is skipped, stubbed or replaced. Composer
advisories remain mandatory, and all three catalog smoke scripts are included
alongside the existing audit checks.

Offline installer regressions execute the shell installer using simulated
release downloads, verify all fixture URLs match actual core (including when
requested version is `latest`), and reject development core before fixture
retrieval. The actual registered REST integration cohort passes locally:
3 tests / 52 assertions. A fresh disposable prefix avoids stale custom plugin
migration tables; no production or staging database is involved.

Shared changes requiring coordinator review are limited to
`bin/install-wp-tests.sh` and the catalog smoke step in
`.github/workflows/digiforge-foundation-audit.yml`. No merge is authorized.
Final acceptance requires the new exact-head hosted audit, including package
identity and all downstream security checks; prior local results alone do not
certify the repaired candidate.

STOP ALL and external safety lock remain ON; automation remains OFF.
No provider calls, live credentials, Finance changes or operational activation.
