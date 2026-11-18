# NONIUSWTAX — Final Production Readiness Program (v0.4.57-rc.1)

**Status at 2026-11-18: TECHNICAL SYNTHETIC ACCEPTANCE PASS; PRODUCTION NO-GO.**

This is the **single consolidated path** from the already verified local Docker / Playwright package to a real signed **GO/NO-GO** decision, with no unnecessary repeated testing. It does not authorize any modification to the live Hostinger site. The signed decision is separate from test execution.

## 0. Immutable release identity / existing evidence

- Verified source commit: `adf3fb4bdce027f65c2a18de2ca4d94e8424e35f`.
- Exact *Windows* candidate ZIP SHA-256: `e8d1fe062073f81d68bd0c2707951a0dcae1543a8d5c1aa0dd074a1e5ccb367a`.
- Candidate: `v0.4.57-rc.1`, intentionally named **STAGING-ONLY**.
- Owner's 2026-11-18 one-command `acceptance-summary.json`: 12/12 Playwright across **each of three** independent suites, 36/36 cumulative test runs, tax/CSRF/roles/Form 2307 render, 10,000 synthetic transactions, backup/restore, exact ZIP installation/rollback, all automated PASS with **expected exit 2** for a known schema difference.
- Live database **structure-only**: 21 tables, 177 columns, 14 triggers. Source schema: 21 tables, 177 columns, 15 triggers. The sole difference is missing trigger `protect_checked_hash_period_update`.
- Authorized **read-only** phpMyAdmin query returned **NO ROW** for `037_checked_hash_period_guard`; source migration 037 is mandatory. **No live DDL was run.**

This evidence will be **reused** unless the source, updater ZIP, test logic, migration, tax rules or hosting requirements change. Changes to them invalidate the affected gate and require a new signed candidate and corresponding rerun.

## 1. Final gates — enforce ALL before GO

| Gate | Required measurable evidence | Fail / NO-GO condition | Current status |
|---|---|---|---|
| A: Immutable source and synthetic acceptance | Exact hash/commit matches owner report, all 3 suites PASS, archive and rollback verified | Different ZIP, failed tests, incomplete logs | **PASS** (owner, 2026-11-18) |
| B: Historical data confidentiality | Authorized **encrypted private** export kept outside Git/ChatGPT; source-site snapshot; schema/JSON inventory and privacy impact review; independently validated masking/scrub policy | Unknown columns/JSON paths, real supplier TIN/email/password in staging, missing privacy approval | **BLOCKED — sanitizer and real backup not inspected** |
| C: Real installed-state migration/rollback | Fully **isolated offline** rehearsal on privately held historical backup; pre/post table/record digests, no unexpected row changes; 037 applied and trigger restored; backup restored to matching original | Missing trigger, unexpected mutation, failed import/restore, engine mismatch | **PENDING** |
| D: Sanitized app-level UAT | After independently approved scrub, staging web + Playwright with realistic roles and historical distribution, 2307 and reconciliation; code/DB rollback independently verified | Identity leakage, broken checked hash, role bypass, legacy record mismatch | **PENDING** |
| E: Philippine tax legality and printed BIR 2307 | Authorized finance reviewer approves ATC and payee class, VAT/EWT rate-by-effective-date matrix, independent centavo cases and printed Form 2307; confirm 2025–26 BIR changes | Unverified rates/form/effective dates or misprinted amount/TIN/signer | **PENDING** |
| F: Hostinger and security/privacy | Read-only approved-domain private path checks, HTTPS, sessions, least-privilege DB, PHP version/extensions, secret/history scan, security and privacy assessment | Private file exposed, unexpected permission, secrets in code/backups, target mismatch | **PENDING** |
| G: Target load and recovery | Named target user/concurrency and max p95/error SLO agreed in writing; measured load on target-like hardware; *separate* encrypted files+DB backup restore proving chosen RPO/RTO, trigger count and login | Unmet SLO/RPO/RTO; no independently restored backup | **PENDING** |
| H: Sign-off and release artifact | Finance, DB/recovery, security/privacy, infrastructure and owner decisions **APPROVE** in private dated records, exact ZIP signed/attested; production runbook rehearsed and maintenance arranged | Any unsigned approval, unknown release version/hash, unapproved deployment | **PENDING** |

**GO = A through H approved, with zero critical defects, an exact signed build, and an owner-authorized maintenance window.** Anything else is **NO-GO**. Passing the local suite or restoring trigger 037 in a disposable database never grants production authority.

## 2. One coordinated rehearsal, not repetitive small tests

### Preparation (owner, offline)

1. Keep the already passed `acceptance-summary.json` and exact candidate ZIP under private release evidence. They must be preserved as immutable copies with SHA-256.
2. Collect **full live site files and database backup** from the authorized Hostinger account. Verify DB identity and database **engine/version** via **read-only** `SELECT VERSION();`. Export a consistent database dump including triggers and migration history, **not just structure**; exclude `CREATE DATABASE`/`USE` and account-management statements. Preserve encrypted originals and a restoreable second off-site copy. **Never upload any dump or `config.local.php` to GitHub/Google Drive/ChatGPT.**
3. Confirm deployed PHP source version and snapshot complete installed code; never infer installed version from GitHub. Have the privacy/information owner approve processing of a full historical backup on the local testing machine, its retention, access and secure deletion.

