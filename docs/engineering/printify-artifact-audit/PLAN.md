# Exact-artifact audit implementation plan
Owner scope: prepare digest-pinned isolated audit on PR1253; execute only after intake/security approval. Preserve plugin ZIP and existing audit.

1. Test fail-closed artifact boundary validation before implementing: wrong digest, path escape, symlink, altered manifest member.
2. Add exact-digest verifier, preservation manifest and narrow inherited HTTP sender checks; stage no production code in Git.
3. Add dedicated manual workflow with authenticated same-repository artifact intake, bounded wrapper, digest verification before plugin extraction; use extracted classes for focused tests and block external test egress.
4. Require protected environment review before intake and independent reviewer acceptance after results; reject absent review controls. No default enablement or automatic execution.
5. Independent review, focused harness checks, update existing draft PR. Stop before dispatch until owner approves intake/controls and provisions independent reviewers.
