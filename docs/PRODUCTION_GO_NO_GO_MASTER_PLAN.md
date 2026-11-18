# NONIUSWTAX v0.4.57 — Production GO/NO-GO Master Plan
**Planning baseline:** 18 November 2026 (Asia/Manila)  
**Status:** TEST PLAN PREPARED — NOT YET PRODUCTION APPROVED  
**Repository:** `rhuinkz/noniuswtax`  
**Change rule:** No merge, new production artifact publication, Hostinger update, migration, database changes or live credential action without the owner's explicit authorization. This plan is not an authorization.

## 0. Release baseline and truthful starting evidence

The owner completed the consolidated local Windows test on 18 November 2026:
- Pinned source commit: `adf3fb4bdce027f65c2a18de2ca4d94e8424e35f`, app `0.4.56`.
- Staging-only ZIP: `noniuswtax-0.4.57-rc.1-STAGING-ONLY-adf3fb4.zip`; **exact Windows SHA-256** `e8d1fe062073f81d68bd0c2707951a0dcae1543a8d5c1aa0dd074a1e5ccb367a`.
- One-command local automated acceptance: `AUTOMATED_PASS_PENDING_MIGRATION_037_REVIEW`, all three synthetic suites PASS; 36/36 cumulative Playwright runs; updater install + code/SQL rollback PASS; tax arithmetic and synthetic Form 2307 rendered; 10,000 synthetic transactions; informational 30-request performance probe; no real records copied or changed.
- Installed database **structure-only** metadata: 21 tables, 177 columns, **14 triggers**. Current source: 21 tables, 177 columns, **15 triggers**. Sole identified difference: `protect_checked_hash_period_update` BEFORE UPDATE on `transactions`.
- Authorized owner read-only phpMyAdmin query `SELECT id,status FROM schema_migrations WHERE id='037_checked_hash_period_guard'` returned **no row**. Mandatory migration `037_checked_hash_period_guard` creates that trigger. A **disposable** 14→15 trigger / 036→037 rehearsal passed; the real installed schema has **not** been migrated.
- Production deployed PHP version/actual source checksum, complete migration history, DB data characteristics, and host PHP/MariaDB extensions/rights are **not yet independently verified**. Do not assume deployed code equals pinned 0.4.56 just because structural metadata matches.

**Current release decision: NO-GO / DEFER.** Existing automated tests cover the source and a disposable synthetic site. They do not satisfy historical upgrade, tax-law, deployment capacity, hosting security or sign-off gates.

## 1. The single coordinated rehearsal: high-level flow

One **coordinated release-acceptance window** after all private inputs and reviewers are ready; *not* a series of ad hoc production SQL experiments. Individual diagnostic reruns are allowed **only after a failed gate**; any code/DB migration change invalidates affected evidence and mandates repeat of the integrated window on an updated pinned artifact.

