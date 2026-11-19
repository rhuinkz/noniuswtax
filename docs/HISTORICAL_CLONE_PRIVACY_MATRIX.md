# NONIUSWTAX — Historical Database Clone Privacy and Integrity Matrix

**Status:** SKELETON ONLY, REVIEW REQUIRED. Not a sanitizer and never an instruction to upload actual financial data. Applies to an authorized encrypted copy of the previously installed application's database, kept in a private local/institutional environment.

The current source has 21 tables, including `schema_migrations`. The owner's installed schema-only preflight reported **21 tables, 177 columns, 14 triggers**. This inventory is based on checked-in migrations but **must be reconciled to the exact installed JSON metadata**, because migrations can add columns and constraints. Unknown columns require a stop decision.

## Table-by-table preliminary handling decisions

| Logical table | Risks / examples | Initial privacy treatment | Structural/integrity requirement |
|---|---|---|---|
| `schools` | School names, address, TIN/contact inside JSON `data` | Tokenize identity and recursive JSON values | Preserve synthetic school IDs and FKs |
| `members` | Email/user ID/password hash/roles, per-school access | Replace all user identities and credentials; synthetic test-only accounts | Preserve role types, school memberships and unique keys |
| `division` | Organization, signatories, TINs in `data` | Synthetic agency profile and JSON scrub | Preserve single/dynamic division references |
| `atcs` | Tax code and rates/effective dates | Keep **approved public** ATC/rate/date codes; scrub free text | Preserve tax scenarios for independent finance review |
| `suppliers` | Supplier names, TINs, addresses and `data` | Collision-free fake supplier identifiers and synthetic/non-issued TINs | Unique TIN and all FK refs preserved |
| `transactions` | Source schools, supplier IDs, gross/date/status, `snapshot`, checked hash | Preserve representative numeric/date/status shape; recursively anonymize snapshot; handle checked hashes per approved resealing policy | Never pretend original checked hashes remain authentic after modifying source |
| `audit` | Actor/entity/details, free-text identifiers | Sanitize all nested details; replace actors, references | Append-only check; retained audit volume; mark synthetic provenance |
| `system_settings` | Keys, values, contact data, live secrets/config | Strict allowlist; drop/replace any secret, endpoint or production email | Never copy production credentials or callbacks |
| `source_funds` | Fund labels/configuration, JSON data | Keep approved category semantics, synthetic private labels | Preserve reference relationships |
| `transaction_history` | `before_data`, `after_data`, remarks/actors, old supplier/payment state | Deep recursive scrub of both historical snapshots and free text | Preserve revisions, event types and monotonic order |
| `transaction_pending_edits` | Pending supplier/amount/submitter | Fake references; preserve scenario status/amount distributions | Preserve FK constraints and pending workflow invariants |
| `password_reset_requests` | User and reset request metadata | Drop or replace with synthetic, never live tokens | Do not enable real password recovery in staging |
| `transaction_submitted_backups` | Protected prior submitted payment states | Fake linked identifiers, keep allowed numeric/date distribution | Compare protected snapshot semantics before/after |
| `login_throttle` | Hashed credential/IP identifiers | Clear and regenerate test-only throttle state | No leaked production rate-limit keys |
| `transaction_reference_counters` | School code and invoice/transaction sequencing | Retain safe counters against remapped schools | Preserve unique reference generation behavior |
| `division_transaction_reference_counters` | Division sequence | Retain safe non-identifying counters | Check uniqueness after migration |
| `transaction_deletion_requests` | Reasons, actors, reviewed remarks | Scrub all free text and identities | Preserve workflow states and history |
| `transaction_periods` | Closed/reopened period states; actors | Preserve date/state structure, anonymize actors/schools | Validate closed-period trigger behavior |
| `transaction_period_history` | Historical reopen/close reasons, actors | Scrub text and identities | Preserve event chronology |
| `update_history` | Operator name, package release details, error log | Scrub users, errors/paths/tokens while keeping safe version statuses | Preserve evidence of historical updater operations |
| `schema_migrations` | Migration ID/status and notes | Keep migration IDs/status; review notes for private strings | Confirm 037 is **absent before upgrade**, present/applied after, absent again after complete rollback |

## Four checks before starting any staging web server

1. **Schema completeness:** every installed table and each of the 177 actual fields has a reviewed policy `KEEP`, `TOKENIZE`, `REPLACE`, `DROP`, or `REBASE_WITH_EXPLICIT_SYNTHETIC_PROVENANCE`. Unknown table/field/JSON key = **FAIL**. Do not assume the 177 fields match the source until metadata has been inspected.
2. **No private content:** run independent negative scanning for known withheld source tokens (names/TINs/emails/phone/address/free text), secret keys/credential hashes, real admin accounts, and URL callbacks. No non-owned real record may appear in the staging output, logs, reports, screenshots, ZIP or Git repository.
3. **Data integrity:** verify every FK; non-null and uniqueness constraints; transaction, audit/history and period counts; tax arithmetic on approved synthetic cases; checked snapshot hash integrity *with clearly documented re-seal provenance* and no misleading claim about original certified records.
4. **Environment isolation:** dedicated localhost/institution-owned staging host/DB credentials, **zero exposure to public Internet**, staging owner account and test-only email, no outbound notification/BIR submission, backup encryption and restricted access. Keep original backup encrypted off-site.

## Stop/go constraints for actual historical-data clone

**No approved sanitizer exists yet.** A structure-only export reveals column names and types, but not custom nested JSON keys, data distributions or rare values. A safe engine must classify nested JSON values from a restricted private assessment (without exporting actual values), recursively substitute values and use approved instructions for already-Checked transactions. If historic checked hashes cannot safely be resealed while retaining meaningful migration testing, either block this gate or use a separately authorized **high-security restricted historical replica** with institutional privacy approval. Never solve this by disabling triggers/audit controls or by calling a transformed dataset "unchanged real history."

All records and signoffs remain private. This is a *specification*, not code that has touched or inspected any actual data.
