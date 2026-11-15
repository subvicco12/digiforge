# Offline printed-date policy v1

Owner-authorized offline preparation only. `digiforge-en-month-day-year-v1` maps
strict Gregorian ISO `YYYY-MM-DD` (years0001–9999) to fixed English month, unpadded
day and original four-digit year, e.g. `November 15, 2026`. No locale, timezone,
trim, case conversion, fallback, truncation or shrinking. Invalid input or unknown
policy/product/profile is rejected. Native caps remain13/13/18 mug,16/16/24 poster;
canonical date schema max10 does not change native Date text caps.

`OfflineDateDisplayProof::render(product, specification, artwork, canonical, policy)`
validates canonical names/date with existing contracts. Separate display values go
through existing local rendering; generated date punctuation does not widen allowed
customer names. Existing glyph/layout HOLDs persist. Receipt binds original canonical
values/hash, fixed-art/spec/proof hashes, display values/hash and full versioned
policy/hash. No original value is overwritten. Existing stored proofs are untouched.

Operator: `php bin/offline-date-display-proof.php < request.json > receipt.json`.
Request has exactly `product`, `specification`, `artwork_svg`, `canonical_inputs`,
`policy`. Input cap128KiB. Product is FL-03 or FL-04; specification must be an exact
validated matching design specification and text regions must map first_name,
second_name,date. Canonical input example:
`{"first_name":"Élodie","second_name":"José","date":"2026-11-15"}`.
Use real pinned artwork bytes; synthetic research fixtures are engineering evidence,
not commercial source approvals. Output includes SVG under proof.svg and canonical
inputs; protect customer-containing local files. Exit0 means MANUAL_REVIEW diagnostic,
3 means MANUAL_HOLD,2 means rejection. None means production approval.

This CLI is not registered in WordPress and makes no writes or provider requests.
No portal behavior, storage, native mappings or final-artwork package is changed.
Future consumers must explicitly select/pin this policy and current canonical input,
art/schema/proof versions; new dependent review/selection/package/listing approvals
are required. Receipt hashes are evidence identities, not signed approval authority.

Output SVG is generic sans-serif local proof, NOT production artwork. Same request
produces identical local SVG/receipt; exported receipt readback can verify those exact
bytes. No native Printify font/weight, physical sizes, supplier geometry or final print
parity follows. Exact bundled OpenSans advance/date-cap checks are separate diagnostics.
All nine owner art/rights/media criteria and native/date-floor/final-output gates remain
unsigned. Legacy saved products and original R5B/R4 assets remain unchanged.

STOP ALL ON; external lock ON; automation OFF; manual approval; RELEASE HOLD.
