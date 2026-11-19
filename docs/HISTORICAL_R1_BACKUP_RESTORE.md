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

### Why the first correct-database attempt stopped (18–19 November 2026)

The owner selected the correct database export `u574137232_noniuswtax.20261118211501.sql.gz` and the full website archive `u574137232.20261117211501.tar.gz`. The SQL format preflight **passed**, but the original site-archive parser failed before Docker startup because it could not identify **exactly one** `lib/bootstrap.php` within the website TAR. It did **not** prove the PHP file was missing; a Hostinger account-level TAR can contain multiple domain roots or nested subarchives.

The improved archive inspector (this draft PR) now:
- inventories **only the entry paths** locally, without printing or sharing them;
- selects the expected `noniuswtax.depedtagb.online` domain's exact application root when uniquely present, even if other sites are in the same archive;
- otherwise accepts a *single unscoped* application only if it is not explicitly assigned to another domain;
- rejects multiple ambiguous roots, another site's archive, absent application roots, missing PHP files and unsafe paths, returning **aggregate counts and a reason code only** in `readiness-results/private-backup-restore-summary.json`;
- records whether Hostinger backup filenames encode matching timestamps. Nonmatching times are a **review item**, not by themselves proof of incorrect data or an automatic failure of the isolated technical restoration.

The backup filenames are dated **November 18, 2026, 21:15:01** (database) and **November 17, 2026, 21:15:01** (website). The owner reports **no transactions and no code changes** during those 24 hours. This supports provisional use of the pair for a private local restore rehearsal, **but does not establish** that settings, membership, audit events, status, migration history, scheduled tasks, or configuration were unchanged. An independent reviewer must document a read-only cross-time comparison and backup provenance before signing R1. The tool leaves `source_backup_point_in_time_consistency_independently_verified=false` regardless of the filename dates.

**After Fetch/Pull**, rerun the **same one-command R1 procedure** with your existing local files; no database re-export is needed at this point. If it still reports `NO_APPLICATION_ROOT`, `MULTIPLE_UNSCOPED_APPS`, `AMBIGUOUS_EXPECTED_DOMAIN`, or `FOREIGN_OR_UNVERIFIED_DOMAIN`, stop and supply **only** the metadata summary. Do not share the TAR listing or raw archive.

### TAR root-directory marker adjustment — November 19, 2026

The owner reran the same private-backup command. The database SQL preflight again **passed**, but the website TAR was rejected with `Unsafe website archive path found` **before Docker started**. The prior inspector treated an empty path produced by normalizing a harmless TAR `./` root directory marker as unsafe. **This is a plausible explanation, not a confirmed analysis of the owner's private TAR file**; its individual entries were not uploaded or inspected.

The revised preflight skips ONLY the harmless standalone TAR archive root markers `.`, `./` and repeated `./` equivalents, then continues enforcing absolute-path, parent traversal (`../`), ambiguous-dot-segment, duplicate-path and wrong-site checks on all other entries. No source file or private TAR entry list is published. It records an aggregate `code_archive_safe_root_markers_ignored` count in the local JSON report.

The CI workflow separately tests (a) a fictional TAR with a standalone `./` root marker and a valid application, and (b) a similarly rooted TAR containing a malicious `../` file that must still be rejected **before any SQL import**. This is a test-tool correction only; R1 remains PENDING until the *real* private backup is restored and backup provenance independently approved.

### Duplicate normalized TAR entries — November 19, 2026

The owner retried with the verified correct local SQL.gz backup and the same private Hostinger TAR after the prior root-marker fix. The SQL preflight passed, the archive inspector advanced past unsafe-path rejection and then stopped on `Duplicate normalized website archive path`, **before Docker startup**. This means at least one TAR file entry repeats a normalized relative path; the cause and specific path(s) remain unknown because the real TAR listing was not shared. It is **not automatically safe** to ignore duplicates inside deployed PHP application code, which could make version, migration or rollback identity ambiguous.

