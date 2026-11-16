# Project Owner — System and Governance Manual

The `project_owner` account has Division-level capabilities plus owner-only **System Settings**, application updates, read-only SQL console, gallery management and test-data reset. Protect this role with strong authentication, limited use and documented recovery procedures.

## Initial installation
1. Provision a HTTPS site and empty MySQL/MariaDB database with the needed CREATE/ALTER/TRIGGER rights for setup.
2. Install the trusted application source; never copy an operational `config.local.php` or database backups into GitHub.
3. Browse to `/install.php` when not already installed; enter secure base URL and DB credentials and create the Project Owner.
4. Verify application version, database migration status and integrity triggers, then configure Division profile, schools, suppliers, ATCs and source funds.
5. Create and securely onboard trained Division Administrators and School Users.

## Privileged account management
Create/edit privileged Division Administrators and school accounts within the authorized UI. Approve password resets only after identity verification. Use a separate daily-use account where practicable. Existing Project Owner account role/deactivation is protected by DB triggers when supported.

## Version updates
1. Take a tested off-site backup and schedule a change window.
2. Open **System Settings → Update** and review a trusted release ZIP with version notes and checksums.
3. Install only after review; the code stages the ZIP and attempts code/DB backup and rollback.
4. Verify release version, migration integrity, role permissions, reports and BIR 2307 outputs after update.
5. Never install a ZIP obtained from an untrusted source. The updater is a privileged remote executable deployment feature.

## SQL and gallery
Current SQL console is **read-only**, owner-only, and restricted to SELECT/SHOW/DESCRIBE/DESC/EXPLAIN. Read queries can still expose sensitive information. The login gallery can upload approved photographs; only use imagery cleared for public display. GD support is necessary.

## Critical caution: Reset test data
The reviewed `resetTestData` API uses SQL `TRUNCATE` on transactions, histories, pending items, counters, periods **and audit log**. Do not run on a production database. Prioritize removing/disabling it for production; confirmation text alone is not adequate protection.

## Backup, privacy and release governance
Keep credentials and SQL/ZIP backups outside public repositories, verify HTTP denies access to internal folders, configure off-site encrypted backups and recovery tests, scan source for secrets, assign a tax-code approval owner, and validate security/role boundaries and BIR outputs before broad release. Validate successful restore, not merely backup creation.

## Owner acceptance checklist
Verify roles, CSRF, tenancy, monthly locks, audit protections, tax golden cases, BIR PDFs, reconciliation, report exports, updater rollback and incident-response readiness.
