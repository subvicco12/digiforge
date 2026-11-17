# Product-draft fingerprint input integrity

The draft contract previously encoded evidence with its own canonicalizer before the guarded MutationIntent constructor ran. A nested JsonSerializable value could execute during preparation or before reconciliation rejected a mismatched fingerprint. Its reference-based sorting could also reorder metadata owned by the caller.

ProductDraftContract now uses the existing MutationIntent::hash implementation, which rejects executable/non-JSON values before encoding and canonicalizes fresh maps. No credential, approval, persistence, transport or authorization mechanism is added. Valid bounded JSON retains the existing canonical hash bytes, including slash/Unicode escaping and zero fractions. Prepared data and normalized responses remain non-authorizing observations.

Three original regression tests failed against the previous implementation: two callback executions and one caller-map mutation. The focused suite additionally covers the public preparation service and independently calculated historical hash vectors. Standalone ProductDraftContract unit tests explicitly load the existing MutationIntent dependency; production uses the companion's established autoloader.

No provider call, write authority, source-pin promotion or safety-policy exception is involved.
