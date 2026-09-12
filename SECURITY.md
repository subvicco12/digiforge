# DigiForge Security Policy

## Reporting

Report suspected vulnerabilities privately to the repository owner. Do not open a public issue containing credentials, customer data, exploit details, or access tokens.

## Supported baseline

Security fixes target the current `main` branch and the latest packaged plugin artifact.

## Mandatory controls

- Never commit or log plaintext credentials.
- Keep sandbox and production credentials isolated.
- Keep automation unarmed and STOP ALL active until an explicit production activation review.
- Require capability checks, validation, audit events, and idempotency for state-changing integrations.
- Treat generated files, webhooks, provider payloads, and AI output as untrusted input.
- Do not bypass the human publication gate.
