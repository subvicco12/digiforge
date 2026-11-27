# Synthetic checkpoint final-sender integration plan

Owner execution directive: implement offline now against the existing Cloudflare development target. No deployment, credentials, provider requests, operational epoch, core/companion edits or safety-policy change. Existing architecture decision and native checkpoint specification govern this task.

Goal: connect the existing observer to the existing synthetic authority/final sender, preserving one authoritative reservation/journal/dispatch implementation.

Architecture: add a disabled-by-default SyntheticCheckpointFinalSender and private checkpoint gate. A distinct CHECKPOINT_EXECUTE RPC carries the existing signed GET authority and a checkpoint request. The guarded service rejects ordinary EXECUTE, verifies the observer result and exact prepared intent, and atomically records stable intent/proof tombstones in the existing AuthorityJournal before existing OfflineRuntime reservation/admission. It verifies the existing witness/state journal and current generation again at physical dispatch. No new database/table, provider transport, mutation host or production factory.

Files: new contracts/cloudflare-dispatcher/checkpoint-final-sender.mjs; minimal optional-guard integration in authority-service.mjs; focused tests and synthetic-only fixture extensions. Add versioned contract and concise qualification/core handoff. Do not alter frozen Stage A runtime modules or any PHP/runtime/workflow file.

1. RED/GREEN: test absent final-sender interface, default denial, positive observer-to-real-synthetic-authority path and durable intent/proof markers. Bind exact signed payload, native operation fingerprint, approval/session/account/shop and deployment identity. Run full dispatcher suite.
2. RED/GREEN adversarial integration: duplicate/concurrent reservations, proof replacement, stop after witness signing and at custody callback, witness/storage outage, restart, lost durable acknowledgement, expiry, conflicting generation and journal corruption. Preserve UNKNOWN, spent intents, no retries and the three counterexamples.
3. Qualification: complete Node, PHP, WordPress, offline, static/security and source-preservation checks; fresh read-only review; exact-head CI on PR1260 only; reproducible source/evidence bundle and canonical Drive readback. Hosted durability, account Free entitlement, independent custody/administration remain unverified.

Review focus: asynchronous final-check gaps; ordinary EXECUTE fallback; checkpoint substituted across signed operations; lost-ack intent consumption; observations incorrectly promoted to native REGISTER/RESERVE. Readiness remains synthetic-only and release-held.