The revised diagnostic collects and emits **counts and non-sensitive scope codes only**:
- `code_archive_duplicate_file_entries`: total repeated normalized file entries (never filenames)
- `code_archive_duplicate_exact_path_entries`: repeated literal entry path
- `code_archive_duplicate_alias_entries`: distinct entry names that normalize to the same path, such as `./app.php` and `app.php`
- `code_archive_duplicate_selected_site_entries` and `code_archive_duplicate_unrelated_entries`: whether repeats belong to the specifically selected NONIUSWTAX application or other archived domains/content
- `code_archive_duplicate_scope`: `NONE`, `ONLY_OUTSIDE_SELECTED_SITE_REVIEW`, or `SELECTED_SITE_AMBIGUOUS`

**Fail-closed rule:** any repeated normalized file inside the selected app (especially `lib/bootstrap.php`, `lib/migrations.php`, `update.php` or private configuration) causes `AMBIGUOUS_DUPLICATE_SITE_FILES` **before any SQL import**. No automatic first-entry/last-entry selection. Repeated entries *outside* the uniquely identified NONIUSWTAX root are permitted for **local verification only** and flagged for later backup-completeness review, because none are extracted or executed. A foreign-domain/ambiguous archive still fails.

The dedicated CI creates fully fictional Hostinger account backups to verify success for an unrelated domain's duplicate, and fail-closed behavior for both exact repeated PHP entries and `./` normalization aliases inside the selected site. All genuine backups and TAR listings stay on the owner's private encrypted disk.

**Next action after GitHub CI passes:** Fetch/Pull the draft `release/production-go-no-go-plan` branch, run the original command once more, and share only the inspected aggregate `readiness-results/private-backup-restore-summary.json`. If selected-site duplicates are reported, obtain a **site-specific complete** NONIUSWTAX file backup using the authorized Hostinger File Manager/Backups interface rather than weakening the verification. The 24-hour file/DB timestamp difference still requires independent R1 provenance review, and R1 remains unsigned.

### Site identification with 3 app roots and 839 nested archives — 19 November 2026

The owner used the correct private SQL.gz and the Hostinger full-site TAR. The SQL preflight passed, and the archive inventory reports **three bootstrap application roots, zero paths explicitly matching `noniuswtax.depedtagb.online`, and 839 nested archive files**. The verifier correctly stopped **before Docker**, because folder names alone cannot distinguish one installed app from two other copies. **No raw TAR listing, source code, config or private records were shared.**

The updated checker first identifies the source's exact `APP_NAME = 'Non-IUS Withholding Tax Monitoring'` and installed `APP_VERSION` in each candidate's `lib/bootstrap.php`. If paths do not identify the domain and more than one app is present, it then reads each candidate's **own `config.local.php` inside the TAR, directly into process memory only**, and checks **the literal, exact target database name `u574137232_noniuswtax` and site URL `noniuswtax.depedtagb.online`**. **No PHP is executed, no password or config text is logged, no file is extracted, and no identifying archive path is emitted.** Configuration is never copied into GitHub, reports or logs. It proceeds only if exactly one candidate satisfies all identity checks. The new JSON summary includes only counts and codes:

- `code_archive_nonius_signature_candidates` — candidates with verified NONIUSWTAX code signature;
- `code_archive_target_db_candidates` — signed candidates with expected DB name;
- `code_archive_target_url_candidates` — signed candidates with expected base URL;
- `code_archive_verified_identity_candidates` — candidates satisfying **all** identity checks;
- `code_archive_identity_method` and `code_archive_site_selection` — non-sensitive selection or refusal reason.

If there are zero verified identities, the archive may be an unrelated account backup or may lack an installed configuration. If multiple match, there are ambiguous application copies. Both situations **FAIL before Docker** and require a clean **site-specific full files backup** from Hostinger, not a manually guessed directory. A unique confirmed candidate still undergoes duplicate-path, required-file, signature and database baseline checks before any restore. The 839 nested archive files are not unpacked or inspected recursively.

**CI tested this exact structural pattern using only fictional data: 3 unscoped app roots, no matching folder path, 839 nested mock archive files and one local config matching both required identity markers — PASS. Two matching configs — DENIED. No matching config — DENIED.** This CI evidence does not prove which private historical TAR entry is the actual deployed site.

The owner can Fetch/Pull `release/production-go-no-go-plan` and rerun the same private one-command verification. If it reports `NO_VERIFIED_INSTALLED_IDENTITY`, `AMBIGUOUS_INSTALLED_IDENTITY` or `AMBIGUOUS_DUPLICATE_SITE_FILES`, stop and supply only the aggregate report. Do not send site archive file lists, configuration, SQL contents, or credentials. R1 approval, independently verified backup time consistency and all production approval gates remain **PENDING**.

