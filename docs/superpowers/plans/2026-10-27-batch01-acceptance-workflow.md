# Batch 01: website acceptance review workflow

Execution is inline on the user-specified cumulative branch. The sprint request
authorizes engineering without a planning-only stop. Baseline: 9c8d3a71, clean.

The existing ReviewedAcceptanceContract implements typed proposal, independent
decision, expiry, current authorization and immutable review evidence. The portal
only exposes GET status. Extend that surface using the existing lifecycle rather
than creating a second review store. Keep Finance excluded and every certification
and external execution flag false.

- [x] Add executable regressions for authenticated acceptance proposal/decision,
  invalid shape, foreign shop, nonce, independent review, revision/hash and replay.
- [x] Add bounded POST routes to the existing reviewed-contract controller.
- [x] Add an acceptance editor with explicit confirmation and exact proposal
  inspection before a separate reviewer chooses approve/reject/revoke.
- [x] Verify browser request construction, stale response handling and safe text
  output. Run available PHP/native checks and report environment limitations.
- [x] Record the precise implemented boundary without promoting acceptance counts.

Ruling: add complete pending-proposal inspection with settings permission, REST
nonce and private no-store responses. Status metadata alone cannot support a
meaningful independent decision. Native route-dispatch tests are added but require
hosted WordPress execution; they are not reported as passing locally.

Ruling: repair selected-shop landing navigation in the same batch. Research,
Orders and Fulfillment were missing; Automation checked a capability different
from its destination; product reviewers could not discover Approvals. A pure
strict-true authorizer projection now covers all four shops without adding
authority or exposing global/Finance views. Navigation regressions pass 3/81.

Production, provider calls, protected custody, merge and deployment remain outside
this batch. Existing OWNER, Google, Etsy, Printify and Master500 paths are retained.
