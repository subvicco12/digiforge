# Durable emergency fence and acceptance dispatch — offline qualification

Baseline: c6ad45ddc2f53b94fbc4ef8d5645963d909b5a71, existing draft PR1260. Revision1 approval is engineering-only. The approved amendment bytes remain unchanged (SHA256 ffbbfe63ecc4223d3192c7fc01a8a33e872cfb51f78a896696f2537465526c9e). No production configuration, installation, provider credential use, binding, activation or live GET is authorized.

## Implemented protocol

1. A preprovisioned private filesystem journal records one bounded random emergency event and fsyncs it **before any database transaction check or generation write**. The sealed, uniquely audit-anchored database generation binds the complete journal digest. Rolling back, restoring or rejecting the database generation alone cannot restore the old journal digest.
2. A shared, inode-pinned dispatch lease spans the existing upstream credential and transport pipeline. Current generation is checked within admission, immediately before the physical sender and after the governed work. All existing owner/session/native/module, exact-provider, grants, quota and transport checks remain in force.
3. Emergency intent is appended without waiting for readers. It immediately prevents old-generation admissions. A successful drain acknowledgement additionally requires an exclusive lease, so it cannot race an admitted reader. If readers remain, return **FENCED_DRAIN_PENDING**, with durable revocation recorded but no drain acknowledgement. Existing work is classified UNKNOWN; no retry, restored slot or automatically renewed authority follows.
4. The admission linearization point is the successful generation check under the shared lease. Work admitted before emergency intent can be in flight even if the physical send follows that intent; it cannot outlive an acknowledged drain. This is not cancellation of a supplier request already admitted. Old response evidence is discarded on revocation or uncertain post-dispatch checks, even if a subsequent database read recovers.
5. If the journal rejects an event, a separate **local autocommit** connection writes an insert-only quarantine latch in existing InnoDB wp_options. It does not commit caller work. The latch is checked without cache and has no runtime clear API. Recovering file permissions, rolling back the caller or invoking terminate again cannot clear it. This is a deny-only safety channel, not another provider transport or credential store.
6. The quarantine path uses existing native WordPress DB configuration internally, never Printify credentials. Only localhost/127.0.0.1/::1, existing host parsing, default client flags, InnoDB and flush-at-commit1 qualify. Connect/read waits and InnoDB/metadata lock waits are explicitly bounded to1s per operation. Remote/unknown TLS configurations are refused rather than silently downgraded. No secrets, DB exception text, private paths or raw responses are returned.

## Native emergency integration and preservation

Settings.php contains **one notification line before its original STOP ALL persistence**, including repeated true writes and attempts whose SQL later fails. A deny-only observer is registered only when the trusted host explicitly configures `DIGIFORGE_PRINTIFY_ACCEPTANCE_EMERGENCY_EVENTS=true`. Default is undefined/OFF. A configured observer writes minimized `printify_acceptance_emergency_observed` audit evidence.

The earlier transaction/return-value-changing Settings hook is NOT restored. Native set/protectProduction return semantics, transactions, capability checks and normal lock behavior are retained. The exact single-line overlay is recorded in `../printify-acceptance-stop-event-overlay.json`; preservation tests reverse only that unique hunk and check the original unchanged dependency pin. No blanket allowlist or original manifest change.

An ordinary control response confirming stop_all=true does **not** certify a drained provider boundary. Inspect the separate emergency receipt: DRAIN_ACKNOWLEDGED, FENCED_DRAIN_PENDING, FENCED_DATABASE_UNAVAILABLE, QUARANTINED or HOST_FAIL_STOP_REQUIRED. No caller may treat a setting flag, listener registration or eligible proposal as execution permission.

## Fault decision matrix

