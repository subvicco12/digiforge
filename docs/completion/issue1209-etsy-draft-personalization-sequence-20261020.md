# Issue #1209 — Etsy physical draft and personalization sequence

Base: draft #1212 at `312de9ec4b2cc2da288142cef04536273f836f32`. Scope: offline planning only; no Etsy request or listing is created.

## Demonstrated mismatch

The inherited multi-product planner placed `personalization_questions` and an internal support marker directly in the `createDraftListing` body, while omitting `shipping_profile_id` and `readiness_state_id`. Etsy's official [listing tutorial](https://developers.etsy.com/documentation/tutorials/listings/) calls for those physical-listing identities. Its [personalization migration guide](https://developers.etsy.com/documentation/tutorials/personalization-migration/) says to create the draft first, then use `updateListingPersonalization` on that listing ID; the dedicated POST replaces all current questions. No provider call was used to discover this.

## Bounded correction

Each separate physical draft plan now requires positive, caller-supplied shipping-profile and readiness-state IDs. It carries only draft fields in `CREATE_DRAFT`. A distinct, non-executable `UPDATE_PERSONALIZATION` plan contains exactly the normalized two FC01 questions, with an endpoint template that cannot resolve until a returned listing ID exists. The internal support marker is not sent. Both plans explicitly deny network execution; no runtime transport, persistence, order or publishing path is added. Existing hashes cover the changed metadata and dependent plan. Actual ID validity, Etsy app/shop association, remote field behavior and a future dispatch sequence remain UNKNOWN.

Synthetic unit and WordPress journey fixtures use fictional positive IDs and assert the separation, required/optional 48-character questions, no legacy scalar fields, and no HTTP. The authoritative ledger adds a scoped `BP-etsy-api-v3` reference without changing its status or any of the 246/268 and 22 UNVERIFIED counts. Hosted exact-head results belong in the PR after audit.

STOP ALL ON; external lock ON; automation OFF; RELEASE HOLD. No Finance, credentials, provider calls, production/staging writes, listing publication, merge or deployment.
