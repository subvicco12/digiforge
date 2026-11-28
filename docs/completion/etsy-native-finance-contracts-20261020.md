# Etsy native Finance source contracts — Batch 10

Draft-only, offline parsing. No native import endpoint, persistence, category mapping, financial approval or provider transport is added.

## Source and reproducibility

The connected public-document tool successfully retrieved the full [official Etsy OpenAPI JSON](https://www.etsy.com/openapi/generated/oas/3.0.0.json) with `maxAge=0`, HTTP200 on 2026-10-20. The managed worker allowlist remains unchanged. Earlier assumptions that its restriction also made all connected public-document retrieval unavailable are superseded; no owner upload or new credentials are needed for this schema.

- OpenAPI format3.0.2; Etsy API version3.0.0.
- Retrieved UTF-8 full snapshot:865,861bytes, SHA-256 `9f0fe8cf1352a444b6069ec04699809bcde41227cf16764e76a98d13e7eb7797`; retained outside Git. This pins the retrieved text, not a signed publisher release or financial record.
- Exact eight-schema extraction: `includes/Finance/contracts/etsy-openapi-finance-3.0.0.json`, SHA-256 `82e24b1123b1c3d3853d2f2734fa59d30a9595a36a46be6994306311f24ee91c`. Every schema equals the corresponding full-snapshot component. The runtime checks this pin before parsing. Etsy API Developer Terms remain applicable; no commercial rights are inferred.
- The full [official payments tutorial](https://developers.etsy.com/documentation/tutorials/payments/) was also retrieved, HTTP200. It distinguishes ledger debits/credits from purchase payment details and notes a payment may appear only after fulfillment. Its sample requests are not copied: the tutorial includes NaN/example query values and examples that omit a shop path segment; the OpenAPI schemas govern this parser.

## Supported objects and semantic boundaries

| Native schema | Preserved/validated | Never inferred |
|---|---|---|
| Money | Signed int64 `amount`, exact positive integer `divisor`, unchanged uppercase three-letter currency code | Fixed100 divisor, float conversion, rounding, FX, currency authenticity |
| Payment | Exact payment/shop/receipt identities where supplied; original/posted/adjusted Money fields; native status, currencies and epoch timestamps | Gross excludes tax/shipping; `amount_fees` means all marketplace fees; native `settled` certifies actual settlement |
| PaymentAccountLedgerEntry | Entry/ledger/sequence/parent/reference identity; signed amount/balance/currency; created epoch aliases | Divisor for raw ledger integers; account balance equals revenue; entry and associated payment are separate income |
| PaymentAdjustment | Parent payment, native status/is_success, optional/nullable currency-specific amounts, created/updated aliases | `is_success` means final settlement; all amounts share payment/shop/buyer currency or one divisor |
| PaymentAdjustmentItem | Parent adjustment, item/transaction/bill-payment identity, exact signed original/shop integers and timestamps | Unspecified units, source completeness or allocation/category |
| Payments / PaymentAccountLedgerEntries | Bounded arrays, count, nested schema/identity/link validation | One page equals complete population, even when count equals returned rows |
| ShopRefund | Documented Money/time/reason/note/status fields | Stable transaction ID absent from schema, financial reversal identity or actual refund certification |

The upstream components have no required-property lists. The ingestion profile is deliberately stricter: stable IDs are required for payment/ledger/adjustment/item objects, Payment.shop_id must equal the supplied external shop, Money needs all three fields and a nonzero divisor, wrappers need count/results. Missing other optional fields stay absent; nullable values stay null. Defaults are never materialized. Currency syntax is checked, not a verified ISO registry/provider account. Unknown fields/types/schemas fail closed for this pinned version.

Bounds:64KiB raw JSON, depth32,100 items per array,4096bytes per string, native PHP signed64-bit integers. Decimal strings, floating/exponent numbers, overflowed integers and duplicate decoded JSON member names reject. The divisor minimum in upstream is zero; this supported profile rejects zero as uninterpretable rather than dividing or inventing units. Valid non-decimal divisors, negative values and zero amounts are retained exactly. Monetary net identities are checked with existing exact decimal string arithmetic only when gross/fees/net have matching currency and divisor; mixed-unit or missing fields are preserved without inventing conversions or zero values.

Timestamp minima follow the source schema; aliases must agree, update cannot precede creation and supplied native timestamps cannot be later than the capture observation. Original integer fields are retained. `observed_at` is local provenance, never charge occurrence.

Duplicate rows within one list or conflicting content for the same scoped native identity reject. Identical adjustment references attached to different ledger entries are retained; their identity keys deduplicate without summing money. Original, posted, adjusted, ledger and refund views are never automatically added or mapped to separate revenue.

## Existing Finance integration and authority

`EtsyFinancialSourceParser::parse` accepts a named native schema, raw JSON, declared capture metadata and an exact selected scope. Shop/environment/business/store/external-shop tuples must match. Source bytes must match the capture hash. Digital and Personalized POD are supported; deferred shops are not onboarded. Context matching is not proof of actual ownership. Existing signed binding, revocation and Finance permission checks remain required at any future import boundary.

Outputs are `SCHEMA_VALIDATED_UNVERIFIED_SOURCE` with native data, raw/canonical hashes, typed scope-bound identities and a deterministic observation key. Retries are content-addressed only; there is no durable native-import replay claim or database write. Changed source content retains its logical identity but changes observation hash; formatting-only changes retain canonical identity and record their distinct raw hash. These are not immutable persisted revisions until an authorized existing repository boundary stores them.

`DeclarationContract` continues to accept only its internal DECLARATION-v1 contract/source kind. It rejects the native observation envelope. No source-object-to-ledger mapping is invented: raw ledger units, fee population, corrections/finality, revenue-versus-tax/shipping and complete source relationships still require authentic evidence and reviewed reconciliation rules. Existing declarations, immutable revision/allocation/inverse behavior, portal permissions and period-completeness checks are unchanged. No parallel ownership or approval system is introduced.

Every output retains UNKNOWN authenticity, settlement and source completeness; ownership, period approval, external execution and persistence are false. `finance_declarations` is empty. STOP ALL, external lock, automation OFF and RELEASE HOLD are unchanged. Parser output cannot authorize provider calls, money movement, tax submissions or production actions.

## Validation and acceptance

Synthetic fixture: `tests/fixtures/finance/etsy-native-synthetic-v1.json` (fictional identities, no authentic transaction or ownership claim). Tests cover all eight schemas, raw hash tampering, duplicate/escaped members, duplicate/conflicting identities, adjustment parent links, exact int64/divisors, zero/negative/null/missing values, native net identities, malformed/future/stale aliases, scope mismatch, deferred shops, canonical retries and rejection by the existing declaration boundary.

Focused results and final exact-head hosted audit are recorded in the existing local-validation JSON and dedicated draft PR/issue1209 receipt. No skipped validation or advisory/security/package gate changes.

Non-Finance remains246/268 implementation candidates with22UNVERIFIED. All23 Finance-dependent criteria remain incomplete; eight source-related Finance rows gain implementation/test references only. No authentic acceptance promotion. Master500/FC01/listing plans and their external evidence blockers remain unchanged.

Next evidence: private original Etsy payment/ledger responses or exact exported files with account/shop context, occurrence/correction relationships and completeness limits; actual POD invoices/charges; reviewed financial mapping/precision/finality rules. CSV exports are different source contracts and are not accepted as OpenAPI objects. Existing aggregate OpenAI buckets remain non-settlement evidence. Actual financial imports, period approvals, provider operations, merges and deployment remain unauthorized.
