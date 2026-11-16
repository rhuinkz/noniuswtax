# NONIUSWTAX v0.4.57-rc.1 — Formal Approval and Go/No-Go Record

**THIS FORM IS NOT A RELEASE AUTHORIZATION. No fields below are preapproved.**

## Release identity

| Property | To record |
|---|---|
| Candidate version | 0.4.57-rc.1 (staging-only) |
| Verified source commit | `adf3fb4bdce027f65c2a18de2ca4d94e8424e35f` |
| Candidate ZIP filename | `noniuswtax-0.4.57-rc.1-STAGING-ONLY-adf3fb4.zip` (owner's local output; not published) |
| Candidate ZIP SHA-256 | `e8d1fe062073f81d68bd0c2707951a0dcae1543a8d5c1aa0dd074a1e5ccb367a` |
| Independently verified provenance/signer | __________________________________ |
| Proposed Hostinger website (private record) | __________________________________ |
| Currently deployed app version and corresponding files/DB | __________________________________ |
| Maintenance change ID, start and end (Asia/Manila) | __________________________________ |
| Incident / rollback owner and contact (private record) | __________________________________ |

## Gate approvals

| Gate / document | Responsible reviewer | Evidence ID and date | Decision |
|---|---|---|---|
| Source commit and full synthetic 12/12 test suite | Technical reviewer | 2026-11-16 JSON test report; checksum/commit linkage confirmed | **AUTOMATED PASS; independent reviewer PENDING** |
| Candidate ZIP archive + SHA-256 + manifest | Release engineer | 2026-11-16 owner build + matching Windows CI; 27 file SHA-256 checks | **AUTOMATED PASS; reviewer/signature PENDING** |
| Disposable synthetic update/rollback rehearsal | Technical reviewer | 2026-11-16 uploaded `staging-summary.json`: PASS; 12/12 tests, 10,003 transactions, 15 triggers, code and DB restoration flagged passed | **AUTOMATED PASS; reviewer PENDING** |
| Upgrade on real-world-representative sanitized staging clone | Database/release administrator | — | **PENDING** |
| Downgrade/rollback rehearsal with restored triggers | Database/release administrator | — | **PENDING** |
| VAT/EWT/ATC rule and effective-date validation | Authorized finance / tax representative | — | **PENDING** |
| BIR Form 2307 generated and physically printed specimens | Authorized finance / records representative | — | **PENDING** |
| Public HTTP security, TLS, accounts and secret scan | Security reviewer | — | **PENDING** |
| Realistic peak load, database pool and approved p95 SLO | Performance/technical reviewer | — | **PENDING** |
| Site files, DB trigger and independent encrypted off-site backup | Backup/recovery administrator | — | **PENDING** |
| Privacy, retention, recovery objectives and organizational process | Authorized information owner | — | **PENDING** |
| Final production installation authorization | Project Owner / authorized organizational signatory | — | **PENDING** |

**No gate may be marked PASS merely because the 12/12 synthetic Playwright suite passed.** The local 30-request p95 of approximately 4.0 seconds is *informational*, not acceptance at a target concurrent-user count. Independently confirmed BIR rules and real hosting checks are required.

### Recorded local candidate test evidence — 2026-11-16

Owner-provided files report successful synthetic upgrade/rollback against candidate SHA-256 `e8d1fe062073f81d68bd0c2707951a0dcae1543a8d5c1aa0dd074a1e5ccb367a`. The source was a **newly installed disposable synthetic environment**, not a historical data clone. Its rollback was automatically verified, but the independent authorized recovery sign-off remains blank. The version was temporarily stale in two anonymous requests, then updated on the third; this is consistent with opcode caching and warrants post-update monitoring in staging.

`staging-summary.json` timestamp: `2026-11-16T07:32:47.9919853Z`. Evidence records are held by the owner and must be archived privately. **No signature or production approval was given by this automated test.**

## Reviewer declarations

- **Finance / Accounting:** I checked the applicable BIR sources, payment-period tax rules, independently computed amounts, ATC choices and required 2307 print specimens. Name, designation, signature, date, decision: ___________________________
- **Database / Recovery:** I applied the exact candidate in staging from the actual previously deployed version, restored a complete backup including triggers and verified the rollback plan. Name, designation, signature, date, decision: ___________________________
- **Security / Privacy:** I verified external website restrictions, managed credentials, repository history and authorized data handling. Name, designation, signature, date, decision: ___________________________
- **Performance / Infrastructure:** I ran representative workload on target infrastructure and accepted the documented SLO and concurrent capacity. Name, designation, signature, date, decision: ___________________________
- **Release Owner:** I checked hashes, stage reports, change window, maintenance communication, deployment operators and full rollback evidence. Name, designation, signature, date, decision: ___________________________

## Final change-control decision

**GO / NO-GO / DEFER:** **DEFER — AWAITING ALL REQUIRED APPROVALS**

**Authorized name and designation:** ___________________________

**Signature and date:** ___________________________

**Approval evidence storage and retention location (private):** ___________________________

**After authorized cutover — actual version, hash, start/end, smoke checks, incident and closure:** ___________________________

Any source, tax calculation, database migration, certificate mapping, or update-process change **after the tested commit** invalidates the earlier test evidence for that changed behavior and requires appropriate re-verification. Never place live financial records, secret keys, password hashes, or real supplier details in this public repository.