| Phase / owner | Inputs (private if applicable) | Mandatory work and output | Gate to advance |
|---|---|---|---|
| R0 — Release owner + DBA | Confirmed current live code revision, PHP/MariaDB versions, host feature/permissions, schema history; frozen RC hash; signed maintenance scope | Complete live **read-only** inventory without exposing IDs, names, amounts, secrets; inventory object names/counts/migration state, database version, extensions, work queue, migration permissions | **R0 PASS:** provenance, access and current installed baseline positively identified |
| R1 — Security + DBA | Encrypted off-site site-code + *consistent full* DB backups with triggers, migration status and account configuration held privately | Restore to **two** isolated non-production targets; independently prove restore, trigger counts, representative counts/checksums, access rules; record SHA-256, tested RTO/RPO, custody | **R1 PASS:** backups restorable and separate from updater backups |
| R2 — Privacy reviewer | Local backup in restricted folder, R0 schema map and data dictionary | Fail-closed private sanitizer: classify every table/column including JSON, snapshots, audit/history, TINs, identities, credentials, notes and tokenized keys; replace with synthetic values or use an approved isolated real-data replica under appropriate controls; block unknown data; independent negative leak test | **R2 PASS:** reviewer signs de-identification + integrity report; no secret or real identifier in outputs |
| R3 — DBA + release engineer | Actual installed **code and schema version** + approved R2 clone; exact RC ZIP | Start clone offline/no outbound email or BIR submission, confirm **14→15 trigger 037** migration via application updater, preserve table/record/amount aggregates consistent with approved sanitized records, references, closed periods, histories and hashes; record all before/after diffs | **R3 PASS:** upgraded historical installation, zero unexpected schema/data drift |
| R4 — DBA + application owner | R3 upgraded clone + independently created matching pre-upgrade backup | Execute *complete* rollback of code and SQL to single consistent pre-change point; compare tables, triggers, migration row status, counts, transactions/audit/history totals, frozen hashes, role login, invoices/2307; time RTO/RPO | **R4 PASS:** restored baseline equals authorized pre-upgrade snapshot, no unapproved loss |
| R5 — Finance/BIR signatories | Current official RR/RMC and BIR form plus independently calculated golden ledger | Verify VAT/EWT/ATC applicable effective dates including 2024–2026 changes, government payment rules, centavo boundaries, supplier categories, school/Division fund scopes; inspect physical Form 2307 printed specimen for each relevant case and signer | **R5 PASS:** finance reviewer independently signs applicable rules, exact PDF outputs and exception handling |
| R6 — Security/ops | Isolated staging hostname + read-only target-host checks, no live secrets in reports | HTTPS, access control, CSRF, roles/tenant isolation, private-path and backup denial, credentials/secret-history scan, audit append-only, denied reset endpoint, current supported PHP and extension compatibility, monitoring and alert rehearsal | **R6 PASS:** independent security reviewer accepts; no critical/high unresolved finding |
| R7 — Performance + users | Agreed capacity target and workload mix, isolated staging representative of host limits | Meaningful concurrent **authenticated** role actions, transaction posting/checking, 2307/report rendering, peak reads and 10k+ rows, latency/error/resource measurements at stated concurrency; user acceptance on real workflows | **R7 PASS:** measured approved SLO and resource headroom; no unapproved destructive or cross-tenant errors |
| R8 — Change authority | All R0–R7 signed evidence, backup/rollback playbook, final artifact signed, code freeze and scheduled window | Independently check exact artifact hash, branch diff and production-specific secrets/certs, code-equivalence tests after version bump, approve change ticket and planned GO/NO-GO decision | **R8 READY:** recommendation **READY_FOR_FORMAL_GO_DECISION**; only actual authorized sign-off can grant **GO** |

**Important:** R0/R1 must precede any handling of actual historical records. R2 must pass before an anonymized dump enters a less restrictive environment. Keep the raw backup **off GitHub, Google Drive, chat uploads, CI logs, and public_html**. The application cannot send emails or files to real taxpayers during rehearsal.

## 2. Exact historical-data design: no silent corruption

The source has at least **21 tables** with `schools`, `members`, `suppliers`, `transactions`, `audit`, `transaction_history`, `system_settings`, `transaction_pending_edits`, `transaction_submitted_backups`, `transaction_periods`, `transaction_period_history`, `update_history`, and **LONGTEXT JSON** inside `data`, `snapshot`, `details`, `before_data`, `after_data`, `release_details` and other fields.

Sanitizer **must not** just use global string replacement or overwrite signed/checked snapshots while retaining the original hashes and implying they remain valid. Use the following explicit independent decisions for each field:

1. `KEEP` only non-identifying schema/enum/status/required numeric values and safe reference dates; maintain synthetic aggregate distribution and period-state mix.
2. `TOKENIZE` all names, addresses, emails, account identifiers, supplier TINs, phone/contact entries and free-text IDs with deterministic fake referential links, collision checks, and null/uniqueness constraints. Use syntactically valid **non-real test TINs** that can never be mistaken for actual issued taxpayer numbers.
3. `REPLACE` all `password_hash`, password-reset requests, login throttles, tokens/session/keys, `system_settings` secrets, and update-history actors with test-only values; do not copy live config or sessions. Disable mail/updates/external callbacks; use dedicated test identities.
4. For JSON/long text (including history and audit): parse recursively; refuse unknown object keys without approved transformations; independently scan source and output for leaked private tokens. Preserve the *presence* and shape of historical workflows.
5. For checked/finalized transaction hashes: preserve original data **only within the restricted real-data replica** or intentionally rebase them from already sanitized data with clearly recorded `SYNTHETIC_RESEALED` provenance. Original hashes must **never** be represented as authentic after changing their inputs. Independently compare immutable original record count/hash inventory before sanitization and restored pre-change inventory after rollback (without publicizing originals).
6. Prevent `FOREIGN_KEY_CHECKS=0` from masking orphaned references in the final clone; verify every FK and relevant logical reference, all triggers/migrations/unique keys, audit append-only and closed-period guards.
7. Fail closed if any unknown table/column/JSON path appears or if scanner finds real identifiers, account secrets, sensitive transaction narrative or unverifiable checked hash. Re-run privacy review before startup.

