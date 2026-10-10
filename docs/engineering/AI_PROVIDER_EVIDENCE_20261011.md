# AI provider evidence — finance-independent milestone

This change binds provider response identity and token metering to the original shop-scoped generation reservation. It records immutable completion evidence before returning generated payloads. Background responses retain immediate completion metering and reject mismatched provider response IDs. Equivalent POST/GET metering replays despite transport serialization differences; each raw response hash is preserved append-only.

Token counts are not financial charges. An authorized `manage_digiforge_ai` human may import a provider-statement charge through the internal repository boundary only, with statement SHA-256, exact invoice/line identity, response/model identity, and reservation currency. Immutable proof precedes the atomic cost projection; uncertain persistence requires reconciliation without automatic retry. Identical completed imports replay; conflicting evidence, reused invoice lines, cross-shop identities and currency changes fail closed. No exchange rates or charges are inferred.

This does not independently verify a provider statement, expose a charge-import endpoint, activate a provider, or authorize any external execution. Failed or invalid-output provider responses still need complete metering coverage. Product/order attribution and monetary-budget reservation upper bounds remain software gaps. Monetary-budget generation remains blocked. Finance PR #1147 and its files are unchanged.

Validation: unit 1,152 tests / 7,340 assertions; WordPress 214 / 1,923, including 12 focused provider-evidence/background tests / 68 assertions; legacy checks, PHPStan and PHPCS pass. HTTP regression tests use intercepted fixtures, not external provider calls. WordPress fault-injection database errors are expected; baseline PHP deprecations remain.

Depends on PR #1152 atomic shop quantity reservation. Preserve STOP ALL ON, external safety lock ON and automation OFF. No deployment, catalog promotion or external Etsy/POD execution. This is a bounded software milestone, not final-product acceptance.
