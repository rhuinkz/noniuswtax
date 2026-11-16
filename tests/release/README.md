# NONIUSWTAX — One-Command Local Release Test Package

This package tests the current GitHub source in a fresh, throwaway PHP 8.4 / Apache / MariaDB / Playwright environment. It is deliberately **one integrated run**, not separate manual tests. It uses fictional data and needs no cloud testing service.

## Run the entire package with ONE command

**Windows PowerShell**, from the cloned NONIUSWTAX repository root:

    powershell -ExecutionPolicy Bypass -File .\tests\release\run-all.ps1

**Linux / WSL2 with open-source Docker Engine + Compose:**

    bash tests/release/run-all.sh

The scripts build the images, start a disposable database and test app, run PHP/JavaScript static tests, install from zero, run Playwright and database checks, save HTML and JSON reports, and delete the test database/container volume even if the suite fails.

**Safety:** Do not run against production data. Docker ignores `config.local.php`, `.env`, SQL dumps, backups and runtime storage in the build context. The MariaDB service exposes no TCP port to the host. The test website binds only to `127.0.0.1:8877`. The scripts clean only the Compose project named `nonius-release-verification`.

## Automated coverage

| Category | Checks |
|---|---|
| Source | PHP/JavaScript syntax, calculation smoke, removed destructive reset action, SQL console guard |
| Installation | Empty schema install, installer locks after setup, owner login, bad password rejection |
| Database | Migration and trigger inventory, synthetic insert, monthly locking, reopen behavior |
| Security | CSRF rejection, unsafe owner SQL denied, internal URLs blocked, append-only audit and transaction deletion |
| School isolation | School A cannot read School B transaction, edit School B profile or use owner SQL console |
| PDF | Bundled BIR 2307 template has PDF signature and HTTP access, not a visual approval |
| Browser/concurrency | School draft, submit, frozen certificate, Division check; 15 simultaneous API reads with reported p95; not a capacity benchmark |

Spec: `tests/release/specs/full-release.spec.js`.

## Consolidated results

- HTML report: `test-results/html/index.html`
- JSON report: `test-results/results.json`
- Failure screenshots/videos/traces: `test-results/artifacts/`
- Process exit code 0: automated suite passed. Nonzero: suite or Docker setup failed.

Reports contain only synthetic test identities and are ignored by Git. The local database volume is deleted after every run, so each run is independent and reproducible.

## Not covered by automation: release approval requirements

Even a green automated test **does not approve a production release**. The separate required gates are: independent BIR rate/ATC/VAT/EWT/rounding review; generated Form 2307 visual/printed validation; sanitized clone upgrade, rollback and backup restore; Hostinger-specific TLS/private-file/permissions checks; actual target concurrency and load; and an approved, versioned, signed/hash-verified release package. See `docs/RELEASE_ACCEPTANCE_GATE.md`.

**Offline caveat:** the core browser test blocks external CDN requests to remain testable offline. The live app's pdf-lib dependency still comes from a CDN. Test the actual generation and consider bundling this dependency before production.

**Cost/licensing:** the suite itself requires no cloud account or per-run charge. Downloading public Docker images and npm packages requires Internet/data and disk space. Docker Desktop free use depends on license category: its current terms list some educational/personal/open-source use as free but government-entity use may require a paid subscription. Verify the actual institutional license at https://docs.docker.com/subscription-billing/desktop-license/ . The open-source Docker Engine in Linux/WSL2 is an alternative without Docker Desktop.

## Troubleshooting

- Start Docker Desktop (or the Linux Docker Engine) before running; ensure Linux containers are enabled.
- If port 8877 is occupied, stop the conflicting local service or adjust only the bound host port.
- Do not weaken failed integrity assertions. Review the printed logs and `test-results/html/index.html` for details.
- The suite has been committed to GitHub but has **not yet been executed on the target Windows Docker host**. The first actual run can reveal compatibility issues; keep this branch in review until a local green report exists.
