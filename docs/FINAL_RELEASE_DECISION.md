# NONIUSWTAX — Final Production GO/NO-GO Decision Record

**NO-GO until the full evidence and independent signatures exist.** This process is for release readiness, not an automated deploy. Latest owner evidence: 2026-11-18 Windows `acceptance-summary.json` reported synthetic **36/36 Playwright PASS**, exact candidate ZIP install/rollback PASS, and known live pending migration 037. The original deployed database has **14** triggers; the pinned candidate source has **15**. A read-only Hostinger query found **no migration 037 row**.

The production installation is **not yet authorized**. Keep PRs #8, #9, #11 and #12 draft or otherwise unmerged pending the recorded decision.

## A. Evidence to collect once, not repetitive testing

| Evidence | How | Approved reviewer |
|---|---|---|
| Exact Windows source/RC synthetic test | Reuse `acceptance-results/acceptance-summary.json`; pinned source+SHA must match | Technical/release engineer |
| Historical installed DB 037 migration and independent restore | Run `Run-PrivateHistoricalDrill.ps1` against a private locally held full SQL backup, **with data-owner approval**. **No web server or live connection** | Database/recovery administrator |
| Independently anonymized legacy web staging | Source-of-truth scrub matrix for all 21 tables/JSON, no original personal data; exact artifact's real UI flows and full code/DB rollback | Privacy + technical reviewer |
| BIR/finance | Current legal VAT/EWT/ATC source/rates, transaction-date matrix, independent decimal scenarios, physical 2307 samples | Authorized finance/accounting officer |
| Hostinger security and privacy | Read-only authorized-site HTTPS/private-path checks, sessions/roles, secrets/credentials/history review, NPC privacy impact assessment | Security/Privacy Officer |
| Realistic load | Named target concurrency, p95, timeout, error threshold and tested host hardware; signed results | Technical/infrastructure owner |
| Real disaster recovery | Encrypted off-site file+DB backup including triggers; independent restore with measured approved RPO/RTO | Recovery administrator |
| Final production artifact | `v0.4.57` **GA** artifact, signed provenance and SHA-256, **retested exact bytes in staging**; candidate ZIP's `STAGING-ONLY` status is not production authorization | Release engineer + security |
| Cutover | Change window, downtime notification, stop-writes, live backup, preflight, rollback conditions, two-person change control | Authorized Project Owner |

A change to PHP source, tax calculations, updater, DB migrations, certificate mapping or deployment method after the tested source commit requires a newly pinned build and new affected tests. No changing accepted values just to match application output.

## B. Closed-box historical rehearsal (private, full data, NO web app)

**Privacy permission required.** The historical dump is *not anonymized*. Export a complete, consistent **Structure + Data** SQL backup with triggers and migration history from the authorized existing Hostinger database. Do **not** include `CREATE DATABASE`/`USE` or account commands. Preserve it encrypted in a local folder **outside** Git; retain independent off-site backup according to approved policy. Also preserve the deployed application files, excluding public disclosure of private configurations.

