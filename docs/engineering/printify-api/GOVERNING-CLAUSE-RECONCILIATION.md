# Exact governing clause-to-policy reconciliation

Sources read through authenticated Drive for this review:

- [Ultimate Master Blueprint v6.0](https://docs.google.com/document/d/1isN_2m7eu5DTjykY7X67woiPx7wluhPc/edit), Consolidated Replacement Edition, 27 September2026. Normalized UTF8 readable-fetch text SHA256 `f0adbadce2d9013d0bb393d6855a326f512d03bf5fccc275ac82dbbf1c20335c`.
- [Development Operating Manual](https://docs.google.com/document/d/1YBvHZktB__bf3iV8qM00hCAobayM-VM1/edit), Version1.0, 17 October2026, **Proposed — draft GitHub PR1190**. Normalized UTF8 text SHA256 `749421b2f223823c4cd98c19477858c77621f8980c793873563bc1d450292e8f`.

These hashes identify the readable extracts, not original Office/Google file bytes. The Manual is not represented as merged/adopted governance. Current explicit owner restrictions prevail. Section references below are the actual Blueprint headings; do not use the earlier .24 report's mistaken §3/§23/§24 shorthand for hierarchy/STOP ALL.

|Topic and exact source|Verbatim clause|Policy conclusion|
|---|---|---|
|Blueprint §6, Hierarchical Controls — Global → Shop → Workflow → Operation|“A narrower scope can restrict a capability further; it can never override a disabled parent safety control.”|A read-only label cannot bypass disabled parents. A broader exception needs explicit amendment.|
|Blueprint §29, Production Activation Principles|“Global STOP ALL remains available at every stage and overrides every shop/workflow setting.”|Retaining the ON flag while changing admission requires a semantic amendment, not a claim of unchanged precedence.|
|Blueprint §31, Non-Negotiable Safety Rules|“STOP ALL outranks everything.”|No implied GET exemption. Emergency behavior must remain dominant even if the flag is already ON.|
|Blueprint final baseline|“Global safety ceilings remain absolute; normal controls, AI budgets, provider routes and workflows are shop-scoped.”|Explicitly identify the proposed narrow change to this absolute ceiling; leave it inactive now.|
|Blueprint §22, Async, Scheduling and Reliability|“STOP ALL and capability policies are rechecked at execution time, not only when jobs are queued.”|Check current authority at each execution boundary; no durable admission from stale approval.|
|Blueprint §23, Security, Credentials and Audit|“Operation-bound credential access only after request validation and safety interlocks.”|Disabled-integration vault admission cannot silently be bypassed.|
|Blueprint §23|“Credentials remain server-side, encrypted/scoped and unavailable to frontend code/logs.”|Existing vault custody; no export or credential creation.|
|Blueprint §23|“Never store secrets in repository, chat, query parameters or operator-visible responses.”|Metadata, minimized receipts and reference hashes only.|
|Blueprint §16.2, Controlled external operation lifecycle|“Approved local intent → scoped execution authorization / one-time permit → operation-scoped credential access → controlled adapter request → external-attempt evidence → SENT → normalized CONFIRMED_SUCCESS / CONFIRMED_FAILURE / UNKNOWN → reconciliation before any retry of UNKNOWN.”|Separate intent, permission, credential use, actual attempt and outcome. Offline validation is not execution.|
|Blueprint §23|“External mutations require authorization, idempotency, durable operation evidence and reconciliation semantics.”|This is an additional mutation rule; it does NOT say GETs are exempt from other interlocks.|
|Blueprint §15, Printify Strategy|“Provider integration must remain shop-scoped and product/template-scoped.”|Exact account/shop/integration selection; no generic provider proxy.|
|Blueprint §15|“Shops 2 and 3 should use distinct provider shop identities/mappings even if credentials are shared where supported.”|No Pop-Up substitution or Shop3 assignment.|
|Blueprint §31|“Configured ON is not the same as authorized/effective.”|A configuration bit or encrypted credential is not permission or account proof.|
|Blueprint §29|“Run read-only connection tests.”|This appears in a controlled activation sequence; it is not an unconditional diagnostic/GET override.|
|Blueprint §29|“Enable Printify preparation only after template/provider acceptance.”|No automatic normal integration activation for acceptance.|
|Blueprint §27, Development and Release Method|“After a substantial integrated batch, cut the next release, bump all established version pins, audit exact release head, verify certified artifact digest/manifest/inner ZIP integrity, then provide installable ZIP.”|Versioned preserved artifact and exact-head evidence; source CI and exact-ZIP qualification distinguished.|
|Blueprint §27|“After user installs release, verify production health/controls/readiness before any controlled external action.”|Installation and later execution remain separate; no installation is authorized here.|
|Blueprint authority priority table|“Current explicit user instruction and explicit human approvals.”|Owner may decide an amendment explicitly, but this mission grants preparation only.|
|Manual §4, Safety rules|“STOP ALL ON; external safety lock ON; automation OFF.”|Preserved current posture.|
|Manual §4|“No production writes, deployments, merges, live Printify or TinyFish calls, credentials, orders, Etsy publishing or international activation without separate owner authorization.”|Separate live/installation/configuration approvals mandatory.|
|Manual §4|“Passing CI is not production certification, publication approval or release authorization.”|No deployment or live certification inferred from tests.|
|Manual §4|“Protect four-shop ownership and scoped credentials. Preserve UNKNOWN for unsupported supplier facts.”|Unknown account/credential association stays UNKNOWN.|

## Explicit documentary limits

Neither retrieved document explicitly specifies Ed25519, a signed Global Primary Owner envelope, the exact independent reviewer contract, integration enabled database semantics, a WordPress ability connector, or a general external-lock GET exemption. No such quotation is invented. Those implementation rules are established by current repository contracts and explicit owner decisions: database-backed Global Primary Owner plus native capabilities/fresh email; Ed25519 delegated contracts where applicable; independently reviewed provider scope; configured/enabled ordinary vault/runtime admission; owner/session-bound abilities. They are preserved here, not replaced.

Read-only operations are mentioned in Blueprint §29 and later engineering detail, but no statement authorizes live external GETs despite STOP ALL. Local minimized receipt persistence is supported by §23 audit requirements and existing repository behavior; it does not authorize provider traffic. Existing catalog-only exception code is historical, narrowly governed repository evidence and not a general Blueprint permission.

## Decision

POLICY_AMENDMENT_REQUIRED: a broader acceptance GET under active STOP ALL/external lock and disabled integration3 cannot be inferred from current text. See the standalone DRAFT / NOT ADOPTED amendment. Normal enabled-integration checks remain unchanged. A separate inactive acceptance-binding proposal distinguishes identity review from normal operational readiness; it grants no exception, vault use, binding write or enabled state.
