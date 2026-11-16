# NONIUSWTAX v0.4.57-rc.1 — Formal Release Candidate Dossier

**Status: RELEASE CANDIDATE — NOT APPROVED FOR PRODUCTION.** Date opened: **16 November 2026**.

## 1. Release identity and baseline

| Field | Value |
|---|---|
| Product | Non-IUS Withholding Tax Monitoring |
| Current application version | `0.4.56` |
| Proposed candidate version | `0.4.57-rc.1` (staging-only) |
| Exact tested source | `adf3fb4bdce027f65c2a18de2ca4d94e8424e35f` |
| Source of truth | `rhuinkz/noniuswtax` |
| Existing review PR | #8 — local integrated release-test package (draft) |
| Preparation branch | `release/0.4.57-rc1-preparation` |
| Candidate output | Local `release-out/noniuswtax-0.4.57-rc.1-STAGING-ONLY-adf3fb4.zip` (generated on owner's PC, not committed) |
| Deployment state | **NOT DEPLOYED; NOT APPROVED** |

The complete local automated Docker suite was reported **PASS** for the pinned commit: **12/12 Playwright tests**, 13 synthetic arithmetic cases, 10,003 transaction records restored, 15 database triggers restored and 10,000-record/30-request local probe (p95 4,011 ms). See `docs/RELEASE_ACCEPTANCE_GATE.md`.

This evidence covers the *tested source commit*. The candidate builder modifies **only** the runtime version declaration and changelog in its output artifact, then generates SHA-256 checksums and an in-app updater manifest. **The candidate ZIP and updater installation path have not yet been independently tested.** Further staging and finance review are required before approval.

## 2. Build and verify the candidate on Windows

Use **GitHub Desktop** to Fetch/Pull and check out `release/0.4.57-rc1-preparation`. In the repository root, run:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\Build-ReleaseCandidate.ps1
```

The builder reads **only** pinned commit `adf3fb4...` using `git archive` and an explicit allowlist of runtime PHP, CSS, JS, the BIR PDF template, and `.htaccess`. It does **not** package tests, documentation, `storage`, `config.local.php`, `.env`, SQL dumps, recovery secrets, screenshots or Git metadata.

It locally creates:
- `release-out/noniuswtax-0.4.57-rc.1-STAGING-ONLY-adf3fb4.zip`: updater-compatible candidate; **DO NOT UPLOAD TO PRODUCTION**.
- Adjacent `.zip.sha256`: independently verifiable SHA-256 of the ZIP.
- `release-out/release-provenance.json`: source commit, version, checksum, and explicit `NOT_APPROVED_FOR_PRODUCTION` status.

Verify the package again without modifying it:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\Verify-ReleaseCandidate.ps1 -PackagePath .\release-out\noniuswtax-0.4.57-rc.1-STAGING-ONLY-adf3fb4.zip
```

**Notes:** This is not a cryptographically signed release. SHA-256 detects changes but does not establish trusted identity by itself. The recorded commit and locally generated ZIP must be reviewed independently; a release signer and approval procedure remain mandatory.

## 2A. Synthetic local updater and rollback rehearsal (additional gate)

After building/verifying the exact staging ZIP, a **separate disposable local Docker rehearsal** may be run from the same Windows repository:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\Test-StagingCandidate.ps1
```

This uses Docker Compose project **`nonius-rc-staging`**, a dedicated synthetic DB, and a named rollback-backup volume; it cannot connect to Hostinger or an actual production database. It runs the complete 12 Playwright tests to populate 10,003 synthetic transactions, uploads the exact SHA-256-pinned ZIP to the application's real two-stage updater, exercises the explicit review/confirmation flow, checks that files, transactions and triggers survive the update, then restores the updater's **own** pre-update code and SQL snapshot and rechecks counts, checked snapshot hash, audit history, login and version. It removes only its staging-specific Docker volumes afterward.

Outputs are saved under `staging-results/` (separate from `test-results/`), including `staging-summary.json`, `staging-upgrade.json`, `staging-rollback.json`, and Playwright reports. The script fails closed if the candidate ZIP hash is wrong and reports partial/failed stages.

**Caveat:** This rehearses the updater on a newly installed application filled with synthetic data. It does not establish compatibility with a previously deployed real-world database or its historical schema. An independently sanitized clone of the **actual installed** database and a recovery drill are still **required for production approval**. This local rehearsal script was newly added on the release preparation branch; **do not mark it PASS before its first actual Docker run.**

### Local staging rehearsal — initial diagnostic failure (2026-11-16)

The owner ran `Test-StagingCandidate.ps1` against the exact approved-for-staging candidate hash `e8d1fe062073f81d68bd0c2707951a0dcae1543a8d5c1aa0dd074a1e5ccb367a`. All **12/12 synthetic Playwright tests passed**, followed by successful update ZIP review and installer API response with trigger backup metadata. The rehearsal then stopped at the test assertion **"Candidate runtime did not expose updated version"** while inspecting the owner dashboard CSS link. The rollback stage was **not run**.

This first attempt is a **FAIL for the integrated staging rehearsal**, not proof that the actual code install or database integrity failed. The candidate script now also inspects the anonymous login page's current version and the authenticated `window.NONIUS.version` value, diagnoses unexpected redirection and permits a required post-update re-login. A new run is needed before the candidate upgrade/rollback can be considered verified. The candidate ZIP and checksum remain **unchanged**.

### Follow-up staging failure: stale v0.4.56 seen on an anonymous page

The next owner-provided Docker log again shows **12/12 Playwright tests passed** and a successful updater API response, but an independent unauthenticated HTTP request to `/login.php` returned `200` and the old asset version **`assets/style.css?v=0.4.56`**. Thus the issue cannot be attributed solely to an authenticated dashboard redirect. The entire isolated rehearsal remains **FAIL**, with rollback untested.

The latest staging diagnostic now (1) probes the anonymous PHP-rendered version for up to approximately 30 seconds with separate requests and cache-busting query strings, preserving each response; and (2) uses the **disposable container only** to read the installed `lib/bootstrap.php` directly and compare it with `nonius-update.json`. If the new version is present on disk but not visible immediately, PHP opcode/cache timing is a possible explanation. If the file remains old on disk, the updater or deployment filesystem must be repaired before considering release. Both cases still block approval until resolved and full rollback has passed. The ZIP SHA-256 is unchanged.

## 3. Scope of security changes

- Removed the destructive `resetTestData` branch from reviewed source; **previously deployed versions may still have it** (Issue #3).
- Strengthened read-only owner SQL diagnostics; safer restriction is not a substitute for least-privilege DB accounts.
- Added closed-period, hash-only update protection (mandatory migration 037) and Apache denial rules for internal/private files.
- Added synthetic local installation, permission, migration, month-lock, Form 2307 and backup/restore tests with consolidated evidence.
- No authorized revision of legal VAT/EWT/ATC rules has been made; current computations need independent BIR/finance approval.
- Client-side Form 2307 still depends on the website's pdf-lib CDN availability in the application runtime. The test package injects a locally bundled copy for offline testing; this candidate **does not convert the deployed app to offline PDF generation**.

## 4. Approval evidence, all required

| Gate | Evidence required | Status |
|---|---|---|
| Automated source and synthetic integration | 2026-11-16 full Docker evidence tied to pinned commit | **PASS** |
| Generated candidate ZIP | Local build output, SHA-256 validation, archive inventory review | **PENDING BUILD** |
| Candidate upgrade/rollback | Upgrade an existing `0.4.56` *sanitized staging copy* using exact candidate ZIP; verify migrations, session/roles, records, backups and rollback | **PENDING** |
| Financial rules | Approved ATC/rate/effective-date matrix and separately computed centavo fixtures | **PENDING** |
| BIR 2307 | Authorized reviewer signs off generated/printed form and field alignment for representative cases | **PENDING** |
| Live hosting security | External HTTP 403/404 checks, PHP permissions, HTTPS, secrets, WAF, logging | **PENDING** |
| Recovery | Independent encrypted off-site backup and full restore of sanitized realistic DB, including triggers, in agreed recovery time | **PENDING** |
| Capacity | True independent account/session load and agreed p95/concurrency SLO | **PENDING** |
| Privacy and release identity | Public-history secret scan; reviewer, signing authority, release artifact attestation | **PENDING** |
| Final authorization | Recorded written sign-off and go/no-go decision | **PENDING** |

Open blockers: GitHub Issues #3–#7. No installation or database migration on the live Hostinger environment is authorized by these documents.

## 5. Official reference material

- BIR Forms portal — [Form 2307, Certificate of Creditable Tax Withheld at Source](https://www.bir.gov.ph/bir-forms?tab=Certificates)
- BIR's hosted [Form 2307 PDF (January 2018 ENCS)](https://bir-cdn.bir.gov.ph/local/pdf/2307%20Jan%202018%20ENCS%20v3.pdf); the finance reviewer must confirm that this is the correct/current form for the specific transaction period and requirements.
- Hostinger — [Download backups](https://www.hostinger.com/support/5981435-how-to-download-backups-at-hostinger/), [Upload backups](https://www.hostinger.com/support/1884234-how-to-upload-backups-at-hostinger/).

See `docs/FINANCE_BIR_SIGNOFF.md`, `docs/PRODUCTION_DEPLOYMENT_RUNBOOK.md`, and `docs/RELEASE_APPROVAL_RECORD.md`. All sign-off templates are intentionally blank and do not imply approval.
