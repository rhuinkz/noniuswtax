# NONIUSWTAX — R0/R1 live inventory, PRIVATE backup custody and offline restore

**Status 18 Nov 2026:** R0 partially read-only verified, R1 not yet run on owner's real private backups. This is **not** an instruction to deploy, overwrite the live site, change database access, or perform an in-place trigger repair.

## 1. Read-only Hostinger baseline already independently checked

The connected Hostinger account lists the active NONIUSWTAX site under `noniuswtax.depedtagb.online`, using the current hosting PHP **8.2.35**; the RC package manifest specifies minimum PHP **8.2.0**. `pdo_mysql`, `mbstring`, `gd`, and `zip` are available and enabled in the website's PHP configuration. SSL is active, HTTP→HTTPS redirect enabled, CDN enabled and server-side cache disabled. `expose_php` is currently **On** and must be examined in the R6 security review. No hosting settings were changed.

What is **not yet verified**: actual installed PHP file hash/version, DB vendor/version, database trigger privilege, backup date consistency, code file permissions and credentials, authentication/access segregation, security scan, server capacity and application historical row consistency. Those cannot be inferred from the hosting settings alone.

The owner previously performed an authorized *read-only* phpMyAdmin query: migration `037_checked_hash_period_guard` **no row**. The installed schema-only report: 21 tables, 177 columns and 14 triggers. Do **not** add the trigger to Hostinger manually.

## 2. Obtain a PAIR of private backups from Hostinger

Use the **specific NONIUSWTAX site** and **specific linked database**, not the whole school/organization's unrelated websites. In Hostinger hPanel navigate to the site's **Backups** area (sometimes **Files → Backups**) and choose the same suitable backup time for:
- Complete website files: commonly **`.tar.gz`**. Prefer an archive containing the installed `lib/bootstrap.php`, `lib/migrations.php`, `update.php`, private `config.local.php` and protected `storage/` assets. If selecting only the app directory, make certain these files and folders are all represented.
- Complete MySQL database including **table definitions, ALL rows, 14 triggers and the migration-history table**: commonly **`.sql.gz`**. A phpMyAdmin **Custom → SQL → Structure AND Data, include triggers, no CREATE DATABASE / USE** export may be used instead, producing a plain **`.sql`**, when scheduled backups cannot provide a matching snapshot.

Hostinger instructions: [Download website and database backups](https://www.hostinger.com/support/5981435-how-to-download-backups-at-hostinger/) and [Export database with phpMyAdmin](https://www.hostinger.com/support/4529011-how-to-export-a-database-with-phpmyadmin-in-hostinger/).

**CRITICAL:** Full backups contain private supplier TINs, government transaction details, credentials and audit snapshots. Save ONLY in a private access-controlled encrypted folder such as `C:\Users\Loy\Documents\NONIUSWTAX_PRIVATE\` on a BitLocker-encrypted disk, not the Git repository, a shared Drive, email attachment or chat. Retain at least one **independent encrypted off-device copy**, with ownership and retention authority; record backup time and provenance privately. The importer makes no actual backup by itself. A full database export can include writes occurring during export; use the hosting snapshot or a properly coordinated consistent read/backup policy to establish matching point-in-time provenance before R1 sign-off. Do **not** pause the live system without a separately authorized maintenance window.

## 3. Run ONE private restore verification command (Windows + Docker)

Open Docker Desktop. In GitHub Desktop Fetch/Pull branch `release/production-go-no-go-plan` (draft PR #13), then from the repository root substitute the **actual** backup filenames in the private folder:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\Test-PrivateHistoricalBackupRestore.ps1 `
  -FullSqlPath "C:\Users\Loy\Documents\NONIUSWTAX_PRIVATE\full-database.sql.gz" `
  -WebsiteArchivePath "C:\Users\Loy\Documents\NONIUSWTAX_PRIVATE\full-website.tar.gz"
```

Supported file formats: `.sql` or `.sql.gz` for the full database, `.zip` or `.tar.gz` for website files. The script accepts an optional `-SchemaReportPath`; default is your already-generated `schema-preflight-results/existing-schema-report.json`.

It first reads the actual site's **code archive manifest** (without extracting sensitive files) to identify `APP_VERSION` and verify expected PHP source files; safely scans the SQL export for disallowed database-switching/global/file operations and computes both backup SHA-256 digests. It then spins up a **separate one-off internal-network** Docker MariaDB database, imports the FULL historical records without opening a web port or making requests to Hostinger, checks the **restored counts only** (21 tables, 177 columns, 14 triggers, migration 037 absent, transactions/audit/history/period counts, FK orphan total) and destroys **only** the `nonius-private-recovery` project and its volume.

**Important privacy distinctions:** Real records do temporarily reside in Docker's private data volume while restoration runs; `docker compose down --volumes` deletes this volume reference but is **not cryptographic secure erasure**. Docker Desktop's backing disk and the operator drive must be encrypted; no Docker Compose ports or outward network are exposed. SQL importer output/errors are written solely to `NONIUSWTAX_PRIVATE\nonius-private-restore-logs\sql-import-private.log` (outside Git); NEVER upload this private log. The tool does not run the historical PHP site or attempt any upgrade, finance certificate issuance or public exposure.

If the archive contains multiple websites, a code version mismatch, unsupported permissions/SQL format or a zero/changed trigger baseline, the test fails rather than automatically altering data. A Hostinger compressed `.sql.gz` and `.tar.gz` are supported **without extracting their private contents into the Git working tree**.

## 4. The single report and interpretation

The ignored local output is `readiness-results/private-backup-restore-summary.json` (no row contents, names, TINs, emails, passwords, account info or SQL statements).

- **`LOCAL_RESTORE_VERIFIED_R1_SIGNOFF_PENDING`**: import and structural/FK verification passed on the temporary copy. This is **technical restoration evidence only**; backup snapshot consistency, independent encrypted copy, real deployed source version, privacy handling and reviewer signatures remain pending.
- **`RESTORE_TEST_FAILED`**: do not proceed to private historical-data sanitization or upgrade. Use the local diagnostic log privately; send only the redacted summary error.
- Neither result changes your production website. Production GO is impossible until R0–R8 complete.

You may share **only the aggregate JSON summary after reviewing it**. Do not send the SQL backup, the site archive, private import log, `config.local.php`, screenshots of real transactions or real TINs. The final project release must use the recorded exact candidate and independently signed finance/security/DB approval.

## 5. Next gate after R1

Once a complete and independently recoverable backup is privately held, reconcile the **entire 177-column schema** plus nested JSON content against `docs/HISTORICAL_CLONE_PRIVACY_MATRIX.md`. Build a fail-closed sanitizer (or obtain institutional privacy authorization for a restricted sealed replica), independently verify no leakage and checked-hash provenance, and only then execute the **one coordinated historical application migration 037 + upgrade + full rollback rehearsal**.

This step alone does not permit actual database writes on Hostinger. R1 and production remain PENDING until authorized sign-off.
