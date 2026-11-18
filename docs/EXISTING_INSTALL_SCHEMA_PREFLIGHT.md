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
| Existing deployed database **schema-only** mapping | PENDING user's safe structural export |
| Existing historical data sanitization and realistic clone verification | NOT STARTED |
| Real-hosting security, authorized BIR/finance and release approval | PENDING |

No production database was read or modified by preparing this preflight package.