|Observed condition|Result and qualification|
|---|---|
|Caller transaction rolls back after emergency|Journal persists; old generation remains denied. OFFLINE_VERIFIED.|
|Generation SQL write fails or old DB generation is restored alone|Journal/DB mismatch; no old authority revival. OFFLINE_VERIFIED.|
|Generation audit fails|No valid anchored generation; old digest invalidated. OFFLINE_VERIFIED.|
|Fence is unavailable but independent local quarantine write succeeds|QUARANTINED, durable denial survives caller rollback/file recovery; no clear/rearm API. OFFLINE_VERIFIED.|
|Emergency arrives during admitted work|Intent recorded; exclusive drain denied/pending; response UNKNOWN, no replay. OFFLINE_VERIFIED.|
|Gate replacement or malformed/missing private files|Deny; inode identity is checked independently of the lock handle. No automatic reprovision. OFFLINE_VERIFIED.|
|Journal reaches capacity|Append poisons bounded journal instead of silently preserving a usable old epoch. Denial until separate host recovery. OFFLINE_VERIFIED.|
|Both journal and quarantine persistence are unavailable before any evidence is stored|HOST_FAIL_STOP_REQUIRED, durable_revocation_recorded=false. **Permanent revocation cannot be claimed.** A regression explicitly demonstrates the surviving old generation after both resources recover; existing parent policy still denies GET. Full exception BLOCKED.|
|Host storage/OS failure, privileged replay of both stores, cross-host/NFS flock or power-loss durability|Not established by isolated tests. HOST QUALIFICATION REQUIRED; no unconditional guarantees.|

## Required private host prerequisites — NOT provisioning instructions

No production path or files are created by this implementation. A separately authorized trusted host must provide a canonical dedicated directory outside **all** web roots/aliases, owner equal to the PHP effective UID, mode0700, no symlinks. PHP POSIX identity, fsync and reliable local append/flock semantics must be qualified. Network/shared-filesystem semantics are not assumed.

Required existing regular, single-link mode0600 files (escaped newline notation below denotes actual LF bytes):

- `events`: bytes `DFPF1\n`, one fresh64-hex private-epoch identifier and newline. At most512 complete event lines /33286 bytes; no runtime truncate, rename, initial-epoch creation or rollover helper.
- `dispatch.lock`: exact bytes `DF_DISPATCH_GATE_V1\n`.
- `dispatch.identity`: `DFG1:` plus SHA256 of the lock file's decimal `device:inode` identity plus newline. This binds the original inode; replacing the gate cannot acknowledge older leases. Do not rewrite identity during a live epoch.

`DIGIFORGE_PRINTIFY_ACCEPTANCE_FENCE_DIR` names that existing private directory. The separate emergency-events flag only installs revocation observation; neither constant enables provider reads. Absence, unknown permissions, malformed data, unsafe public location or unsupported host state denies acceptance. Test fixtures provision disposable directories only; they are excluded from installation packages.

Quarantine recovery is a separate audited host decision after all old authority expires/revokes and admitted work drains. It requires a fresh private epoch, revalidated installation/identity evidence and renewed independent approvals. No current source function clears the latch or repairs journal/gate files. This mission performs no recovery drill or production maintenance.

## Qualification boundaries and remaining gates

The known **database rollback/write-failure resurrection defect** is closed under the tested independent-journal fault model. Shared/exclusive lease tests establish acknowledgement ordering, including separate PHP processes. They do not prove hardware durability or provide an out-of-band supervisor.

If both persistence channels cannot store the emergency, software cannot manufacture durable evidence of that event. A separately reviewed host-level fail-stop mechanism, with explicit rearm after recovery, is mandatory before adopting a live exception. Physical fsync latency and host failure behavior require external qualification; the1s SQL limits are not a claim of an unconditional total emergency deadline. The source returns HOST_FAIL_STOP_REQUIRED rather than claiming success.

The ordinary bridge still denies STOP ALL/external lock; normal integration enablement, vault, owner/delegate authorization and provider mutations are unchanged. No locked-state credential path or acceptance policy grant is activated. Full operational exception remains BLOCKED_AUTHORITY / BLOCKED_HOST_QUALIFICATION, with installation and live GET NOT AUTHORIZED. No installation-ready ZIP is issued at this checkpoint. Existing qualified .25 ZIP is unchanged; source CI packaging is not a replacement artifact or hosted exact-ZIP certification.

No migrations, production changes, owner enrollment, shop-binding writes, provider requests or reservation22 retry. STOP ALL ON; external lock ON; automation OFF; activation unauthorized; RELEASE HOLD.
