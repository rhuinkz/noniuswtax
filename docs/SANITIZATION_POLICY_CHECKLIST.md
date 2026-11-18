# NONIUSWTAX — Mandatory 21-Table Historical Data Sanitization Specification

**Status: REQUIREMENTS DRAFT, NOT AN IMPLEMENTED/APPROVED SANITIZER.**

This is the **privacy gate B/D** for the final production readiness plan. The newly built private closed-box migration drill tests an actual database backup **without anonymizing it**, inside temporary CLI-only Docker. This document defines what must be checked **before** an actual historical database is ever used with a staging web UI or Playwright browser.

A real data dump should never be uploaded to GitHub, public Google Drive, ChatGPT or an unaudited staging host. Local processing requires the information owner's approval, and a privacy impact assessment (PIA).

## 1. Fail-closed table inventory

Source schema currently defines **21 tables** (21 tables and 177 columns in the owner's zero-row Hostinger schema report). The following is an initial review map, **not** a guarantee every actual nested field has been identified.

| Table | Private-data hazards / required sanitization control |
|---|---|
| `schools` | Stable synthetic school IDs; replace names, addresses, school representatives, TINs, signatures, emails and every nested `data` field; preserve relationships through deterministic mapping |
| `members` | Replace email/user ID with dedicated `example.invalid` test accounts; never reuse original password hashes, reset tokens or credentials; generate new test-only passwords; preserve role/tenant structural distribution |
| `division` | Replace payor/authorized representative identities, addresses, TINs, email and all `data` JSON text |
| `atcs` | Validate tax codes, rates, types and active/effective dates for legal test coverage; inspect every `data` JSON property and replace any custom identity/narrative values |
| `suppliers` | Replace unique TINs, names and all `data` tax profile/contact data; preserve referential mapping and VAT/non-VAT category distribution with clearly fake unissuable TINs |
| `transactions` | Relink school, supplier, actor and IDs; handle `snapshot` recursive JSON, references, remarks, original payment date/gross, checked/submission hashes; do not present recomputed synthetic hashes as historical source seals |
| `audit` | Actor/entity/details JSON may contain identities, supplier details, transaction data and error content; immutable triggers mean **do not attempt live UPDATE/DELETE** to mask the audit trail |
| `system_settings` | Remove production URLs, configured email/TIN/address, private credentials, API keys, organization identifiers and free-text values; provide staging-only settings |
| `source_funds` | Preserve test category behavior (e.g., MOOE, SNED, SBFP, SIIF), but review all `data` JSON and custom source/fund identifiers |
| `transaction_history` | Thorough recursive scrubbing of `before_data`/`after_data` JSON, remarks, actor details and nested financial/certificate snapshots; preserve synthetic revision order |
| `transaction_pending_edits` | Remap transaction/supplier/source IDs, actor, private gross/date and all pending state without changing application workflow semantics |
| `password_reset_requests` | Clear real requests and tokens; construct optional fake expired/pending requests using synthetic accounts only |
| `transaction_submitted_backups` | Remap transaction/supplier/source, private gross/date, versions and historical state; no original payee data |
| `login_throttle` | Clear hashed IP/email/client identifiers and attempt counters; no real network footprints in staging |
| `transaction_reference_counters` | Preserve non-colliding sequence invariants with synthetic school mapping and synthetic references |
| `division_transaction_reference_counters` | Preserve sequence rules while removing associations with actual historical references |
| `transaction_deletion_requests` | Replace usernames, reason/remarks, review decisions and any free-text supplier details; preserve synthetic approval status transitions |
| `transaction_periods` | Remap school IDs and actor fields, keep representative open/closed/reopened states and valid period links |
| `transaction_period_history` | Remap school ID, actor and free-text reason, preserve representative event chronology |
| `update_history` | Scrub actor IDs/names, release details, errors, package metadata potentially containing internal paths and identifiers; preserve only sanitized release state |
| `schema_migrations` | Preserve schema migration status **exactly** for pre-upgrade compatibility testing. Never fabricate migration `037` as applied in the baseline. This table is structural history, not a user account |

## 2. Non-negotiable nested JSON protection

Search all values in `data`, `snapshot`, `details`, `before_data`, `after_data`, `release_details`, `setting_value` and free-text fields **locally**. Construct a **field-by-field allowlist** for each JSON *path*, including dynamic arrays/objects. No property may silently pass through merely because its key name is unfamiliar. Names, addresses, representative TINs, supplier TINs, emails, phones, IDs, school identifiers, transaction references, invoice details, narrative remarks and free-form notes are high-risk.

When a nested structure contains unknown keys, non-JSON text, embedded HTML/XML, binary data or a previously unseen field, **abort** sanitized export and request privacy review. No ad-hoc regex can establish anonymity for arbitrary JSON.

## 3. Financial/cryptographic correctness

- A **real checked transaction** uses a `checked_snapshot_hash` of the raw persisted `snapshot` bytes. Editing the snapshot without an explicit new **synthetic provenance** invalidates this proof. A scrubber must not merely modify the source row in place and claim it is still the original checked certificate.
- Original `submission_hash`, `checked_snapshot_hash`, `before_data`/`after_data` audit and history links must be treated as a **linked structure**. Either regenerate a fully consistent synthetic history and mark it **FICTIONAL**, or leave it only in the **closed-box historical migration drill**, where the real data never reaches a web UI.
- Do not silently carry original **amounts** or payment references to a public or cloud staging website. Generate representative synthetic amounts/date distributions with independent tax calculations and explicit test fixtures.
- Source-for-source historical ledger verification should occur privately in the closed-box migration drill. Browser UAT should be done on **independently sanitized** records. A signed reviewer must document which privacy-preserving tradeoff was accepted.
- Database-level append-only triggers protecting `audit`, `transaction_history` and periods may block modifying rows. A compliant sanitizer must build a new **separate** staging dataset before enabling protections, never bypass protections on the live website.

## 4. Acceptance: no values leak and behavior remains representative

Mandatory independent checks on sanitized staging data before a web server may start:

- [ ] Every original base-table row path and nested JSON field covered by an explicit approved mapping; unknown columns, types, arrays or object keys cause abort
- [ ] No original names, TINs, emails, password hashes, actual reference numbers, long financial narratives, addresses, phones, account tokens or other identifiers remain
- [ ] No real data can be reconstructed from pseudonymization mappings; reversible mapping keys are retained separately under controlled access, not distributed with staging
- [ ] All test accounts use freshly generated credentials and valid but **non-deliverable** domains; outbound email, API/BIR filing, payment submission and 3rd-party connectors are disabled
- [ ] Source counts/distributions and accepted synthetic financial scenarios documented without revealing original values
- [ ] All FKs and unique constraints satisfied, tenant and role isolation holds, closed-period rules and migrated trigger protections hold
- [ ] Rebuilt checked/submitted hashes match *synthetic* snapshot raw bytes and are labeled synthetic; no false historical provenance
- [ ] Full updated-code/DB rollback works and original source record integrity remains exclusively on its private encrypted backup
- [ ] Privacy officer signs off leakage tests and PIA; the staging database is physically separated from Hostinger live DB

**Current state:** None of these sanitizer and no-PII approvals have been executed against the actual historical backup. This policy must be signed and a purpose-built sanitizer independently tested before staging browser tests with historical-shaped data. Without it, the release stays **NO-GO**.

## 5. Source of privacy requirements

See National Privacy Commission guidance at [5 Pillars of Compliance](https://privacy.gov.ph/5-pillars-of-compliance-5/) and [National Privacy Commission circular/security resources](https://privacy.gov.ph/npc-issues-circulars-to-strengthen-personal-data-protection-in-ph/), including privacy impact assessment and risk management. This document is an engineering gate, not a legal privacy certification.