From PowerShell on the **draft review branch** `release/final-production-go-no-go-gates` with Docker Desktop running (after Fetch/Pull):

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\Run-PrivateHistoricalDrill.ps1 -PrivateSqlPath "C:\Users\Loy\Documents\NONIUSWTAX_PRIVATE\full-private-backup.sql" -ApprovedForLocalPrivateData
```

Replace the example path with the actual local file. This intentionally requires the explicit `-ApprovedForLocalPrivateData` switch. **Do not run it** if you do not have institutional approval to process the historic data on your PC.

The script loads the original backup into dedicated Docker MariaDB 11.4. It does **not** run Apache, publish ports, or connect to Hostinger. It checks all 21 tables/177 columns/14 triggers and exactly migration 037 pending, fingerprints each table privately in a Docker-only volume, applies the ordinary source migration 037, verifies all business-table fingerprints unchanged and 15 triggers, restores the original snapshot into an independent temporary DB, and compares original table digests. All containers and named volumes are deleted afterward. **The original encrypted backup remains your responsibility** and is not deleted by the script.

The only local report is `private-historical-results/private-historical-summary.json`, containing aggregate booleans and counts. **Never share the private SQL, screenshots of real transactions, account credentials or TINs.** The output is not proof of anonymization.

If the import, migrations, row preservation, restore, or DB-version match fails, the gate is NO-GO; no automatic fix is applied to Hostinger.

**Database-engine caveat:** The private local drill presently uses MariaDB 11.4. The actual Hostinger engine/version must be documented separately using a *read-only* `SELECT VERSION();`. An engine mismatch may require a target-equivalent rehearsal and is a release blocker until resolved.

## C. Authorization template and one final status command

The checked-in template `docs/production-approval.template.json` has **every gate PENDING**. Only after independent reviews, copy it to a private **ignored** location:

```powershell
New-Item -ItemType Directory -Force .\private-release-evidence | Out-Null
Copy-Item .\docs\production-approval.template.json .\private-release-evidence\production-approval.json
```

Record each actually approved gate's `reviewer`, `reviewed_at` (ISO 8601), `evidence_id`, plus the exact separately **signed** GA artifact `v0.4.57` hash/attestation and final authorizing officer signature reference. Do **not** mark any field APPROVED until evidence is complete. Do not fabricate signatures or upload the completed private record to public GitHub.

Then run:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\Check-FinalGoNoGo.ps1
```

The checker **reuses** already generated technical evidence, **does not rerun** the 36/36 tests, never reads raw SQL, and writes `private-release-evidence/final-gate-report.json`. It reports `NO_GO_BLOCKED_BY_OUTSTANDING_GATES` until all technical and manual signoffs are present. If a completed record says GO, it emits `GO_RECORDED_REQUIRES_INDEPENDENT_SIGNATURE_VERIFICATION`: **a human must independently verify the actual approval signatures and exact release artifact before execution**. The checker never connects to Hostinger or deploys.

## D. Independent finance/legal acceptance

Use `docs/FINANCE_BIR_SIGNOFF.md`. Finance must verify the correct period, supplier classification, withholding treatment, current approved VAT/EWT/ATC rates and centavo rounding for each scenario, plus **physical Form 2307 output** and signer identity. Relevant current BIR material requires a fresh review:
- [BIR 2026 Revenue Regulations](https://www.bir.gov.ph/2026-Revenue-Regulations), including RR 6-2026 on invoice/CWT information.
- [BIR RR 24-2025 digest](https://bir-cdn.bir.gov.ph/BIR/pdf/RR%20No.%2024-2025%20Digest%20FINAL.pdf), concerning certain top-withholding-agent income payments.
- [BIR Forms — Certificates](https://www.bir.gov.ph/bir-forms?tab=Certificates) for Form 2307 edition and issuance instructions.
- [National Privacy Commission privacy and security guidance](https://privacy.gov.ph/5-pillars-of-compliance-5/) for PIA and approved personal data handling.

These references do **not** imply every school transaction follows the same rate. Rates and form editions are dated regulatory decisions, not a software regression-test conclusion.

## E. Final authorized cutover and rollback (only after formal GO)

1. Confirm institutional signoff, exact `v0.4.57` GA artifact SHA-256, verified independent signature, tested staging hash, production DB engine/PHP and compliant private secrets.
2. Schedule/announce maintenance. Freeze writes, record baseline row/audit totals and hashes, obtain two independent encrypted, restorably tested site+DB snapshots (including triggers and migration history).
3. Under two-person control, upload **only the approved exact GA artifact** to the Hostinger app updater and review the proposed file changes before actual installation. No blind `ALTER TABLE` or manual trigger changes.
4. Verify mandatory migration 037 **`applied`**, 15 triggers (or verified required list), financial reconciliation, 2307 output, roles/isolation, HTTP/TLS, no SQL data loss, and monitoring before allowing new writes.
5. If any critical gate fails, **stop writes and restore the matching pre-change application files and full database**; independently verify migration history, triggers, audit/event counts, certificates and login. Document downtime and RTO/RPO and do not reopen before incident owner signs off.

**As of 2026-11-18 none of these production cutover actions have taken place.** A technically passing test can make an authorized GO decision possible, but only independent real-world evidence and sign-off can grant it.
