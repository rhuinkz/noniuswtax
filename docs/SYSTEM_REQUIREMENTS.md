# NON-IUS — System Requirements (v0.4.56)

## Confirmed release baseline
The source's `APP_VERSION` is **0.4.56** and the release manifest specifies **minimum PHP 8.2**. The bundled older first-upload guide refers to v0.3.0 and PHP 8.1 and is outdated. Prefer a security-supported PHP 8.4/8.5 build **after compatibility/regression tests**; review current vendor support at https://www.php.net/supported-versions.php.

## Server requirements

| Component | Requirement |
|---|---|
| PHP runtime | 8.2 minimum stated by current release manifest; current patched PHP 8.4/8.5 recommended after testing |
| PHP extensions | **pdo_mysql**, **mbstring**, **zip/ZipArchive**, **gd**, cryptographically secure random source |
| Database | MySQL/MariaDB with **InnoDB**, utf8mb4, foreign keys, indexes, migrations and **trigger creation** privileges |
| Web hosting | PHP-capable Apache/LiteSpeed with supported `.htaccess`, or Nginx configured with equivalent private-file denials |
| Security | HTTPS certificate, secure cookies, locked-down internal and storage paths, secret management, off-site backups |
| Browser | Current Chrome, Edge, Firefox or Safari with JavaScript/cookies and PDF viewing/printing |
| BIR PDF | Bundled `assets/bir2307-v1.pdf`; current `pdf-lib` 1.17.1 loaded via external CDN; internet needed for browser PDF generation |

## Suggested capacity-planning figures—not tested guarantees
A small pilot could begin with an environment equivalent to approximately 2 vCPUs, 2 GB RAM and 10 GB protected storage. Larger deployments may require 4+ vCPUs, 4–8 GB RAM, SSD storage and a separately tuned database. These are **starting estimates only**: the provided source has **not** been measured for 1,000 concurrent users. Validate PHP worker counts, DB connection pools, report queries, simultaneous saves, backup duration and peak traffic before rollout.

## Installation and deployment
1. Create a fresh empty database and application account.
2. Install files directly in the web root configured for the selected hostname, never copying historical `storage/` or `config.local.php`.
3. Visit `/install.php` via HTTPS, create the Project Owner, and confirm migrations/DB triggers.
4. Set Division details, school codes, ATCs, suppliers, funds, user accounts and open months.
5. Run security, tax, PDF and data-isolation acceptance tests before live operations.
6. Upgrade an existing deployment only through a tested, reviewed migration/update and rollback procedure; never run the fresh installer against a live database.

## Additional operational preflight
Check `pdo_mysql`, `mbstring`, `gd` and `ZipArchive`; confirm UTF8MB4 support, writable protected directories, DNS/TLS, timezone coherence (stored UTC versus Manila periods), disk available for backups, 30 MB update-upload budget and application-specific PHP memory/time limits. Verify with external HTTP requests that `config.local.php`, `storage/backups/*`, `lib/*` and `bin/*` cannot be downloaded.
