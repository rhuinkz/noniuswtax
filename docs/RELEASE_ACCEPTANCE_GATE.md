# NONIUSWTAX — Production Release Acceptance Gate

**Current status: NOT APPROVED FOR LIVE DEPLOYMENT.**

This file is the formal evidence checklist. Automated source tests and Playwright cannot replace independent fiscal, data-security or operational approval.

## One-command technical verification

Run the entire local suite via `tests/release/run-all.ps1` (Windows) or `tests/release/run-all.sh` (Linux/WSL2). Archive the HTML/JSON reports, the tested Git commit SHA, OS, PHP/MariaDB/Playwright versions and date. Any failed automated test blocks promotion.

| Category | Required evidence | Approval owner | Status |
|---|---|---|---|
| PHP/JS, installer and HTTP auth | Local full-suite report: all tests passed | Technical reviewer | PENDING |
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
