# DigiForge P4 — Admin / Control Center Completion Scope

## Objective
Provide a central, read-only operational control surface over the already-implemented DigiForge backend modules without weakening fail-closed safety controls.

## Existing module surfaces
DigiForge already registers module-specific WordPress admin pages for Research, AI Governance, Product Factory, Production, Digital Factory, POD & Personalization, Listings, Orders, Finance and Integrations/Connections.

## P4 central-layer gaps
The existing root DigiForge page currently concentrates release readiness and automation switches but does not provide a complete operator-oriented navigation and status layer. P4 therefore adds:

1. A Control Center overview with release, schema, readiness and external-lock status.
2. A module navigation matrix linking operators to the existing module admin surfaces.
3. A dedicated System Status page sourced from the HealthMonitor snapshot.
4. A dedicated Readiness page sourced from the Readiness report.
5. A dedicated Safety & Controls page exposing STOP ALL, activation authorization, automation-armed state and every effective feature switch as read-only status.
6. Recovery evidence visibility, including database backup, plugin package, checksum, schema, restore instructions and STOP ALL confirmation.
7. Evidence hashes for readiness/recovery reports so operators can retain verifiable snapshots.

## Safety rules
- P4 admin pages are read-only.
- P4 does not add buttons that arm automation, authorize activation or disable STOP ALL.
- External feature switches remain unchanged.
- No Etsy, POD provider, AI, fulfillment, finance, tax or other external side effect is executed from these pages.
- Any future activation UI must be implemented separately with explicit authorization gates and independent validation.

## Deployment rule
P4 code may be merged only after CI passes. Live deployment remains separate from repository merge and must not occur while the production backup/recovery requirement is unresolved.
