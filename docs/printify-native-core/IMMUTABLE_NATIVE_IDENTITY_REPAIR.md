# Native Printify identity isolation

PHP readonly arrays do not detach reference cells held by a caller. A referenced image ID changed `ShopBinding::ownedUploads` and its reviewed scope fingerprint after construction. The same pattern changed `ExternalOperationIntent` product IDs, pagination and owned receipt references. Deterministic unit tests reproduce each change before correction.

The DTOs now copy validated scalar values into fresh arrays. The fixed read grammar runs before JSON encoding, so rejected known, unknown and nested `JsonSerializable` values cannot execute serialization callbacks. Valid input key order, source-reference order, public object JSON order and the existing scope fingerprint are preserved. No operation, scope or pagination range is broadened.

Duplicate detection runs after strict string validation and compares exact string identities. Two distinct canonical SHA-256/image strings beginning with `0e` must not collapse to numeric zero under PHP's regular comparison. Literal red/green vectors verify both distinct identities remain accepted and exactly duplicated references remain denied.

`NativePortContract::hash` also recursively sorted caller-owned referenced maps in place. It now builds and sorts fresh result maps; literal canonical SHA-256 vectors remain unchanged while caller key order is preserved. Its existing bounded native JSON guard rejects objects/resources before encoding, preventing callback execution. No alternate hash profile, credential path or provider transport is introduced.

These repairs protect source and GET identity immutability. They do not grant provider execution, establish independent recovery roots or authorize installation. STOP ALL and the external lock remain ON, automation OFF and RELEASE HOLD active.
