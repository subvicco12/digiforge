# DigiForge agent instructions

Read `docs/DIGIFORGE_OPERATING_MANUAL.md` and `docs/completion/DIGIFORGE_EXECUTION_LEDGER.md` before making changes. Consult the actual **DigiForge Ultimate Master Blueprint v6.0** and **Master 500 v2** for governing requirements; if they are not accessible in the workspace, report the gap and do not invent their content.

At task start fetch live `main`, relevant PR head, exact-head CI, and changed-file dependencies. Use the writable scope assigned in the operating manual or the explicit task. Do not modify files owned by another lane; coordinate shared CI, migrations and cross-cutting files.

STOP ALL and external safety lock must remain ON, automation OFF. No live Printify/TinyFish calls, credential access, Etsy publishing, production writes, deployment, merges, or fabricated approvals. Passing CI is not certification.

Prefer substantial, bounded changes with focused tests and evidence. Update the execution ledger only with verified facts and links. End every handoff with exact SHA, tests, blockers, next tasks and production-impact statement.

If this branch is not merged, these instructions are a **proposed operating model**; do not claim they are active on main.
