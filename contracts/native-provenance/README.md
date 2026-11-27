# Native provenance input contract v1

This offline component authenticates a bounded canonical Etsy provenance snapshot.
It returns a held diagnostic with a SHA-256 commitment to the signed snapshot.
There is no runtime registration or native write callback.

The trusted composition root supplies the producer identifier, producer source
SHA, Ed25519 SPKI SHA-256 pin, canonical business ID and external Etsy shop ID.
No operational producer or key is enrolled here. Sign the UTF-8 bytes of
`DF_ETSY_NATIVE_PROVENANCE_1`, a newline, and the exact JSON object serialized in
the field order in bridge.mjs. Field values are scalar. The signed validity
window is at most 60 seconds. The caller supplies trusted current Unix seconds.
A signature establishes the configured producer statement. Etsy buyer identity,
revocation and latest-snapshot status need separate evidence.

## Lane B handoff

Use the frozen native reference at
`50fc20ccb7ca33b4b1267a5cd00f65f1fb75e0c3`. Treat this output only as a
diagnostic. The native writer must check the current authenticated source
revision and revocation under a fence spanning registration or advance and
native CAS. The existing register/advance methods own their transactions and
accept only an opaque core_reference where the provenance would need to bind.
An outer preflight cannot make two independent transactions atomic.

Before accepting DF_NATIVE_PROVENANCE_RECEIPT_1, require native-owned evidence
binding the canonical snapshot hash to the committed operation ID, allocation
ID and revision, product/order identities and versions, and checkpoint
generation/epoch. Use the existing issuer/witness validation. A caller-provided
core_reference or independently verified receipt with matching loose identifiers
cannot establish that join. This version returns receipt:null and does not
advertise receipt support. The existing authority retains ownership of
allocation, cancellation, custody, replay, recovery, and UNKNOWN. Do not retry
after uncertain dispatch or commit.

A native writer integration change needs an explicit ownership transfer or an
approved existing atomic integration hook. Separately assign the Etsy buyer
verifier. Until both dependencies land, keep Lane B consumption and native
advance held. No production activation is authorized.
