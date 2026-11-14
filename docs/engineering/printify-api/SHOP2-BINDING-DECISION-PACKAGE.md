# Shop2 binding decision package — .25

**No binding is approved or applied.** The normal operational validator remains unchanged and requires enabled integration. Its normal denial is deliberate: existing runtime and credential retrieval require an enabled CONFIGURED integration plus independently reviewed scope and global safety admission.

The old .24 proposal used that normal validator as though it described an acceptance-only workflow. That diagnostic conflation is corrected with a **separate inactive contract**, PrintifyAcceptanceDraftBinding. It nests the original normal proposal and retains the full normal validation result unchanged. Only when both proposed observation and evidence say integration_enabled=false is that single normal blocker classified as expected for the *draft identity review*. Unknown, missing, string, drifted or true enablement remains a draft blocker. No other blocker is discarded. Draft status is always POLICY_AMENDMENT_REQUIRED and all execution, write and enablement authority fields remain false.

An identity-validation result OFFLINE_VERIFIED means only that the supplied candidate facts are internally consistent; it is not provider identity proof, cryptographic review, adoption or execution permission. Existing capabilities do not accept this draft as a runtime binding. SHOP2-GET-ONLY-BINDING-PROPOSAL.json is the canonical separate draft. The earlier normal SHOP2-ACCEPTANCE-BINDING-PROPOSAL.json remains preserved for comparison, not applied.

|Field|Available evidence|Proposed value / gap|Decision|
|---|---|---|---|
|Internal shop|Owner-specified canonical Shop2|2|Known intended identity|
|Shop key|Owner-specified canonical scope|personalized_pod|Known intended identity|
|Provider|Printify-only mission|printify|Known intended provider|
|Integration|Work report metadata|3, CONFIGURED, disabled|Reported, not re-inspected in production|
|Environment|Work report|production|Reported; current binding still must be verified|
|Provider shop / channel|Dashboard inspection|28930593 / Etsy|Dashboard-only, not token authentication|
|Excluded provider shop|Separate dashboard shop|28925875 / Pop-Up|Excluded; no substitution|
|Shop3|Deferred scope|No mapping|Remains deferred|
|Account identity / account reference|Dashboard access only|UNKNOWN/null|PROVIDER_IDENTITY_UNVERIFIED|
|Credential reference / account association|Encrypted material exists; two account-UI credentials visible|UNKNOWN/null; no fingerprint invented|PROVIDER_IDENTITY_UNVERIFIED|
|Business/store/program catalog scope|No exact approved registry tuple supplied|UNKNOWN/null; do not use internal shop2 as registry IDs|PROVIDER_IDENTITY_UNVERIFIED|
|API scopes, expiry and effective permissions|No authenticated Printify GET|UNKNOWN|PROVIDER_IDENTITY_UNVERIFIED|
|Approval hash/revision and revalidation timestamps|No provider evidence rows in supplied report|UNKNOWN/null|OWNER_APPROVAL_REQUIRED|
|Runtime enablement|Disabled by supplied evidence and owner restriction|Remains false|Normal runtime denied; draft cannot change it|

Source evidence: Work acceptance report1q02iJtQ2Ivf2cqb7Dt0pqn-Oc4jsNzms and sanitized JSON1Th9UuGTehvYJS6AbN5CUiK1nUAMTpVQJ, not live reinspection. Dashboard account identity must not be equated with the encrypted credential's identity. A ciphertext/label is insufficient evidence. Missing account/credential linkage requires independently established custody metadata/approval; do not decode or reveal a token, guess an account ID, or invent a provider endpoint. If independent linkage cannot be established without an API probe, a separate explicit bootstrap-identity policy decision is required before any such probe. This draft does not grant one.

## Non-executing configuration procedure and decisions

1. Owner decides the standalone safety-amendment wording separately. No grant or implementation is implied by document approval.
2. Under separate read-only inspection approval, establish exact non-secret credential/account/shop linkage, environment and registry ancestry. Keep raw credentials and private metadata in current custody. Record provenance and UNKNOWN gaps.
3. Independently review the draft tuple, duplicate/cross-shop conflicts and evidence freshness/revision. Normal enabled-integration validation remains denied while disabled; it must not be forced green by enabling integration3.
4. If the amendment is adopted, separately review an acceptance-only implementation capable of scoped disabled-credential admission and emergency revocation while all business gates remain denied. No such runtime path exists here.
5. Obtain separate installation/configuration approval for that exact implementation and artifact, then use the approved existing audited ownership workflow only where its contracts actually apply. Do not insert a draft directly into provider_connections or backfill approval rows.
6. Independently verify connector site/actor/session compatibility. Finally obtain separate bounded live GET authorization, after every other gate passes. No normal activation or Shop3 assignment follows.

Approving this binding decision package is neither a configuration write approval nor API execution consent. Current gates: POLICY_AMENDMENT_REQUIRED, OWNER_APPROVAL_REQUIRED, PROVIDER_IDENTITY_UNVERIFIED, CONNECTOR_UNVERIFIED, INSTALLATION_NOT_AUTHORIZED, LIVE_GET_NOT_AUTHORIZED. Preserve RELEASE HOLD.
