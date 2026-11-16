# NON-IUS Withholding Tax Monitoring

**Reviewed baseline: v0.4.56** · PHP + MariaDB/MySQL · DepEd school/Division withholding tax transaction management.

NON-IUS supports school-originated and Division-originated supplier transactions, tax calculation and ATC/source-of-fund configuration, submission and Division checking, controlled corrections and cancellation, BIR Form 2307 PDF preparation, BIR encoding/reconciliation tracking, monthly period controls, reporting and audit history.

## Manuals and review documents

- [Project brief and system narrative](docs/PROJECT_BRIEF.md)
- [Role/feature permission matrix](docs/ROLE_MATRIX.md)
- [School User manual](docs/roles/SCHOOL_USER.md)
- [Division Administrator manual](docs/roles/DIVISION_ADMINISTRATOR.md)
- [Project Owner manual](docs/roles/PROJECT_OWNER.md)
- [System requirements](docs/SYSTEM_REQUIREMENTS.md)
- [Workflows and database](docs/WORKFLOWS_AND_DATA.md)
- [Technical findings and recommendations](docs/TECHNICAL_REVIEW.md)
- [Test/acceptance plan](docs/TEST_PLAN.md)
- [Safe GitHub publication guide](docs/SAFE_PUBLICATION.md)

## Roles

The code implements exactly **three login roles**: **Project Owner**, **Division Administrator**, and **School User**. School User records are limited to their own school; Division Administrators manage cross-school review. Owner-only settings include application updates and database diagnostics.

## Hosting requirements

The v0.4.56 manifest specifies **PHP 8.2 minimum**. PHP 8.4/8.5 are preferred for newly deployed systems after compatibility testing. Required features include **PDO MySQL, mbstring, ZIP, GD, MySQL/MariaDB (InnoDB, foreign keys and triggers), HTTPS**, and an up-to-date browser. Browser-side BIR Form 2307 generation currently uses **pdf-lib 1.17.1 via CDN**.

## Public repository safety

**This branch introduces documentation and review, not the uploaded live database or runtime ZIP.** The upload contained `config.local.php`, SQL and backup archives, account information, TINs, financial transactions and historical records. Such data **must not** go into GitHub. A separately prepared sanitized source archive and complete-length manuals are available for owner validation before any additional source import.

## Review limitations

Local PHP and JavaScript lint, the three-file update manifest hashes, and a small calculation smoke test passed. **Not yet performed:** deployed HTTP/security tests, live database integration, migration/restore test, peak concurrency load test or finance/legal certification of tax computations and official BIR certificate outputs. Review the [risk register](docs/TECHNICAL_REVIEW.md) before production deployment.


## Pending review-branch security fixes (not a production release)
The source-review branch adds migration `037_checked_hash_period_guard` (closed-month certificate seal protection), a conservative read-only owner SQL diagnostic guard, stricter Apache file restrictions and additional CI checks. **Do not deploy or feed this GitHub branch into the in-app updater** as an ordinary release; the v0.4.56 manifest reflects the original release, and staging DB tests/tax approval are still pending.


## One-command local release verification (Docker + Playwright)
Use the disposable [Release Test Package](tests/release/README.md) to test this source with **one Windows PowerShell command**. It creates synthetic data, runs PHP/JS, database trigger, authorization and browser checks, exports reports, and removes its test database. A passing report **does not** authorize production deployment; see [Release Acceptance Gate](docs/RELEASE_ACCEPTANCE_GATE.md).
