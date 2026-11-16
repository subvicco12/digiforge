# Source/package verification equivalent

This verifies the independently rebuilt package against immutable Git objects. It
does **not** claim that the unavailable CI archive bytes were read or compared.
Earlier canonical CI read-back attempts for Audit #5027's artifact `14197110578`
(source `aa4e7f37551d0f0048d27a6b1ce407d1fc5327d8`) returned HTTP403 from the
execution receiver and CLI redirect; the connected download produced a file
reference, not locally readable bytes. Audit #5031 was still running when this
source-equivalence check was performed; its completed artifact was not observed.

Verified source: `4ef63d8bc2ef2ea60459e1d8c428e927637cfd22`.
Git tree: `4b0299e1c29ddd0c0e330afb01b1875f904a0627`.
Local Git: `2.52.0`.

- Independent inner ZIP SHA256: `956e36cdfc84aa3fc26c2ee3e3ae3ea8704c0f4bb322d97cb2dfc30e350e7681`.
- Size: 3,471,665 bytes. All 826 exported regular files verified.
- ZIP CRC passed. Exported path set equals the exact commit's Git archive export.
- Every file's raw bytes produce the exact Git blob object ID and recorded SHA256.
  The Git tree records source modes `100644` or `100755`; these ZIP entries omit
  Unix permission metadata, so ZIP permission equivalence is not claimed.
  No untracked working bytes included.
- Release manifest identifies the same commit and ZIP digest, with external actions
  and production activation both false. Nothing was installed or deployed.
- Full per-file verification receipt retained in the execution workspace at
  `/workspace/digiforge-platform-sprint/4ef-source-equivalence/equivalence.json`,
  SHA256 `b56eaabf85261e090381b84fa9396638b05213249346242fe0b12dd3c90b060f`.

An independent reviewer can reproduce without provider access:

1. Create a detached worktree at the exact source commit above.
2. Run `bash bin/package-plugin.sh /tmp/digiforge-source-proof`.
3. Check `digiforge.zip.sha256` and the release manifest's commit and false activation
   flags. The script archives the Git commit, not mutable working files.
4. Obtain the exported path set with `git archive --format=tar --prefix=digiforge/ HEAD`
   at the same commit. Read ZIP/TAR members without extracting or executing them;
   require equal regular-file path sets, safe rooted paths and a clean ZIP CRC.
5. For each ZIP file, resolve its object ID/mode from `git ls-tree -rz --full-tree HEAD`
   at that commit. Compute SHA1 of `b"blob " + decimal_length + b"\0" + raw_bytes`
   and require the exact Git object ID. Also record each raw-byte SHA256.
6. Verify the exact-head CI's package-build, expected-commit manifest, checksum,
   boundary and upload steps separately. Audit #5031 is the run for this source:
   <https://github.com/subvicco12/digiforge/actions/runs/43802656852>.
7. A reviewer with working artifact access can additionally download the canonical
   CI artifact and compare its inner ZIP digest. That additional comparison remains
   **NOT OBSERVED** here; outer artifact digests and source equivalence are not a
   substitute for an assertion that those two ZIP byte streams match.

S2-ORD-04's existing independent local-software acceptance remains pinned to exact
`aa4e7f37` and successful Audit #5027. This procedure does not promote S2-OPS-13,
certify the newer photo integration, establish hosted/installed readiness, or grant
native execution authority. STOP ALL/external lock ON, automation OFF and RELEASE
HOLD remain active.
