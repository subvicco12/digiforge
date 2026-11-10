# 2026-11-10 execution setup qualification

Current setup: 35 mock tests pass; full offline 132 tests pass with one existing
skip; PHP unit 1,575 tests / 8,758 assertions pass. Configured static/standards and
HTTP/secret gates pass. The mock suite covers including exact new environment/secret/ref,
literal client pin, no legacy-token environment fallback, separate initial-creation
and update/deletion restrictions, and isolated two-file root/hash verification.
The earlier 2026-11-09 qualification below is historical evidence for c44bafcd.

Remote protection installation was denied HTTP403. No remote execution branch or
ruleset was created. Environment restrictions/admin-bypass flag were read and
verified; secret-name metadata, detailed protection, Actions and billing reads
were403. One repository collaborator (subvicco12, administrator/push) was observed.
A separate review caught unrestricted initial branch creation in the first draft;
that proposal was corrected to require an exact-owner creation rule plus separate
no-bypass update/deletion freeze. Final automated re-review found no remaining
important findings, while remote enforcement remains SECURITY_BLOCKED.

No credential values, Cloudflare API requests, manual workflow dispatch or provider
operations were used. No runtime/plugin files or schema were changed.

---

# Offline qualification and review

This profile was not dispatched. No Cloudflare token was accessed and no account
API request was executed. The new workflow remains literally disabled.

Test-first evidence: the initial 24 contract tests failed because the fixed client
was absent. The implementation passed those contracts, then five additional
negative tests covered main-entry logging/missing credentials, expired validity,
transport confinement and unsafe report directories. Two further behavioral
workflow tests verify tampered-client rejection and a deterministic file-replacement
race: the compiled payload remains the exact bounded bytes whose hash was checked.

Focused result: 31 mock-only tests PASS. Full offline suite: 128 tests PASS with
one preexisting skip. Exact-head source CI is recorded in the final PR handoff. The preserved full PHP unit regression
run passed 1,575 tests / 8,758 assertions. Configured PHPStan, PHPCS and existing
HTTP/secret source gates passed. Workflow YAML and both inline Python programs
parse. No preexisting Printify test was excluded or weakened.

Separate automated source review inspected the disabled workflow, fixed endpoints,
secret-step boundaries, same-byte verification, minimized report and mock tests.
It found no critical/important defects, with environment isolation, execution-path
qualification and public artifact approval expressly remaining blocked. The
reviewer also ran the then-current 29 mock tests. This is not independent human
review and not approval to activate the profile.

The only official action pin was verified against GitHub's actions/upload-artifact
v4.6.2 tag: ea165f8d65b6e75b540449e92b4886f43607fa02. No third-party action, checkout,
Wrangler, dependency install, mutation command or live verification was added.

Detailed Actions permissions, protection configuration and billing reads were
403. The observed environment list is unprotected; basic main metadata reports
protected=false. These are real admission blockers, not assumptions that owner
review or a repository secret has already established a protected execution path.

Runtime PHP/JS/assets, provider transports, vault, owner authorization and schema
files were not modified. No plugin ZIP/version is issued for this workflow-only
task. STOP ALL/external lock ON, automation OFF, RELEASE HOLD preserved.
