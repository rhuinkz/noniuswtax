# Acceptance and Regression Test Plan

## Baseline static results
PHP source syntax and JS syntax were checked; the included v0.4.56 release manifest checksums matched. A small calculation smoke test passed. The supplied original ZIP contained no automated test suite; no live DB-backed or HTTP tests were executed. All acceptance scenarios below are **pending**.

| ID | Scenario | Expected |
|---|---|---|
| T01 | Install fresh application and retry installer | First setup succeeds, reinstallation prevented |
| T02 | Repeated failed login and reset flow | Throttle activates, reset approvals invalidate old session |
| T03 | School A requests School B's transaction or CSV data | Access rejected |
| T04 | School directly calls Division review/period API | Server denies role bypass |
| T05 | Missing/invalid CSRF token on mutation | Mutation rejected |
| T06 | Save and submit draft | Permanent reference, correct preview and frozen snapshot/hash |
| T07 | Division checks or returns a submitted transaction | Proper status, actor, remarks and history |
| T08 | School edits Checked transaction | Official record persists until approval |
| T09 | Checked cancellation request | Reviewed logical deletion, full retained history |
| T10 | Division requester approves own Division cancellation | Rejected; other authorized reviewer permitted |
| T11 | Close with pending work, then clean month | Blockers enforced, clean month closes |
| T12 | Change transaction in closed month, including hash-only field | Blocked by API/DB guard |
| T13 | Produce Form 2307 | Visually correct payor, supplier, TIN, date, ATCs and amounts |
| T14 | Mark BIR encoded/reconciled and compare external records | Independent status and audit accuracy |
| T15 | CSV export with malicious supplier cell starting `=`/`+`/`-`/`@` | Escaped against spreadsheet-formula execution |
| T16 | Synthetic 10,000+ transactions with filters | Correct totals, paging and performance |
| T17 | Restore full SQL/trigger backup on clone | All data and guards restore |
| T18 | Malformed update ZIP, induced update failure | Rejected or safely rolled back |
| T19 | HTTP request for private config, backup and internal paths | HTTP access denied |
| T20 | Production reset-test-data action after fix | Unavailable |

## Tax golden cases
Generate independently approved expected figures for VAT and non-VAT suppliers, Goods/Services, different ATC codes and effective dates, zero and fractional rates, centavo-level rounding edges, valid min/max amounts, modified supplier master after submission, and Checked-edit approval. Compare integer-centavo totals precisely. Obtain tax officer/accountant signoff separately from test automation.

## CI recommendations
Lint PHP and JavaScript; run calculation unit tests; launch isolated MariaDB/MySQL and apply migrations; run role, CSRF, period-lock and snapshot integration tests; add PDF fixture validation and a staging-only load test; block release for unresolved critical/high issues.
