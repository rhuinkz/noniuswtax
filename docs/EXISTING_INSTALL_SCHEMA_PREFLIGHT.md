# NONIUSWTAX: Existing Installation — Private Schema-Only Preflight

**Stage: schema discovery, not real-data sanitization or production upgrade.**  
**Release candidate:** `v0.4.57-rc.1` (exact ZIP hash `e8d1fe062073f81d68bd0c2707951a0dcae1543a8d5c1aa0dd074a1e5ccb367a`). Production approval is **PENDING**.

## Why inspect structure first?

The published application schema includes personal identification and supplier records, JSON-encoded master data, transaction snapshots and history, audit trails, credential hashes, and SQL triggers. A generic SQL search-and-replace would break keys or destroy existing checked-transaction hash integrity. We must first compare the **actual deployed table/column/foreign-key/trigger structure** with the GitHub source. Only then can we create a field-by-field sanitization procedure that preserves relational integrity and explicitly states how any checked-record hashes are treated.

The tool in this branch runs **only against a SQL structure export stored locally**. It creates a dedicated Docker Compose project with MariaDB and a CLI importer, **no Apache/web container, no host port and an internal-only Docker network**. It refuses common data-bearing exports before import, checks that **every imported base table is empty**, emits structural metadata (no row values), and destroys the dedicated temporary Docker database on completion.

This is not a complete SQL parser or a substitute for carefully selecting *Structure only* in phpMyAdmin. The source file itself must contain **no real records**.

## 1. Export structure only from the existing Hostinger website

1. Log in to your **authorized Hostinger account**. In **Websites → Manage site → Databases → Management**, open the actual NONIUSWTAX database in **phpMyAdmin**. Confirm the database name **privately** from your installed application's settings, not from the GitHub demonstration credentials.
2. Select that database, then **Export → Custom → Format: SQL**. Select the structure for **all relevant tables**. **Uncheck Data** for every table. Include trigger definitions when supported. Disable options to create/switch databases (`CREATE DATABASE`, `USE`) if present. Do not export routines/definers requiring unrelated privileges.
3. Choose the plain/uncompressed `.sql` format and save the file to an **external, access-controlled folder outside Git**, for example:

   `C:\Users\Loy\Documents\NONIUSWTAX_PRIVATE\structure-only.sql`

   This is **not a backup of your records**, because it deliberately contains no transaction data.

The menu path and **Custom** export option follow Hostinger's [phpMyAdmin export guide](https://www.hostinger.com/support/4529011-how-to-export-a-database-with-phpmyadmin-in-hostinger/). phpMyAdmin documents exporting table **structure, data or both** [here](https://docs.phpmyadmin.net/en/latest/import_export.html). Never use the Quick export method for this privacy preflight, because Quick can include real rows.

## 2. Inspect that export offline on your Windows PC

In **GitHub Desktop**, fetch and check out `test/existing-install-schema-preflight` (a separate review branch; do **not merge or deploy**). With Docker Desktop running, open PowerShell in the NONIUSWTAX repository directory.

Run this command, substituting **your actual external schema-only file path**:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\Inspect-ExistingSchema.ps1 -SchemaSqlPath "C:\Users\Loy\Documents\NONIUSWTAX_PRIVATE\structure-only.sql"
```

*Optional:* If you independently downloaded a **private copy of the currently deployed PHP website files**, pass `-ExistingCodeRoot "C:\...\NONIUSWTAX_PRIVATE\site-files"`. The script reads only `lib/bootstrap.php` to identify the actual installed source version and SHA-256; it never copies credentials or source files into the test container. Do not provide the private site folder through chat.

### Expected result

```text
PASS: all base tables empty; no rows from the existing installation are present.
PASS: [number] tables, [number] columns, [number] triggers.
Safe structural report: ...\schema-preflight-results\existing-schema-report.json
```

The tool always disposes of **only its own** `nonius-existing-schema-preflight` Docker database volume and services, not the existing local app or Hostinger database. It does not connect to an actual production server.

If the export contains SQL INSERT statements, a table unexpectedly has data, or the schema does not resemble NONIUSWTAX, it **fails**. Re-export *Structure only* instead of modifying the guardrails.

## 3. Share only the structural report for mapping review

The generated **`schema-preflight-results/existing-schema-report.json`** contains database object names, column types, foreign keys, indexes, triggers, optional installed PHP version and schema-export digest. It does not include records, supplier names, account emails, TIN values, password hashes, transaction amounts, or actual historical migration rows.

Review it before sharing: your organization may have custom table or column names that it considers confidential. **Do not upload the original SQL export, full database dump, private installed code, `config.local.php`, login credentials or backups to GitHub, Google Drive or ChatGPT.**

Both `schema-preflight-results/` and `local-private/` are Git-ignored and Docker-image-ignored. The source must be outside the repository regardless.

## 3A. Local Docker + Playwright structural compatibility check (new)

**Owner's reported schema-only preflight (2026-11-18): PASS.** The separate local Docker structure import verified **zero rows in all tables**, inventoried **21 tables, 177 columns, and 14 triggers**, generated the ignored `schema-preflight-results/existing-schema-report.json`, and removed its disposable database. These totals are from the console report, not yet a detailed object-by-object comparison; the generated JSON itself was not provided.

The next step does **not** need another Hostinger export, and uses **the generated JSON only**. Fetch/Pull the updated `test/existing-install-schema-preflight` branch and run in the repository folder with Docker Desktop open:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\Test-ExistingSchemaDockerPlaywright.ps1
```

