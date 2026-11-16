# NONIUSWTAX — Staging Upgrade, Production Deployment and Rollback Runbook

**HARD STOP: RELEASE NOT APPROVED.** This document is a procedural plan, not an instruction to deploy now. The candidate ZIP is **staging-only** until all release gates are signed.

## A. Before any operation (change manager / Project Owner)

1. Confirm approved target application, exact current installed version, hosting plan, operating domain, PHP version and MySQL/MariaDB version. Do not infer the live installation's source/DB version from the GitHub repository.
2. Compare `git rev-parse HEAD` and signed/checksummed release evidence. Confirm candidate is based on tested source `adf3fb4bdce027f65c2a18de2ca4d94e8424e35f` and version is `0.4.57-rc.1`. The ZIP must pass `Verify-ReleaseCandidate.ps1`.
3. Confirm **no production use** of the candidate before written approval, with independent finance, security and database recovery gates complete.
4. Schedule a maintenance window with school/Division users, freeze submissions and tax/month-end posting during the change, establish a contact tree and a rollback decision deadline.
5. Record the previous release, source files, account permissions, DB migration IDs, trigger count, application checks, current counts of transactions/history/audit rows and the location of **encrypted off-site backups**. Do not paste names, password hashes, TINs or personal/financial records into GitHub or ChatGPT.

## B. Isolated staging rehearsal (required before production)

1. Provision a **separate** PHP 8.4-capable staging website and MariaDB/MySQL database under dedicated credentials. Staging must not connect to the live database. Prevent accidental external login/email delivery and payment/BIR submissions.
2. Start with the **actual previously deployed v0.4.56 code** and a sanitized, representative backup of the **existing** DB (not merely the empty test installer). The sanitized clone should preserve table structures, indexes, migration IDs, triggers, and representative totals, while replacing personally identifiable information, accounts, supplier TINs and credentials with synthetic values.
3. Confirm source ZIP's expected base release `0.4.56`; the updater should refuse any older version until intermediate approved updates exist. Ensure the `minimum_php` and other runtime requirements are met.
4. Execute the production updater's **Upload and Review** step in this *isolated staging account only*. Verify it displays version `0.4.57-rc.1`, checksum, file inventory and release notes. Independently compare its reported ZIP SHA-256 to the saved sidecar. Check that no `config.local.php`, `storage/` or confidential data is inside the package.
5. After the explicit separate review and under change approval for staging, perform **Install Update** only in staging. Confirm the updater's internal code/database backups are complete, verify app version and migration `037_checked_hash_period_guard` status plus all required triggers. Do not treat the updater's own backup as your only recoverable copy.
6. Repeat School User, Division Administrator and Project Owner workflows; intentionally attempt prohibited cross-school reads, SQL writes, reset action, status edits and closed-month hash mutation. Check frozen amounts, audit immutability, change history, period locks and owner update history.
7. Compare pre/post totals for all real-world-representative (sanitized) records. Test Forms 2307, CSV reports, source-of-fund/ATC filters and reconciliation totals.
8. Rehearse rollback **on staging**: stop writes, restore the exact matching pre-update files and database snapshot to the same point in time, and confirm migration records, triggers, transaction/audit counts, role access and ability to sign in. Time the restoration; record tested RTO/RPO.
9. Capture approved reviewer signatures, screenshots without sensitive details, trigger counts, database backup hash, application ZIP SHA-256 and resolved defects. A failure is a **NO-GO**.

## C. Production preflight checklist — all approval gates mandatory

- [ ] Formal release approval record signed by authorized officials; independent finance/BIR rules and Form 2307 sign-off complete.
- [ ] Exact staging update and **rollback rehearsal** passed for identical candidate archive SHA-256.
- [ ] Full external scan for repository/history secrets completed; exposed credentials rotated; no personal data in release archive.
- [ ] Public TLS certificate valid; HTTPS redirect and cookie security verified; server rejects direct requests for `config.local.php`, `lib/`, `storage/`, `.git/`, `tests/`, `bin/`, `.env` and backup files.
- [ ] PHP has `pdo_mysql`, `mbstring`, `gd` and ZIP; DB account has required migration and trigger privileges but not broad unnecessary permissions.
- [ ] Domain/hosting PHP worker and database connection concurrency tested against **agreed** target (the 30-request synthetic probe is insufficient).
- [ ] Owner access controlled with MFA on hosting/repository, logs protected, monitoring/alerts and least-privilege policies in place.
- [ ] Two independent, restorable backups completed: files/runtime secrets and full DB **including triggers**. Encrypted off-site copy retained, recovery permissions/time recorded.
- [ ] Maintenance, user notification, stop-work and rollback decision contacts documented.

## D. Approved production installation (execute ONLY after all boxes above)

1. Confirm the authorized change ID, approval timestamp, exact package checksum and current application version, then pause new transactions/writes.
2. Use Hostinger's website dashboard to export current **site files and database** separately; store encrypted copies outside the web root. See [Hostinger backup guide](https://www.hostinger.com/support/5981435-how-to-download-backups-at-hostinger/).
3. Restore backups to a fresh isolated clone and verify rows/triggers **before** changing production.
4. As Project Owner, enter the app's two-stage Update workflow, upload the **approved exact** ZIP, review the file list, version and SHA-256, and confirm installation under dual control only after staging evidence is signed.
5. Verify version, migration state, triggers, real transaction and audit counts, service health and role restrictions. Re-run **non-destructive** role and private-path checks only. Never run the synthetic suite or `resetTestData` on the live site.
6. Release the maintenance window only after sign-off on smoke results and user acceptance. Record exact start/stop times, backups, hashes and final change disposition.

## E. Rollback decision and method

**Trigger rollback** for any migration failure, wrong version, missing trigger, unauthorized data access, corrupted financial totals, unmatched audit counts, unavailable certificates, or unrecoverable outage.

1. Freeze all writes; alert administrators and preserve logs. Do **not** continue posting after deciding to roll back.
2. Restore the complete pre-change site code, protected configuration, and corresponding **consistent DB snapshot** in the right environment. Code rollback alone cannot reverse changed schema/data.
3. Restore triggers, verify `schema_migrations`, checksums/approval status and row-count snapshots against the recorded preflight. Do not run old code against newer unverified schema.
4. Retest login, school isolation, read-only reports, checked hashes, monthly locks and sample 2307 outputs. Obtain documented authorization before reopening writes.
5. Record who decided, what changed, actual RTO/RPO, losses/delays (if any), incident issues and follow-up changes. Never disclose backup contents or secrets publicly.

## F. Recording evidence

Complete `docs/RELEASE_APPROVAL_RECORD.md` and attach private documents as appropriate. Do **not** upload sensitive data or production database files into this public repository.

**Current state:** Candidate creation and all production actions remain **PENDING**. The verified disposable 12/12 technical suite proves neither an upgrade of a previously installed site nor conformity with BIR or hosting security obligations.