### Windows PowerShell 5.1 Docker progress handling — 19 November 2026

Owner's correct SQL.gz passed preflight; the website root was uniquely identified and there was **one duplicate file entry outside the selected app**, flagged for independent archive-provenance review. The temporary MariaDB started and became healthy, but the initial import ended with console text `FAIL: Container nonius-private-recovery-inspector-run-... Creating`. Docker created its importer container and the script cleaned up all isolated containers, the network, and temporary volume.

**Diagnosis:** the original importer invoked `& docker @command *> $privateLog` under `$ErrorActionPreference='Stop'`. Native Docker Compose's ordinary stderr progress line can become a terminating `NativeCommandError` on the owner's **Windows PowerShell 5.1**, before an actual SQL import exit code is checked. This error **does not establish whether SQL succeeded or failed**. Do not mark R1 PASS from that console output.

**Fix:** `scripts/PrivateNativeOutput.ps1` now runs native Docker processes with a scoped nonterminating stderr policy, redirects import stdout/stderr **only to the owner's existing private folder outside the repository**, captures the **actual process exit code without a PowerShell pipeline**, and separately logs metadata-query/FK-query stderr while collecting only aggregate stdout. New privacy-safe JSON fields `sql_import_docker_exit_code`, `sql_metadata_docker_exit_code`, and `sql_fk_docker_exit_code` help identify the failing stage without revealing SQL statements or row values.

**Proof:** A dedicated **Windows PowerShell 5.1** CI job invokes the exact helper on a fictional native process emitting `Container ... Creating` on stderr and returning exit `0`, verifies aggregate stdout isolation, and separately simulates a genuine SQL error returning exit **7**. These checks ensure normal progress cannot bypass a real SQL failure. The existing Docker restore suite additionally uses fictional 21/177/14 databases and Hostinger-like archives.

**Next:** after CI is green, Fetch/Pull `release/production-go-no-go-plan`, then rerun the **same one local private restore command** against the original backups. No SQL/TAR re-download needed. If it still fails, share only the **reviewed** aggregate JSON. Do not display or upload `nonius-private-restore-logs/sql-import-private.log`, `sql-metadata-private.log`, or `sql-foreign-keys-private.log`—SQL import diagnostics can contain confidential literals. Genuine nonzero SQL import errors must be investigated locally, separately from Docker progress.

The file/DB backup time mismatch and unreviewed duplicate outside the app still need independent R1 approval even if the technical restore completes. **No actual production changes, migration 037, sanitization, or formal release authorization occur in this test.**

## 4. The single report and interpretation

The ignored local output is `readiness-results/private-backup-restore-summary.json` (no row contents, names, TINs, emails, passwords, account info or SQL statements).

- **`LOCAL_RESTORE_VERIFIED_R1_SIGNOFF_PENDING`**: import and structural/FK verification passed on the temporary copy. This is **technical restoration evidence only**; backup snapshot consistency, independent encrypted copy, real deployed source version, privacy handling and reviewer signatures remain pending.
- **`RESTORE_TEST_FAILED`**: do not proceed to private historical-data sanitization or upgrade. Use the local diagnostic log privately; send only the redacted summary error.
- Neither result changes your production website. Production GO is impossible until R0–R8 complete.

You may share **only the aggregate JSON summary after reviewing it**. Do not send the SQL backup, the site archive, private import log, `config.local.php`, screenshots of real transactions or real TINs. The final project release must use the recorded exact candidate and independently signed finance/security/DB approval.

## 5. Next gate after R1

Once a complete and independently recoverable backup is privately held, reconcile the **entire 177-column schema** plus nested JSON content against `docs/HISTORICAL_CLONE_PRIVACY_MATRIX.md`. Build a fail-closed sanitizer (or obtain institutional privacy authorization for a restricted sealed replica), independently verify no leakage and checked-hash provenance, and only then execute the **one coordinated historical application migration 037 + upgrade + full rollback rehearsal**.

This step alone does not permit actual database writes on Hostinger. R1 and production remain PENDING until authorized sign-off.
