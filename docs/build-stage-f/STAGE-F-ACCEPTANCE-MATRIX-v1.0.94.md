# Stage-F Acceptance Matrix — v1.0.94

This matrix binds the non-recovery Stage-F completion decision to current repository, release-certification and runtime evidence. IMPLEMENTED or CERTIFIED never means external activation authorization.

| Workstream | Repository / runtime evidence | Acceptance state |
| --- | --- | --- |
| F1 repository/runtime convergence | DigiForge 1.0.94, schema 23, readiness controls, immutable release lineage and bounded/idempotent operations | VERIFIED — production health OK; schema 23/23 |
| F2 Digital Product Factory | DigitalFactory lifecycle/repository, production assets/packages, deterministic + semantic QA, Gate 2, bounded portal lifecycle/readiness projection | IMPLEMENTED |
| F3 Personalized POD Factory | artwork/render evidence, readiness gate, supplier/cost/margin evidence, authorization package + preflight operator projection | IMPLEMENTED — EXTERNALLY LOCKED |
| F4 Listing Factory / Gate 3 | listing readiness evidence, approved package compiler, human Gate 3 portal workflow, Etsy reconciliation | IMPLEMENTED — PUBLISH AUTHORITY SEPARATE |
| F5 operations foundations | order readiness/reconciliation, fulfillment plans/exceptions, finance periods, analytics and audit/reconciliation | IMPLEMENTED — external provider/money/tax authority remains separate |
| F6 Approval Inbox / Dashboard | consolidated approval inbox, Stage-F operator pulse, attention/reconciliation, responsive portal CSS | IMPLEMENTED |
| F7 failure/recovery | governed recovery architecture and historical drill evidence | DEFERRED / NON-BLOCKING FOR THIS CLOSURE — recovery drill is not claimed current or passed |
| F8 security/integrations | capability/nonces, credential vault, STOP ALL, external-action interlocks and audit scans | CERTIFIED on v1.0.94 exact head `e0724771f9e194e127852f999a86eaf3c4fac436` by Engineering & Safety Audit #3668 |
| F9 release certification | syntax, legacy/unit, WordPress/MariaDB integration, diff, legacy-identifier, secret, external HTTP, reproducible package and package-boundary stages | CERTIFIED — Audit #3668 SUCCESS |
| F10 final artifact/runtime | exact audited ZIP + checksum + manifest, followed by production runtime verification | CERTIFIED ARTIFACT INSTALLED — artifact #11117403126; production v1.0.94 / schema 23/23 / health OK / STOP ALL ON / externally locked |

## Certified v1.0.94 evidence

- Exact audited source head: `e0724771f9e194e127852f999a86eaf3c4fac436`.
- Engineering & Safety Audit #3668: SUCCESS.
- Certified GitHub artifact: #11117403126.
- Outer artifact digest: `sha256:557ca2a57a28d39647e4f16689a5f34eef3a0997ecb5ca2c0a7f68b409b1beeb`.
- Inner `digiforge.zip` SHA-256: `8833f5ded41cfd4789242557dd72408813b8f53def88131f3909465ebbb68e33`.
- Manifest binds plugin 1.0.94, database schema 23, exact audited source head, `external_actions_performed=false`, and `production_activation_authorized=false`.
- Certified source was squash-merged to main commit `27c565cee27ad0d2bba0820de02d9b864834d15d`.

## Production runtime acceptance evidence — 2026-10-01

Read-only post-install verification of the main DigiForge site directly evidenced:
- health `ok`
- plugin version `1.0.94`
- database schema `23 / 23`
- STOP ALL `true`
- externally locked `true`
- activation not authorized
- automation unarmed / disabled
- no effective feature switches
- audit healthy
- queue query verified
- no expired running leases.

The overall readiness endpoint remains REVIEW_REQUIRED because historical recovery-drill evidence is not bound to the current release. This matrix does not reinterpret that evidence as PASS. Recovery is explicitly deferred from this non-recovery product-completion closure.

## Non-negotiable safety acceptance

- STOP ALL and the external safety lock remain authoritative.
- No human approval automatically grants Etsy publish, POD production, fulfillment dispatch, refund, tax/GST filing or money movement authority.
- UNKNOWN external outcomes require reconciliation before retry.
- Etsy publish authority remains separate from listing readiness and Gate 3.
- Personalized POD human/render/package review remains evidence only; provider production authority remains separate.
- Fulfillment readiness or an approved plan is not provider execution authorization.
- Finance/analytics evidence cannot move money, approve refunds, file tax/GST or sync external accounting.
- Recovery is not claimed current, certified or passed by this matrix.
- No row may be interpreted as activation authorization.

## Closure rule

The non-recovery Stage-F product-completion line is accepted only when this evidence-only closure change itself passes the repository Engineering & Safety Audit on its exact head. That audit certifies this documentation/test change; it does not retroactively alter the immutable v1.0.94 release artifact or authorize any external action.
