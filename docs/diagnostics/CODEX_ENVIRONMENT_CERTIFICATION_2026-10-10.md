# Codex environment certification diagnostic

This non-runtime document tests GitHub publishing from an isolated diagnostic
branch. It changes no application behavior and authorizes no deployment,
production change, automation, or external business action.

Base: `844107ab9baf7e1303cd47708e9eab3bc700b348` (fetched main).

Observed checks on 2026-10-10:

- Managed-proxy HTTPS probes: GitHub, GitHub API, Packagist, Packagist repository,
  WordPress, and the WordPress SVN test-library bootstrap returned HTTP 200.
- Composer installed 31 development dependencies; its live advisory audit
  returned empty advisories and abandoned-package lists.
- PHP unit suite: 1,152 tests, 7,340 assertions passed.
- WordPress integration suite: 199 tests, 1,840 assertions passed.
- PHP syntax: 941 files, zero failures.
- Configured PHPStan and PHPCS checks passed.
- Git fetch and push dry run succeeded. Actual publishing and independent
  exact-SHA confirmation are separate checks recorded after this commit.

Limitations: published-configuration latestness was not independently exposed
by the runtime status interface. PHP tooling required explicit PATH selection;
network commands required shell network permission. Main has no tracked
Composer lock file. Existing test warnings and database-error output remain.

STOP ALL, external safety lock, and automation OFF remain required. No safety
control changes, Etsy/POD actions, PR creation, merge, or deployment are part of
this certification. The finance PR and recovery workspace are excluded.
