# Qualified .17 normalization implementation plan

Goal: establish a truthful new source baseline reproducing all741 qualified package members and the exact ZIP, then stop for two independent reviews.
Architecture: runtime bytes at existing plugin-root paths are recovered from pinned qualified .17. Existing e957 development tests/docs are retained as historical tooling, not runtime qualification. Explicit payload inventory, byte-preserving Git attributes and a committed-object deterministic builder replace cumulative git-archive packaging for this normalization branch.
Authority: owner's explicit DEVELOPMENT-ONLY normalization instruction in current session, Issue1252; qualified digest5303ef7eb5ede7dab770599f5cfd279022c77d3d8665a3d5ab1d2fce5ef83dac; Work final custody report b4601c7cbbec5c768d29507af392695fdf89d1c0614226e298120fa3598605ca.

- Verify Drive input ZIP/741member hashes/modes/CRC and all32Work receipt hashes; preserve original handoffs.
- Test deterministic packaging guard against missing/extra/changed/path-unsafe/nonregular members before implementing builder.
- Recover exact runtime files, remove cumulative-only runtime paths, preserve0644 member modes and all binary/line-ending bytes; add explicit .gitattributes -text.
- Add docs/normalization/QUALIFIED_PAYLOAD.json and provenance statement. No runtime edits, API import, feature port or new approval.
- Implement bin/package-normalized-10317.py; canonical bin/package-plugin.sh delegates only for this normalized baseline. Package from exact committed Git blobs, never mutable worktree; reproduce historical sorted Unix0644/2026-11-06/DEFLATE9 ZIP with Python3.12.14/zlib1.3.2. Fail on mismatched pins.
- Commit isolated branch; from clean exact SHA build twice, verify allmember metadata/bytes, full archive byte equality, inventory, PHP syntax and scanner evidence. Publish source archive/patch/manifests/receipts to Drive, verify readback.
- Ask Lane A and Work on Issue1252 for independent preservation review at exact SHA; stop. Neither review can be self-issued. No POD integration before both PASS.

Review focus: altered bytes/hash; deleted or extra runtime path; line endings/permissions drift; ZIP order/compression metadata; provenance overstated as original authorship. All covered by strict inventory + fresh checkout/source-to-archive comparison and declared limitations. Production/source audit and runtime regression acceptance remain separate; phase2 prohibited at this gate.
