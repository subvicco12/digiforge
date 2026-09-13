# DigiForge P7 — Integration Preparation

## Purpose
Prepare DigiForge integrations for later controlled activation without making external calls or enabling external side effects.

## Safety state during P7
P7 is preparation only. The following must remain true throughout this phase:

- STOP ALL is ON.
- Activation authorization is OFF.
- Automation is unarmed.
- Every external feature switch remains OFF.
- No live webhook, scheduled external worker, marketplace mutation, POD order, AI provider call, payment/banking action, accounting mutation, GST/tax filing, or tax-authority call is permitted.
- `external_actions_performed` must remain false.

A configured integration is not an activated integration.

## Integration inventory

### Etsy
Prepare:
- application/client identity fields;
- OAuth redirect URI documentation;
- minimum required scopes;
- credential storage mapping;
- connection-state model;
- token expiry/refresh handling;
- rate-limit and retry policy;
- idempotency requirements for future mutations;
- draft-versus-publish separation;
- explicit authorization gate before any OAuth completion or remote action.

P7 must not create, edit, publish, renew, deactivate, or delete an Etsy listing.

### Printify
Prepare:
- API credential field and encrypted-secret mapping;
- shop/provider identifiers;
- catalog/product mapping model;
- personalization payload mapping;
- request idempotency and retry policy;
- sandbox/mock validation where possible without remote execution;
- explicit authorization gate before live provider requests.

P7 must not create or submit a Printify order or mutate a live Printify product.

### Gelato
Prepare the same control model as Printify, including credential storage, identifiers, product mapping, personalization mapping, idempotency, retry policy, and explicit authorization gates.

P7 must not create or submit a Gelato order or mutate a live Gelato product.

### AI providers
Prepare:
- provider registry entries;
- encrypted API-key mapping;
- model/capability mapping;
- purpose-specific model policy;
- token/cost ceilings;
- timeout/retry/circuit-breaker behavior;
- prompt/input classification;
- output validation and audit metadata;
- explicit authorization gate before provider execution.

P7 must not send prompts, product data, customer data, or other payloads to an external AI provider.

### Future finance/accounting/payment/analytics integrations
Prepare only generic registry and governance requirements. No banking, payment, accounting, tax-authority, GST, or financial mutation is permitted in P7.

## Credential governance

All credentials must:
1. use the DigiForge credential-security architecture rather than source code or plaintext repository files;
2. be environment-scoped;
3. never appear in Git history, logs, generated packages, screenshots, test fixtures, or audit output;
4. support replacement/revocation;
5. expose only non-secret connection metadata to administrators;
6. fail closed when missing, invalid, expired, or inaccessible.

No real secret is required to complete the repository-level P7 preparation.

## Environment isolation

For each integration, document or enforce:
- development/test versus production identity;
- separate credentials where supported;
- separate provider/shop/account identifiers where supported;
- no automatic promotion of credentials between environments;
- production activation as a distinct authorization event.

## Authorization gates

External execution must require all applicable gates, including:
- STOP ALL not active;
- explicit activation authorization;
- automation armed when the operation requires automation;
- the specific feature switch enabled;
- valid integration/credential state;
- environment match;
- capability/role authorization;
- operation-specific validation;
- audit event creation.

P7 does not satisfy or bypass any of these gates.

## Scope minimization

Before future activation, every provider permission/scope must be reviewed against the exact DigiForge capability that needs it. Prefer least privilege and read-only access during validation where a provider supports it. Publishing, ordering, refund/cancellation, financial, and tax capabilities require separate explicit authorization.

## Webhooks and scheduled workers

P7 may define webhook schemas, signatures, replay protection, deduplication, and worker contracts in code or documentation. It must not register live provider webhooks or enable externally acting schedules.

Future webhook activation requires:
- signature verification;
- timestamp/replay protection;
- idempotency;
- environment validation;
- rate limiting;
- audit logging;
- STOP ALL behavior;
- explicit authorization.

## Validation checklist

Repository-level P7 passes when:
- integration inventory is documented;
- credential and environment requirements are documented;
- least-privilege scope review is defined;
- activation gates are explicit;
- webhook/worker safety requirements are explicit;
- no secret is committed;
- no external feature switch is enabled;
- no external action is performed.

Live connection validation is intentionally deferred until P1 recovery certification and explicit integration-specific authorization.

## Activation order remains unchanged

Future activation is separate and sequential:
1. Research
2. AI
3. Product Development
4. Printify/Gelato
5. Etsy Draft
6. Etsy Publishing
7. Orders
8. Fulfillment
9. Finance automation
10. Tax functionality

Each step requires its own authorization and validation. Passing P7 does not authorize any step in this sequence.
