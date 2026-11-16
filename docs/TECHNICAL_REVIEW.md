# Static Technical Review and Risk Register

**Reviewed:** 16 November 2026 · uploaded v0.4.56 PHP ZIP and 21-table SQL dump. The code shows server-side role checks, school-scoped transactions, CSRF protection, PDO parameterization, password hashing, login throttling, versioned snapshots, checked hashes, DB triggers and audit history. This is a **static review**, not a production penetration test or financial/legal approval.

| ID | Priority | Finding | Corrective action |
|---|---|---|---|
| R01 | **Critical** | Source ZIP includes local DB config and many SQL/code backups; SQL has account emails, bcrypt hashes, TINs, transaction and audit records | Do not publish uploaded archives; sanitize, scan, use synthetic data and rotate credentials if ever exposed |
| R02 | **Critical** | Owner-only `resetTestData` in `api.php` truncates tax transaction tables, month states, history **and audit** | Remove or hard-disable in production; use isolated testing database |
| R03 | **High** | `config.local.php` and `storage/backups` are inside web root and rely on `.htaccess` access denial | Verify external HTTP 403; enforce equivalent Nginx policy; move secrets/backups outside web root where feasible |
| R04 | **High** | No pre-existing integration/unit/security test suite shipped | Add DB-backed permission, workflow, tax, PDF and restore tests; run CI |
| R05 | **High** | VAT-registered base is unconditionally divided by **1.12** in current calculation logic | Independently validate all tax rules/rates and effective dates; gain finance signoff |
| R06 | **High** | Privileged owner-only update ZIP can deploy executable PHP; SHA checks check payload but no separate signed release provenance policy was identified | Release-signing/provenance approvals and tested rollback |
| R07 | **Medium** | BIR PDF browser generation depends on external pdf-lib CDN | Self-host/pin vetted library; validate offline/fallback and print output |
| R08 | **Medium** | Legacy installation guide gives outdated PHP/version requirements | Use version-specific documentation and preflight |
| R09 | **Medium** | Some report paths cap previews at 5,000 rows and use different query strategies | Test summary totals, CSV vs previews and heavy filters with large data |
| R10 | **Medium** | Login throttle keys on identifier plus IP; account-level distributed guessing may escape per-key quota | Add account-level, per-IP and monitoring controls |
| R11 | **Medium** | Critical financial rounding uses float-based peso intermediate values | Consider integer/decimal arithmetic with independently verified golden cases |
| R12 | **Medium** | DB period-update trigger appears to omit `checked_snapshot_hash` from its field equality list | Inspect and test closed-period hash-only mutation; repair if confirmed |
| R13 | **Medium** | No committed app preflight and performance/load suite | Add checks for extensions, indexes, triggers, HTTP controls and peak concurrency |
| R14 | **Low** | Obsolete instructions suggest writable SQL console, but current implementation is read-only | Retire outdated documents |
| R15 | **Low** | Original runtime package contains Hostinger `default.php` placeholder | Keep it out of curated source |

## Review validation
- PHP syntax checks passed on inspected source files.
- `nonius-update.json` file hashes matched the three named release payload files.
- JavaScript syntax checks passed.
- A small deterministic `calculate_tax` mechanical smoke test passed in the locally prepared sanitized source bundle.
- **Not verified:** live login and tenant isolation, HTTP restrictions, live database triggers, migrations/restore, concurrency, real-world tax compliance, certificate PDF visual output.

## Priorities before go-live
1. Exclude private archives/data from GitHub; disable production test reset.
2. Validate site-level access restrictions and restore a complete backup to an isolated database.
3. Obtain accounting/tax approval and add tests across tax scenarios.
4. Test every role and direct-API denial path, period integrity, checked-edit and cancellation history.
5. Establish signed update policy, controlled change approvals, monitoring, retention and recovery.


### Additional GitHub review-branch work (not deployed)
- Added `037_checked_hash_period_guard` to reject hash-only edits when the transaction's month is closed; **not database-tested yet**.
- Owner-only SQL diagnostics now use a stricter query guard and read-only DB transaction. This does not replace a least-privilege database account.
- Apache rules explicitly deny web access to Git internals, tests, documentation, and `config.*.php`/environment files. Real HTTP denial tests are still necessary.
- CI now runs security/policy and SQL diagnostic tests, including checks that confidential backup and configuration files are not Git-tracked.
- The destructive reset feature was removed from this sanitized branch, **not from a currently deployed instance**.