**Current status of sanitizer:** NOT IMPLEMENTED OR APPROVED. It cannot be responsibly written against a structure-only count alone. Obtain the owner-held `schema-preflight-results/existing-schema-report.json` or a local structural metadata report **without any row values** before building a field-by-field scrubber. If scrub/reseal cannot preserve meaningful historical checks, use a tightly restricted authorized real-data staging replica within the same institutional security boundary, with explicit data-owner permission and encryption, rather than falsely labeling data sanitized. No actual-data replica should be sent to ChatGPT.

## 3. Migration 037 and updater acceptance

- Baseline: installed `schema_migrations` has **no row** for `037_checked_hash_period_guard`. Existing trigger count 14, freshly migrated count 15. Do not change production migration records or manually add triggers now.
- On the **private clone only**, confirm the database user can `CREATE TRIGGER`, existing trigger definitions do not conflict, migration 037 runs via the **normal application bootstrap/updater** and writes `schema_migrations.status='applied'`.
- Independently check the exact SQL trigger body, `SHOW TRIGGERS` = 15, all other schema objects unchanged except approved migrations, and a closed-month `checked_snapshot_hash` update is rejected. Confirm open/reopened behavior where applicable, plus frozen hash comparisons and transaction/audit continuity.
- If a previous upgrade partially applied DDL before the history row was written, **stop** and design a tested idempotent repair on staging; do not assume a second updater run is safe.
- Verify code + SQL backup is taken before migration, and independent backup is already restorably tested. If the trigger is missing or the updater reports inconsistent state, **NO-GO**.
- Never infer runtime security from presence of the trigger name alone: test the guard behavior.

## 4. Finance and Form 2307 sign-off are genuinely independent

The code's 13 golden mechanical cases are *not* tax-law certification. Philippine BIR rules and forms may change. Reviewers must select applicable rules **as of each actual payment date**, not merely today; include historical corrections and source-of-fund/taxpayer status.

Official starting references for responsible finance sign-off:
- BIR Certificates: https://www.bir.gov.ph/bir-forms?tab=Certificates
- BIR 2025 RR index: https://www.bir.gov.ph/2025-Revenue-Regulations
- BIR 2026 RR index: https://www.bir.gov.ph/2026-Revenue-Regulations
- RR 5-2025 (withholding-related amendments): https://bir-cdn.bir.gov.ph/BIR/pdf/RR%20No.%205-2025.pdf
- RR 24-2025 (top withholding agents): https://bir-cdn.bir.gov.ph/BIR/pdf/RR%20No.%2024-2025%20Digest%20FINAL.pdf
- RR 3-2024 (EOPT VAT/percentage tax amendments): https://bir-cdn.bir.gov.ph/BIR/pdf/RR%203-2024%20%28final%29.pdf

These are **review starting points**, not a statement that every named rule applies to all payments. Approved finance reviewer must complete `docs/FINANCE_BIR_SIGNOFF.md`, independently calculate centavo cases and physically compare current issued Form 2307 version, printed positions, payor/payee/ATC/TIN, quarterly dates and signatures. Any legal/form difference means **REJECT, FIX, REBUILD, RETEST**, not a signed technical override.

## 5. Environment, performance and rollback thresholds

Before a definitive GO, the organization must **write down its actual target** for concurrent staff, date/period operations, transaction volume, report/PDF jobs and school/division workflows. The prior local 30-request `p95` is **informational only** and does not prove 1,000 simultaneous users. Choose an SLO through technical/business approval, e.g., standard API p95, critical write p95, error rate, DB connection usage, worker/CPU headroom and degradation/soak behavior; collect logs with synthetic/redacted identifiers.

