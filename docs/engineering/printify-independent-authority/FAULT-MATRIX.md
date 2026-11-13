# Offline fault and rollback evidence

These are deterministic state-machine tests, not infrastructure fault injection.
`tests/unit/PrintifyIndependentAuthorityTest.php` exercises the portable contract
with ephemeral synthetic signing keys and a copy-on-write fake atomic store.
There are no live requests, real provider credentials or production records.

| Scenario | Behavioral test / expected result |
|---|---|
| Hostinger DB/FS/both/application rollback | `testHostingerDatabaseFilesystemCoordinatedAndApplicationRollbackCannotRewindExternalAuthority`: restored caller values cannot reset separate fake service generation; stale grant denied |
| Authority outage | `testAuthorityOutageNeverReachesProvider`: no dispatch; stop durable flag false |
| Sender outage / timeout | `testDispatchServiceOutageIsUnknownAndNeverRetried`, `testProviderTimeoutIsUnknownConsumedAndNotRetried`: one attempt, UNKNOWN, consumed slot |
| Stop before final admission | `testEmergencyBeforeFinalAdmissionPreventsPhysicalSend`: no physical fake call, drain acknowledged |
| Stop after admission / in-flight response | `testEmergencyDuringAdmittedGetIsPendingAndResponseUnknown`: pending drain, no values, UNKNOWN, later fresh stop acknowledges drain |
| Lost reservation commit ACK | `testRequestCommitAcknowledgementLossNeverSendsOrReplays`: no send, persisted slot cannot replay |
| Lost post-send state access | `testPostDispatchStoreOutageCannotProduceAcceptedReceiptOrDrainAck`: UNKNOWN, admitted orphan retained, no drain ACK after recovery |
| Expired, future, overlong authority | `testExpiredFutureAndOverlongOwnerAuthorityDenied`: denied before send |
| Tampered signature / stale generation | `testUnsignedOrTamperedAuthorityDenied`, rollback test: denied |
| Wrong actor/account/shop/credential/integration | `testWrongActorShopAccountAndIntegrationDeniedEvenWithValidSignature`: valid signature insufficient |
| Unavailable custody / unqualified external boundary | `testCredentialUnavailableNeverReachesProvider`, `testMissingIndependentCustodyQualificationKeepsServiceDisabled`: denied |
| Duplicate nonce/session slot | `testDuplicateNonceAndSlotNeverDispatchTwice`: only one call |
| Shared quota / provider throttle | `testAccountRateBudgetSharedAcrossSlots`, `testProviderRetryAfterDeniesNewReadWithoutRetry`: third allowed, fourth denied; 429 pauses future admissions |
| Mutations / proxy inputs | `testMutationsAndArbitraryProxyInputsDenied`: methods other than GET, arbitrary paths/hosts/envelope fields denied |
| Body/HTTP boundary | `testMalformedOversizedAndRedirectResponsesAreNeverAccepted`: no OBSERVED on invalid JSON, oversized body or redirects |
| Stop signature domain / replay | `testStopSignatureDomainAndReplayProtection`: GET signature cannot stop, stop replay cannot claim durable success |
| Recovery epoch mismatch | `testAuthorityRestoreRequiresNewIndependentEpoch`: denied; does not simulate privileged rollback of BOTH witness and state |
| Corrupt claims / rate state | `testMalformedPersistedClaimsCannotAcknowledgeDrain`, `testMalformedQuotaEvidenceCannotRestoreBudget`: deny rather than infer drained or replenish quota |
| Signed audit tampering | `testAuditTamperingDeniesFurtherDispatchAndAcknowledgement`: bad chain denies |
| Session revocation / malformed expiry | `testRevokedSessionCannotSend`, `testMalformedSessionExpiryDenied`: deny |
| Catalog authority | `testCatalogGrantCheckedAfterApprovedSlotAndCannotExpandScope`: valid approved slot still needs correct independent grant, with type-safe expiry, identity and one-use slots |
| Signer failure / secret-safe receipts | `testReceiptSignerFailurePreventsDispatch`, `testValidSignedGetIsMinimizedAndAudited`: no send before signed reservation; only type observations returned |

## Qualification deliberately not claimed

- Actual cross-worker atomicity, process crash persistence or host-failure durability.
- A failed emergency write becoming permanently recorded after storage recovery.
  An unacknowledged stop is UNKNOWN, never successful revocation. A future service
  must qualify its outage/recovery fail-stop and independent epoch invalidation;
  restoration of an old authority snapshot must not reactivate sessions.
- Provider-side revocation of previously usable Hostinger tokens, or mandatory
  all-worker egress enforcement. The fake legacy caller cannot demonstrate either.
- Simultaneous privileged rollback of external code, store, issuer keys and witness.
- Real issuer authentication, credential custody, transport TLS/redirect behavior,
  durable external audit anchoring, HTTP listener and connector integration.
- Any Printify API response, account association, scope or live operation.

Real-adapter admission requires multi-process stop/admission contention tests,
crash-before/after-commit tests, lost-ACK tests, partition/recovery tests, independent
witness restoration tests, and evidence that Hostinger cannot send or obtain a
usable token. No backend is approved or provisioned; therefore these are release
blockers and Option D remains the operational decision.
