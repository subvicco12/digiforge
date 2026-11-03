# Digital PDF content integrity — reproduced correction

Baseline: draft #1236, `36324b6f2e31c69d68f2685027745f6f18bd6681`, audit #4772 PASS. Draft/offline correction only. STOP ALL ON, external lock ON, automation OFF, RELEASE HOLD; NO-GO.

## Reproduction

`LocalAssetProducer::pdf()` called `wrap()`, which inserted a blank line after every source paragraph and returned `array_slice($lines,0,54)`. A synthetic source with 60 required sections plus a final sentinel produced a valid single-page PDF. Poppler extraction ended at section27: required text and the final sentinel were absent. The baseline `AutomatedQa::inspect()` nevertheless returned `passed=true` because PDF structure/checksum do not prove completeness. Mobile output could also place text below the page. Independent extraction further reproduced default StandardEncoding changing ASCII apostrophe/backtick into curly quotes; the generator and verifier now require explicit WinAnsiEncoding, with all95 supported printable ASCII characters covered. `strip_tags()` and iconv TRANSLIT silently changed other input.

Original synthetic input SHA-256: `a1c6eaee3255ecd9d851e176593f4c476d825e9c37fbb8a47af7a6894285db4e`.
Truncated baseline PDF SHA-256: `647b534c1777c0747c84c8b55c40b1a286438a5193bcb3f00a216e91c0b08986`.

## Bounded fail-closed contract

This patch deliberately does not add a general typesetter, automatic pagination or Unicode font engine. The versioned local-text-pdf-v2 profile accepts printable ASCII and explicit CR/LF line breaks. Unsupported Unicode (including accented/decomposed input and emoji), invalid bytes, tabs, nested/nontext values, oversized lines/pages/documents return explicit WP_Error before artifact/revision QA. No transliteration, Unicode normalization, tag stripping, truncation, automatic wrapping/shrinking or font substitution occurs. Literal angle brackets, parentheses, backslashes, leading/trailing spaces and blank lines inside nonblank pages survive.

Use an ordered string-page array or reserved `[[PAGE_BREAK]]` separators. Blank optional sections are omitted as before; all-blank documents fail. CRLF/CR are represented as equivalent PDF line breaks; original source identity remains separately hashed. At most100 explicit sections and8MiB source/output bytes are accepted. Physical filename-selected page geometry is unchanged:

| Profile | Points | Lines/page | Conservative characters/line |
| --- | --- | --- | --- |
| Letter/default | 612×792 | 50 | 42 |
| A4 | 595×842 | 54 | 41 |
| Mobile | 360×640 | 42 | 25 |

Width uses a conservative12-point advance allowance for the fixed11-point standard Helvetica font; it is a safety bound, not precise typography fit or licensing certification. Content that exceeds it needs explicitly reviewed line/page breaks or a separately supported renderer. Limits include blank lines/spaces; nothing is silently discarded to fit. This is an intentional compatibility restriction.

`pdf_source_sha256` hashes an unambiguous typed-content JSON envelope (text versus ordered pages, original supplied bytes); `pdf_text_sha256` hashes canonical visible page strings. The original manifest/version hashes remain unchanged. Metadata and immutable revision provenance carry the two hashes and profile; QA persists only hashes/profile/artifact hash, not a second plaintext document.

## QA and approval boundaries

PDF QA compares decoded literal text from the exact bounded local format to the separately supplied expected-text hash. Missing evidence, extra/missing/altered text, moved-off-page content, unexpected font/operators or malformed structure fail. PDF-like words in customer text are not treated as page objects. The complete bounded file is read, including supported documents larger than the former256KiB sample. Oversized actual files fail even when their prefix contains a valid PDF. The portal recognizes only the exact new check name and continues excluding raw details.

Product Approval and release-bundle validation independently recheck local digiforge_u3 PDF evidence: exact revision artifact/source/text hashes, current profile, one unambiguous PASS receipt, readable protected bytes and current content integrity. Missing/duplicate/conflicting proof and database failures fail closed. Historical structural-only QA cannot confer new local PDF approval/release readiness. Other governed PDF adapters retain their own contracts. Hashes are content evidence, never rights, linguistic, commercial or production approval.

No historical artifact, stored QA row, approval or version is rewritten/backfilled. Regeneration must use a new immutable artifact/version when bytes differ; existing-path conflicts remain errors. There is no schema/migration, privilege, shop-binding, Finance, provider or release-activation change.

## Verification

Initial regression:16 tests,13 failures and2 runtime errors reproduced omissions, rewriting/invalid input and missing completeness checks. Additional adversarial tests reproduced literal-operator misclassification, off-page QA and structural-only approval admission before correction.

- Full local unit suite:1,743 tests /9,181 assertions,1 existing skip.
- Focused real WordPress suite:59 tests /1,246 assertions, including25 new PDF cases and existing protected Digital/listing/shop-isolation cases.
- Independent Poppler25.03.0 extraction also verifies exact apostrophe/backtick output after the encoding fix. All61 required lines match expected source after explicit synthetic three-page preparation; no source content lost. Accepted synthetic PDF SHA-256: `5e5c88c564d044ea57d3400b276504e901e5c5dcde119f3f6879e929041ac6ae`.
- Source/visible-text hashes for that fixture: `cfc4666fb28a7218d1ceb536f24342800fc888f038ee31f47973a17d83d58791` / `be570f5a9c29692a6fcc724ec33add9e78a73c805930b528d80ba3188e310bbc`.
- The synthetic100-page Letter boundary document exceeds256KiB and passes complete extraction/QA. The101st section,8MiB+1 source, oversized page/line, unsupported text, missing optional content, tampering and immutable replay conflicts are covered.
- Exact-head hosted audit/package/security results belong in the final PR/issue receipt; no unobserved result is asserted here.

Acceptance counts remain246/268 non-Finance implementation candidates +22 UNVERIFIED and23 Finance-dependent UNVERIFIED. This corrects a proven software defect without certifying content, rights, language, Etsy delivery or installed-site behavior. Master500 identities/classifications are unchanged.

Next authentic inputs remain original commercially reviewed Digital content/rights, exact reviewed file versions and current Etsy/installed-site acceptance evidence. This patch does not authorize those external actions. For non-ASCII PDFs, a separately supported licensed-font/typesetting profile is needed; no customer text may be transliterated to work around this denial.

Hosted audit #4774 on intermediate8421cf9 failed the older portal artifact fixture, which reflected the private PDF byte-return method and supplied no expected source proof. The fixture now exercises the public protected producer and carries its actual evidence; every original format/authorization/redaction assertion remains. The portal check-name integration and an independently reproduced oversized-prefix QA case are covered by the combined59-test suite. Historical audit evidence is preserved: https://github.com/subvicco12/digiforge/actions/runs/39723544913 . Final exact-head audit is required after this correction.