Set **RTO** and **RPO** in advance. As a reasonable starting proposal (subject to organizational acceptance), use **RPO=0 acknowledged writes during a controlled freeze** and **RTO ≤30 minutes** for the rehearsal; measure rather than assume either. Enforce a freeze before consistent backup, prevent post-backup writes while testing rollback, and arrange an explicit change-window stop decision. A failed restore, lost trigger, incorrect period hash, excessive outage or unauthorized access requires **NO-GO** and rollback of the isolated test environment.

## 6. The one-window evidence and decision contract

All results feed a single **private** `release-gates.json` from `docs/RELEASE_GATE_TEMPLATE.json`. Every mandatory gate includes:
`status` (`PENDING`, `PASS` or `FAIL`), `evidence_id` (private reference, *no sensitive content*), `reviewer_role`, `reviewed_at_utc` and `artifact_sha256`. An automated suite may supply **evidence** but never impersonate a reviewer or sign an approval. Independent finance, privacy, DBA, security, performance and release-owner roles sign where required.

`scripts/Assess-ProductionReadiness.ps1` will emit an auditable, **non-mutating** `release-readiness-assessment.json` with:
- `NOT_READY` if any mandatory gate is absent, failed, unsigned, inconsistent with candidate hash, or evidence is stale,
- `READY_FOR_FORMAL_GO_DECISION` only when all verified technical gates and human sign-offs are present,
- **never** `PRODUCTION_GO` by script alone. Actual GO requires the authorized owner/change authority's separate written signature and change ticket.

**No premature GO:** Production release is NOT authorized by a successful CI run, a passing checker, or the user's generic “let's do it.” The final approved production package must have its **own** version, exact hash, independent staging smoke after any version/changelog changes, and fully signed change-control record. A change to application behavior, database migration, tax/form mapping, access control or updater after testing invalidates the relevant evidence and demands re-run of the integrated acceptance for the new artifact.

## 7. Pre-approved operational order (plan only)

1. Get owner consent for **backup export**, limited to authorized administrators; verify target host and private custody. No actual database records uploaded to chat.
2. R0 baseline → R1 proven off-site backups → R2 anonymized/secure clone with *independently verified absence of leaks*.
3. **One coordinated full staging window:** R3 exact package upgrade and migration 037, R4 full rollback, R5 finance specimen+golden ledger, R6 security, R7 realistic performance/UAT.
4. Generate single readiness assessment, independently review deviations, sign appropriate records and *only then* schedule formal GO/NO-GO change-control decision (R8).
5. If **READY** and the owner explicitly approves deployment later: recheck backup+hash/host+maintenance freeze, controlled production update, non-destructive smoke, rollback on fault, formal closure.
6. If any critical gate FAILS or remains PENDING: **NO-GO**, leave production unmodified; create a narrowly scoped fix with regression test and regenerate readiness evidence.

## 8. Evidence custody and constraints

Private records (real DB backups, code snapshots/config, key material, raw tax worksheets, user identities and raw logs) remain encrypted on user-controlled devices or institution-approved storage with restricted access. GitHub contains only test plans, scrub policy *definitions*, synthetic fixtures and redacted/aggregate evidence. Do not put private backups in user-visible Git branches, workflow artifacts, chat or a publicly served directory. `staging` must be **separate DB credentials, storage, host and mail** from production.

### Final gate checklist

- [x] Exact staging candidate archive + all synthetic Docker/Playwright/updater/rollback suite passed 2026-11-18.
- [x] Installed database structural diff isolated to missing mandatory trigger `037_checked_hash_period_update` (*trigger name actually `protect_checked_hash_period_update`*), and migration 037 recorded **NO ROW**.
- [ ] R0/R1 baseline, secrets/security and restorable backup verification against actual installed state.
- [ ] R2 safe source-data transformation + independently approved privacy/integrity evidence.
- [ ] R3/R4 actual historical clone upgrade **and** rollback using exact artifact.
- [ ] R5 independent BIR tax matrix and printed Form 2307.
- [ ] R6 hosting security, leakage/tenant isolation, infrastructure compatibility.
- [ ] R7 real target workload and user acceptance.
- [ ] R8 complete signed release evidence and final versioned production artifact.
- [ ] Explicit organizational written deployment authorization (**separate future action**).

**Outcome at plan creation: NO-GO / DEFER.**
