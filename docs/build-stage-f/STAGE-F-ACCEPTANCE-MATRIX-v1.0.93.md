# Stage-F Acceptance Matrix — v1.0.93

This matrix binds the final Stage-F acceptance decision to repository evidence and the exact-head Engineering & Safety Audit. A row marked implemented is not a production activation and does not authorize any external action.

| Workstream | Repository evidence | Acceptance state |
| --- | --- | --- |
| F1 repository/runtime convergence | version/schema/readiness controls, immutable release lineage, bounded workers and existing U3 repair/idempotency contracts | IMPLEMENTED — runtime post-install verification still separate |
| F2 Digital Product Factory | DigitalFactory lifecycle/repository, production assets/packages, deterministic + semantic QA, Gate 2, portal lifecycle projection | IMPLEMENTED |
| F3 Personalized POD Factory | artwork/render evidence, readiness gate, supplier/cost/margin evidence, authorization package + preflight operator projection | IMPLEMENTED — EXTERNALLY LOCKED |
| F4 Listing Factory / Gate 3 | listing readiness evidence, approved package compiler, human Gate 3 portal workflow, Etsy reconciliation | IMPLEMENTED — PUBLISH AUTHORITY SEPARATE |
| F5 operations foundations | order readiness/reconciliation, fulfillment plans/exceptions, finance periods, analytics and audit/reconciliation | IMPLEMENTED — LOCAL/READ-ONLY CERTIFICATION |
| F6 Approval Inbox / Dashboard | consolidated approval inbox, Stage-F operator pulse, attention/recovery, responsive portal CSS | IMPLEMENTED |
| F7 failure/recovery | fail-closed tests and governed recovery architecture | CERTIFICATION DEPENDENCY — destructive staging drill remains separately authorized |
| F8 security/integrations | capability/nonces, credential vault, external-action interlocks and audit scans | EXACT-HEAD AUDIT REQUIRED |
| F9 release certification | Engineering & Safety Audit: syntax, legacy/unit, WP integration, diff, legacy-identifier scan, secret scan, HTTP scan, package, boundaries | EXACT-HEAD SUCCESS REQUIRED |
| F10 final artifact | exact audited ZIP + SHA-256 + release manifest | BLOCKED UNTIL F9 SUCCESS |

## Non-negotiable safety acceptance

- STOP ALL and external safety lock remain authoritative.
- No human approval automatically grants Etsy publish, POD production, fulfillment dispatch, refund, tax/GST filing or money movement authority.
- UNKNOWN external outcomes require reconciliation before retry.
- Printify/Gelato production and live fulfillment remain disabled during certification.
- Recovery acceptance cannot be inferred from planning or historical evidence; any destructive staging restore requires its separately governed authorization.
- No row in this matrix may be interpreted as activation authorization.

## Final acceptance procedure

1. Audit the exact final v1.0.93 head and resolve the complete failure set, not only the first visible error.
2. Require every Engineering & Safety Audit stage to succeed, including WordPress/MariaDB integration and package-boundary verification.
3. Bind the certified artifact to the exact candidate commit, manifest and SHA-256.
4. Install only that exact artifact under the controlled deployment plan.
5. Verify version 1.0.93, schema 23, health/readiness, STOP ALL, external lock, automation posture and effective switches after installation.
6. Keep destructive recovery certification and every consequential external activation behind their independent authorization boundaries.
