# Final bounded offline engineering verification

Branch: `integration/full-product-certified-20261031`. Starting audited runtime: `65f95d9d2723e6e268dd6420625dae97ab65fb5b`, Engineering & Safety Audit [4838 PASS,39/39](https://github.com/subvicco12/digiforge/actions/runs/41233507865). This batch adds tests and sanitized evidence only. Runtime, schemas, dependencies, workflows, approval gates and deployed package inputs are unchanged. No material application defect was reproduced and no runtime correction was justified.

## Customer ZIP publication

New `CustomerZipPublicationSafetyTest` uses two separate native PHP processes and the actual WordPress test bootstrap with installation disabled in children. Both publishers finish real ZIP readback before a fixture-only namespaced hard-link barrier controls the publication interleaving. Production APIs are unchanged; ordinary cases forward to the real OS hard link.

- Identical publishers converge on the exact same members/hash/size and preserve the winner inode; replay succeeds.
- Conflicting publishers observe the same absent destination, then the loser receives409 without replacing the winner bytes.
- SIGKILL before publication leaves an unaccepted partial temporary archive; retry creates a complete verified destination and does not adopt the orphan.
- SIGKILL after a successful link but before a response leaves a valid retained artifact; retry verifies and adopts it without replacement.
- A partial final destination returns conflict and remains unchanged, never accepted as a valid package.
- Disabled global `link` and injected link failure return503 without publication; failed attempt cleans only its own temporary file. Existing valid retained archives remain readable when link is disabled.
- Ordered member names, exact source hashes, deterministic replay and absence of accepted partial output are asserted. Fixture cleanup is limited to the isolated test's own directories; no production orphan-cleanup/retention policy is introduced.

Focused ZIP selection including existing integrity tests: **11 tests /151 assertions PASS**. New ZIP publication cases:6. No actual Wedding Guide files, customer-package approval or production filesystem qualification is inferred.

## Full PET worker

New `PetFullWorkerQualificationTest` uses the existing workflow, preparation, supplied-matte compositor and retained-final preview. It exercises1,048,576pixels with existing16,777,216byte bounds, preserving exact original source bytes/hash, actual expected final pixels, canonical immutable replay and preview identity. Deadline failures at review and render leave the previous journal unchanged and retryable; output-bound failure creates no result. Cancellation and assignment revocation deny rendering/preview; revoked access cannot read source artifacts.

Independent lane: **4 tests /94 assertions PASS**. Root integrated new ZIP+PET selection: **10 tests /209 assertions PASS**,35.985seconds. Root PHP8.4.26 measurement:

| Observation | Measured result |
| --- | --- |
| Prepare API | 2.110060810s |
| Review API | 4.571996802s |
| Render API | 3.612372807s |
| Preview API | 3.448150433s |
| Complete exercised scenario | 30.894023366s |
| Allocator baseline | 73,924,608bytes |
| Allocator peak | 92,876,800bytes /88.57MiB |
| Process RSS high-water mark | 116,326,400bytes /110.94MiB |

Each measured operation completed under its explicit10second job-operation limit. The whole scenario includes fixture construction, source/preview validation and immutable replays; it excludes WordPress bootstrap. VmHWM includes earlier process/bootstrap allocations and is not a per-stage delta. Fixture is a low-entropy white square with constant128 grayscale matte, not an animal or representative host workload. Injected deadline tests delay one scoped journal SELECT1.1seconds; they verify workflow refusal/unchanged state, not OS CPU preemption.

**Proposed whole-worker64MiB and end-to-end10second budgets are not met or certified.** No budget was raised or silently installed. Existing standalone compositor benchmarks are reused, not repeated. Host/runtime budget review remains necessary before operational qualification.

Evidence: `evidence/pet-worker-qualification.json` records the isolated lane; `evidence/final-offline-integration.json` records the integrated selection and source identities. Measurements vary between runs; both scopes remain explicit.

## Integration, certification and remaining gates

Root ZIP test commit `7d2021d`; PET source branch `lane/pilot48-pet-worker-20261031` commit `3c63d222b99141dbd8f6bcbb19c9578ebb11b74f`, integrated with original-commit provenance as `adc9139`. Both branches are preserved. Final normally pushed test/evidence SHA is recorded in issue1209 and the execution response; no new PR is needed.

Syntax and diff checks pass. The existing `.gitattributes` excludes tests/docs from the plugin package. Runtime/dependency/CI diff against65f95d is empty. Accordingly no redundant full suite or hosted audit is triggered for this test/evidence-only batch. Audit4838 still certifies **exactly65f95d**, not a later test/evidence commit. The new focused test results supplement that unchanged-runtime evidence; the final head is not claimed separately hosted-audited.

Initial test prerequisite failure was the stopped disposable MariaDB daemon; root started the existing local onboarding service. No production service was accessed. The PET test's initial strict array-order assertion was corrected to the established canonical JSON comparison; runtime behavior was not changed. All final bounded checks pass. Historical failures remain in private logs.

No independently reproducible application defect remains from these two tested scopes. This is not proof of universal Blueprint correctness. Remaining engineering qualification: reviewed full-worker resource/concurrency budgets and representative workload evidence. External gates remain exact Wedding Guide source/customer files and genuine buyer/content/rights review; genuine licensed PET photo/matte/reference and independent artistic review; exact supplier geometry; separately authorized installed-site/provider acceptance and release. No45-criterion reclassification is repeated; all45 outstanding statuses,500 catalog identities and20 review waves are unchanged.

Next action: retain the verified offline runtime and new tests; resolve the worker budget/host review and authentic product-review inputs before operational qualification. No speculative new runtime batch or duplicate CI is justified by this result.

STOP ALL ON; external lock ON; automation OFF; RELEASE HOLD. No production/staging writes, live imports, Finance activation, provider operations, orders, publishing, main merge, deployment or activation. Bounded offline technical verification:PASS. Operational/commercial release:NO-GO.
