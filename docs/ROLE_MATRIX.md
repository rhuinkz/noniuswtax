# Actual Roles and Permission Matrix (v0.4.56)

The code defines exactly three roles: `project_owner`, `division_admin` and `school_user`.

| Feature | Project Owner | Division Administrator | School User |
|---|---|---|---|
| Dashboard and own account | Yes | Yes | Yes |
| All-school transaction visibility | Yes | Yes | No; own school only |
| Division Action Center | Yes | Yes | No |
| Create own school's Draft and submit | Not ordinary owner flow | Not ordinary admin flow | Yes |
| Create/check Division-originated transaction | Yes | Yes | No |
| Check/return school submissions | Yes | Yes | No |
| Review Checked edit and cancellation requests | Yes | Yes | No |
| New supplier/ATC | Yes | Yes | Yes; shared new entries |
| Edit existing supplier/ATC | Yes | Yes | No |
| Modify school details | Yes | Yes | Own school contact/representative fields only |
| Division profile and funds | Yes | Yes | No |
| Open/reopen/close months | Yes | Yes | No |
| Reports/CSV | All origins | All origins | Own school |
| Authorized account administration | Full supported scope | School User accounts | Own account |
| Approve password reset | Eligible accounts | School users | No |
| SQL console, updater, login gallery and test-data reset | Owner only | No | No |

**Review status:** `draft` → `submitted` → `checked`; alternatively a submission may be `returned` for correction. An edit to a Checked school transaction may be `pending_approval` while the previous Checked snapshot remains authoritative. Cancellation and void actions retain history (`deleted` and `void` are not a physical removal of a transaction row). **BIR tracking** (`not_encoded`, `encoded`, `reconciled`) is separate from transaction review status.

**Security principle:** UI navigation visibility must be complemented by server-side role and school-scope enforcement, and validated by negative API tests.
