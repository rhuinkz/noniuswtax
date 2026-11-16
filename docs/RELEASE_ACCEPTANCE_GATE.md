# NONIUSWTAX — Production Release Acceptance Gate

**Current status: NOT APPROVED FOR LIVE DEPLOYMENT.**

This file is the formal evidence checklist. Automated source tests and Playwright cannot replace independent fiscal, data-security or operational approval.

## One-command technical verification

Run the entire local suite via `tests/release/run-all.ps1` (Windows) or `tests/release/run-all.sh` (Linux/WSL2). Archive the HTML/JSON reports, the tested Git commit SHA, OS, PHP/MariaDB/Playwright versions and date. Any failed automated test blocks promotion.

| Category | Required evidence | Approval owner | Status |
|---|---|---|---|
| PHP/JS, installer and HTTP auth | Local full-suite report: all tests passed | Technical reviewer | AUTOMATED PASS on 2026-11-16 (user-provided local log; independent approval pending) |
| Database migration and rollback | Isolated staging restoration from sanitized clone; trigger integrity; successful upgrade and rollback | Database administrator | PENDING |
| School/Division/Owner security | Role matrix, CSRF, session, direct API access attempts, server-side isolation | Application security reviewer | PENDING |
| Withholding tax calculations | Approved tax rule/ATC table, independent synthetic golden cases including centavo rounding | Authorized tax/finance reviewer | PENDING |
| BIR Form 2307 | Approved rendered and printed examples; TIN, amount and signatory mapping | Authorized finance/records reviewer | PENDING |
| Hostinger production controls | HTTPS, runtime config and storage denial, secrets review, appropriate PHP extensions | Deployment operator | PENDING |
| Recoverability | Verified backup restore with required triggers and documented recovery objectives | Deployment operator | PENDING |
| Capacity | Target concurrency, synthetic transaction volume, acceptable p95, database and PHP resource budget | Technical reviewer | PENDING |
| Versioned distribution | New version, signed/hash-verified release, install/update and rollback checks | Project Owner | PENDING |
| Final authorization | Signed change record and official production release approval | Authorized organization representative | PENDING |

Do not call the application an approved production release merely because the local package finishes successfully. Open blockers are tracked in GitHub Issues #3–#7.

## Local verification evidence — 2026-11-16

A local Windows Docker test of review-branch commit `12686f0dd4a93484f32e2e3134496edad9d3382e` was reported by the application owner. The log shows:

- **10/10 Playwright browser/API/DB synthetic checks passed** (no skipped tests).
- **26 PHP files passed syntax lint**, plus calculation, destructive reset removal, and diagnostic SQL guard smoke tests.
- Mandatory schema migrations and triggers, school isolation, month-lock/hash protections, append-only audit checks, synthetic Draft → Submit → Division Check, and local Apache private-file access checks all completed within the smoke suite.
- Lightweight performance observation: 15 concurrent local dashboard requests, p95 = **3,761 ms** (no capacity threshold established).
- Docker reported disposal of the synthetic MariaDB volume and test containers after completion.
- Test reports were generated on the local computer under `test-results/`, **not committed to Git**. The log alone does not substitute for independent report archiving, broader security review, real BIR tax validation, backup restore or production sign-off.

**Decision: automated synthetic local smoke gate PASS; production release remains NOT APPROVED.**

## Release evidence template

- Candidate version and Git commit:
- Date and environment:
- HTML report:
- JSON report:
- Migration/restore evidence:
- Independent BIR sign-off:
- Security sign-off:
- Capacity results:
- Release archive/hash and rollback instructions:
- Authorized decision: **PENDING / APPROVED / REJECTED**
