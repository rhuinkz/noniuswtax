# NONIUSWTAX — One Big Local Release Acceptance Package

**One command, one consolidated report; no live Hostinger installation.**

Current technical release candidate: **v0.4.57-rc.1**, pinned source commit `adf3fb4bdce027f65c2a18de2ca4d94e8424e35f`, ZIP SHA-256 `e8d1fe062073f81d68bd0c2707951a0dcae1543a8d5c1aa0dd074a1e5ccb367a`. This exact artifact remains **UNAPPROVED**.

## Execute once on Windows

In GitHub Desktop, stay on `test/existing-install-schema-preflight`, then **Fetch origin → Pull origin**. Ensure Docker Desktop is running. From the NONIUSWTAX repository root:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\Run-FullAcceptance.ps1
```

That is the **only command needed** to perform this full **synthetic/local automated** acceptance check.

The runner reuses the existing local ignored `schema-preflight-results/existing-schema-report.json`. It **never reads** the private source SQL, database backup, `config.local.php`, supplier TINs or other transaction rows. It checks for the pinned staging-only ZIP in `release-out/` and verifies the exact SHA-256 and all 27 runtime file hashes before invoking any Docker suites; if the ZIP is absent, it generates it from the locally available exact tested Git commit and verifies it.

## Coverage, all under one invocation

| Internal suite | Verification |
|---|---|
| Full source/release tests | PHP/JavaScript lint, synthetic tax golden cases, security, 12 Chromium Playwright flows, 2307 PDF/PNG rendering, 10k synthetic transaction sample, 30-request informational load probe, full DB backup and fresh restore |
| Exact release-candidate staging | Real owner review → install of the verified v0.4.57-rc.1 ZIP on a **throwaway** application; backup of application files and SQL including triggers; rollback to prior source and DB; independent login, version, transaction, audit and checksum verification |
| Existing-site structural gap + migration | Compare the existing **zero-row structure-only** metadata report with the current fresh schema; simulate the one missing mandatory trigger and migration `037_checked_hash_period_guard` using a disposable DB with fictional records; confirm the normal PHP migration engine recreates the trigger and blocks hash tampering in closed months |

Each suite uses its **own isolated Docker Compose project and disposable MariaDB**. The orchestration runs each complete suite once and automatically removes only its own test containers and volumes. Browser tests run more than once internally because independent backup and staging scenarios must start from separate clean states; you do not need to run separate commands.

## Your one consolidated result

After the command finishes:

- Open **`acceptance-results/acceptance-report.html`** for the single human-readable report.
- Share **`acceptance-results/acceptance-summary.json`** for a high-level technical review.
- Detailed diagnostic logs are under `acceptance-results/logs/` and the pre-existing `test-results/`, `staging-results/`, and `existing-compat-results/` folders. **Only** use those when the master result identifies a failure.
- Generated test reports are ignored by Git and excluded from web and Docker build contexts. Never commit/upload the original SQL export, private backups, or deployed config/secrets.

### Return codes and results

| Exit | Meaning | Next action |
|---|---|---|
| `0` | All synthetic technical suites pass and existing schema **matches** the current source | Still requires independent production approval |
| `2` | All synthetic technical suites pass, but structural drift needs review. **Expected currently:** one pending migration 037 trigger | Retain report; review installed migration on a controlled historical clone; do **not** modify Hostinger |
| `1` | A technical check, preflight, ZIP verification, updater, backup/rollback, or migration rehearsal failed | Read the single report for failing stage; consult detailed logs only for that stage |

**Note on live migration status:** The authorized owner ran `SELECT id,status FROM schema_migrations WHERE id='037_checked_hash_period_guard'` against the installed database on **18 November 2026** and reported **zero rows**. This is separate **read-only human evidence**, not a live query or adjustment made by this test package. The missing trigger is consistent with a pending migration, but the actual installed database has not been upgraded.

### GitHub Actions versus your Windows ZIP

The GitHub Linux workflow executes this comprehensive package in a restricted **CI-only cross-platform ZIP mode**. Different .NET ZIP implementations can compress the same pinned source files into different archive bytes; GitHub's Linux CI therefore independently checks the source commit and all manifest file checksums but **does not claim to test the exact Windows candidate ZIP SHA-256**. The default Windows command never enables this mode and **requires** the original Windows ZIP SHA-256 `e8d1fe062073f81d68bd0c2707951a0dcae1543a8d5c1aa0dd074a1e5ccb367a`. Only the Linux GitHub Actions environment can request the special test flag; it is not intended for local use or distribution.

## Actual Windows owner execution — PASS, migration review pending (18 November 2026)

The owner supplied the PowerShell console log and `acceptance-results/acceptance-summary.json`. They confirm that the **one-command Windows acceptance suite completed** at `2026-11-18T04:17:07.3270810Z`, using the **actual verified Windows release candidate archive** (not the Linux CI-repacked ZIP):

- Exact pinned source: `adf3fb4bdce027f65c2a18de2ca4d94e8424e35f`.
- Exact candidate ZIP SHA-256: `e8d1fe062073f81d68bd0c2707951a0dcae1543a8d5c1aa0dd074a1e5ccb367a`, `exact_windows_zip_tested=true`, `ci_cross_platform_zip_repacked=false`.
- Candidate package and all 27 file hashes: **PASS**.
- Full PHP/source + tax arithmetic golden cases + rendered Form 2307 + concurrent 10,000 synthetic records + independent SQL backup/restore: **PASS**, **12/12** Playwright.
- Exact ZIP review → in-app install → full application-code and SQL restoration with independent post-rollback checks: **PASS**, **12/12** Playwright.
- Real existing-site **schema-only** 21-table metadata comparison plus simulated legacy-migration recovery: **PASS**, **12/12** Playwright. Normal bootstrap restores migration 037 and rejected closed-month hash tampering in the **disposable** database.
- The existing schema report still has **14 triggers** vs. 15 freshly installed; the **only identified missing structure** is `protect_checked_hash_period_update` on `transactions`. No extra objects or changed columns reported. Authorized owner separately confirmed via read-only phpMyAdmin query that live migration `037_checked_hash_period_guard` has **no row**.
- Master outcome: **`AUTOMATED_PASS_PENDING_MIGRATION_037_REVIEW`**, exit code **2**; preflight errors **none**. This is a **successful automated package with one known installed-schema review item**, not a failed Playwright test.

**Limit:** the local test imports **zero** real financial records, does **not** apply migration 037 on Hostinger, and does **not** test the upgrade/rollback of an independently sanitized copy of the historical deployed DB. Tax/BIR/Form 2307 legal approval, actual hosting/security review, realistic concurrent capacity and all release-owner sign-offs remain pending. **Production status: `NO_GO_PENDING_MANUAL_APPROVAL`.** The report is owner-held evidence; do not commit it with identifying data.

## Limits and release governance

The one-command package tests **fictional/local records**, not the historical installed business records. It does not certify correct BIR treatment, current 2307 layout, legal rates, live hosting security, real 1,000-user concurrency, or safe migration of personal data. The previously completed synthetic candidate rollback and the new structural check are useful technical evidence, but a **sanitized clone of the actual installed database** and its full rollback rehearsal remain separate approval requirements.

The single report explicitly shows **`production_release: NO_GO_PENDING_MANUAL_APPROVAL`** regardless of automated outcome. Neither PR #8, #9 nor #11 is authorized for production merge or deployment by this package.