### Closed-box historical migration drill (new local capability)

Use `scripts/Run-PrivateHistoricalDrill.ps1` **only on the approved local machine with a real backup path outside the repo**. The script is designed for **private, temporary Docker/MariaDB, CLI-only, no Apache/web server or exposed ports, internal-only network**. It imports the backup into an isolated test database, fingerprints actual table content **without printing the rows**, verifies migration 037 pending and guard missing, exercises the normal migration runner on the disposable copy, checks the 15th trigger and unchanged existing records, and independently restores the pre-migration private snapshot. It removes its own database and internal backup volume after the test and writes a **summary without names, identifiers, TINs or transaction amounts**. A clean summary cannot prove the raw backup was anonymized: **it was not**.

**Privacy restriction:** this closed-box drill is *not the sanitized web staging clone*. The data inside isolated Docker remains real until disposed. Do **not** run a web browser or expose a port to it. Do not run without approved local data handling.

### Sanitized UI regression and final security/tax acceptance

1. A separate **fail-closed, field-by-field** sanitization policy is needed for all 21 tables and nested JSON snapshots and history. Unknown keys, TIN/email/user identifiers, credentials, audit details, linked keys and frozen hashes must be addressed explicitly. Merely changing account names breaks tax certificates and their SHA-256 snapshots. An independently approved masked clone is mandatory before exposing any web app UI.
2. Validate that clone has **no original TIN, email, personal names, password hashes, account secrets or sensitive free text**; rederive or explicitly mark synthetic checked-hash seals with new provenance, never present synthetic seals as originals. Validate FKs, uniqueness, source counts, periods, roles, and representative calculation results.
3. Run the existing exact-candidate updater and rollback in an **isolated staging website with only sanitized data**, then rerun Playwright workflows and detailed 2307 print inspection. Document changed/unchanged fields, correct version, integrity of financial totals, and complete backup restore. All test files remain private; report only anonymous aggregate evidence.
4. Complete a non-destructive external read-only security test against the **authorized** Hostinger site, privacy PIA, BIR rule sign-off, representative target load and independent backup recovery.
5. Use `docs/FINAL_RELEASE_DECISION.md` and `docs/RELEASE_APPROVAL_RECORD.md` for the single reviewed decision. **No production installation before authorized GO.**

## 3. Acceptance benchmarks / decision ownership

- **Role and isolation:** no cross-school data access, no unauthorized owner SQL writes, CSRF enforced, closed-month hash mutation blocked by DB even if application rules bypassed; 0 critical vulnerabilities.
- **Accounting:** 0 unexplained variance in checked-record amounts/ATCs/period totals between approved historical ledger and staged reports. Printed 2307 must be reviewed by authorized finance signatory for current BIR form and period rules.
- **Recovery:** 100% preserved transaction/history/audit records in consistent snapshot, all mandatory triggers installed and effective, backup restore *actually tested*. RTO and RPO limits must be **defined by organizational owner before testing**, not assumed from synthetic timings.
- **Capacity:** target concurrent users and p95 response/error SLO must be **agreed** before tests. The original 10k rows/30 HTTP calls with ~4 s p95 is only a sample, **not** a capacity certification.
- **Finance/legal:** check official BIR effective-date changes, notably RR 24-2025 affecting certain top withholding-agent payments and RR 6-2026 invoice/CWT requirements; these are **review triggers**, not automatic changes to all school tax rates. Use `docs/FINANCE_BIR_SIGNOFF.md`.
- **Privacy:** apply NPC privacy impact assessment, access minimization, retention and secure disposal procedures. The local closed-box drill must never export data values into logs or issue trackers.
- **Post-cutover monitoring:** named owner reviews HTTPS, errors, audit alerts, generated Form 2307, transaction totals, trigger/migration integrity and service health; stop writes/roll back on critical failure.

## 4. Decision and deployment sequence

```text
Immutable candidate + synthetic PASS
    ↓
Private backup and installed source snapshot (owner-controlled)
    ↓
Offline closed-box historical migration + restore PASS
    ↓
Approved fail-closed sanitization + no-PII verification PASS
    ↓
Sanitized staging exact-ZIP upgrade + rollback + UAT PASS
    ↓
BIR/Finance + Hostinger security/privacy + target load + recovery PASS
    ↓
Signed independent approvals + exact ZIP attestation
    ↓
GO / NO-GO decision signed (GO only when ALL)
    ↓  AUTHORIZED GO ONLY
Maintenance freeze → independent fresh backup → verified install → smoke checks
    ↓
Record production outcome or restore pre-change code + matched DB snapshot
```

**As of 2026-11-18, no production changes have been made by these tasks.** All real-data handling is local and requires the data owner's permission. PRs remain draft until review; the exact package is not published as a production release.