This creates a separate Docker Compose project `nonius-existing-compatibility`, with an isolated MariaDB and local Apache/PHP app, and a Chromium Playwright container. It mounts **only** the metadata report **read-only**, never your original `.sql` export or real records, and uses an **internal Docker network**. The already-established 12 browser tests re-create purely fictional schools, owners, suppliers, transactions, month-locks, PDF certificates and a 10,000-transaction test sample in a disposable fresh-installation database. A new structural comparator then measures the fresh application's tables, columns, column types, foreign keys, indexes, and triggers against the existing installed schema report.

Reports are generated **only locally** under `existing-compat-results/`:

- `compatibility-summary.json`: Playwright pass/fail count, missing/extra/changed structural item counts, and an explicit `production_approval: PENDING`.
- `existing-schema-comparison.json`: detailed metadata-only differences grouped by table, column, foreign key, index, and trigger.
- `results.json` and `html/index.html`: the synthetic Playwright suite's normal evidence.

If it reports `PLAYWRIGHT_PASS_SCHEMA_DRIFT_REVIEW_REQUIRED` and exits with code **2**, this is an **expected review outcome** when the existing deployment predates a migration or contains a custom object. It is **not** approval to force migrations or override an installed schema. For example, the reported existing **14 triggers** should be compared with the freshly tested current source before concluding which trigger changed. Script exit **1** indicates a technical test failure, and exit **0** indicates structurally matching metadata—not legal, migration, or production approval.

You can share **only** `compatibility-summary.json` and `existing-schema-comparison.json` after checking structural names for confidentiality. **Never upload source SQL, genuine transaction data, credentials or database backups.**

This check measures installed structural **differences**, not an upgrade of its historical records. Full data sanitization, protected hash handling and actual installed-state rollback remain separate required gates.

## 3B. Installed schema drift result — 18 November 2026

The owner ran `scripts/Test-ExistingSchemaDockerPlaywright.ps1` locally and provided the console log. The **12/12 Chromium Playwright tests PASSED**, including authentication/roles, Form 2307 generation, integrity guards, and 10,000 synthetic transactions. The current source and the **real site's structure-only report** each contain **21 tables and 177 columns**. The current source has **15 triggers**, while the installed structure-only report has **14**. Comparison result: `DRIFT_REVIEW_REQUIRED` with **one missing structure item**, zero extras, and zero changed column definitions.

The *only* discrepancy reported by the console log is that the installed schema lacks mandatory **`protect_checked_hash_period_update`**, a `BEFORE UPDATE` trigger on `transactions`. Its source is migration **`037_checked_hash_period_guard`** (mandatory in `lib/migrations.php`). It blocks changing `checked_snapshot_hash` while the corresponding month is not open/reopened.

**Important limitation:** The schema-only export provides no `schema_migrations` data, so the installed site's migration **037 status remains unknown**. The migration engine skips IDs whose status is already `applied` or `skipped`. Consequently an erroneously recorded `applied` migration with a missing trigger might not self-repair. We must read the *single migration status*, without any production data rows, before staging deployment decisions. **Do not create/delete the trigger manually on Hostinger or change production migration history.**

The test branch now includes an **additional isolated local migration rehearsal** in the same one-command Docker + Playwright runner. After a fresh synthetic installation, it temporarily removes exactly trigger 037 and its migration-history record from the disposable database, launches a new PHP bootstrap to execute the **normal migration runner**, and checks that the trigger is restored, preexisting synthetic transactions/audit/history and checked hashes are unchanged, and a closed-period hash change is refused. Its result is `existing-compat-results/migration037-rehearsal.json`. It must be executed locally; **do not mark this new rehearsal PASS based on the earlier 12-test run**.

A read-only query that the authorized administrator may run separately against the existing database to establish migration state is:

```sql
SELECT id, status
FROM schema_migrations
WHERE id = '037_checked_hash_period_guard';
```

Only the result of this single migration-status query should be shared; do not export the `schema_migrations` table or any real records. If no row exists, report **NOT PRESENT**. If the status is `applied` despite the missing trigger, flag it as a separate integrity defect requiring an approved recovery plan and staged repair. The trigger count discrepancy alone is not approval to run SQL or modify the live site.

## 4. Next gated technical work (not yet authorized)

Once the structural report is reviewed:

- Map every real database column and every JSON-bearing field to an explicit **retain / tokenize / clear / recalculate / forbid** policy. Detect unknown columns and fail closed.
- Design sanitization that preserves referential integrity and does not silently make checked transaction hashes invalid. Prefer controlled synthetic historical replacements when cryptographic provenance cannot be preserved.
- Produce a private local sanitized clone **with no true identity, account secret, supplier TIN or financial narrative**. Validate it with independent negative leakage checks *before* starting any web service.
- Rehearse the exact `v0.4.57-rc.1` ZIP against that sanitized historical clone, including full backup/restore and rollback from the actual installed version.
- Archive the private evidence and obtain BIR/Form 2307, security, performance, recovery and organizational approvals. **Do not deploy to Hostinger.**

### Distinct release gates

| Gate | Status |
|---|---|
| Full disposable synthetic tests, upgrade and rollback | PASS (already recorded; distinct from this task) |
| Existing deployed database **schema-only** inventory | 21 tables, 177 columns, 14 triggers; local zero-row preflight PASS (console log) |
| Local Docker + Playwright installed-schema drift comparison | 12/12 PASS; 21 tables/177 columns match; missing one mandatory migration 037 trigger — REVIEW REQUIRED |
| Existing historical data sanitization and realistic clone verification | NOT STARTED |
| Real-hosting security, authorized BIR/finance and release approval | PENDING |

No production database was read or modified by preparing this preflight package.
