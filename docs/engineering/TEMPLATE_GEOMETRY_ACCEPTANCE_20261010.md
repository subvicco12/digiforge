# Provider geometry drift acceptance

Original Blueprint §§13.4/14 require safe-zone, bleed and panel geometry to be revalidated after provider geometry changes. Baseline ProductionTemplateContract retained only position, method, width and height, silently dropping supplemental geometry.

The contract now retains bounded JSON geometry evidence, canonicalizes object keys while preserving panel-list order, and includes the evidence in both version and material fingerprints. Nonfinite values, objects/resources, invalid UTF-8, excessive collections/depth/size fail closed. Existing templates without supplemental fields retain their prior shape and fingerprint. Previously discarded historical geometry cannot be reconstructed; explicit fresh evidence and a new DRAFT version are required.

Behavioral verification covers each geometry dimension, object ordering, panel order, invalid numbers and legacy compatibility. WordPress persistence demonstrates safe-zone drift creates version 2 in DRAFT, preserves version 1 VALIDATED and its exact fingerprint, and persists the changed geometry. No approval, deployment or external provider call occurs.

Local unit suite: 1,156 tests / 7,350 assertions. Legacy checks, configured PHPStan/PHPCS and changed-file syntax pass. Full WordPress suite: 200 tests / 1,848 assertions (baseline 199 plus one drift test). Exact-head audit is required before merge. Finance untouched; STOP ALL ON, external lock ON, automation OFF remain required. Source reconciliation PR #1150 contains the original full-text inventory; requirement-specific product acceptance remains open.
