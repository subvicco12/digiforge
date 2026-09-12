# Changelog

All notable DigiForge changes are recorded here.

## Unreleased

### Added

- Fail-closed automation arming guard.
- Typed non-secret settings registry and validation.
- PHPUnit unit-test foundation and WordPress integration-test bootstrap.
- PHPStan and PHPCS quality gates.
- Audited plugin packaging with SHA-256 checksum.
- CI coverage for main, build branches, phase branches, and pull requests.

### Changed

- STOP ALL now defaults to ON for new installations.
- Production packages exclude development, test, documentation, and CI files.

### Safety

- No integration, worker, schedule, publishing, fulfillment, or production deployment was enabled.
