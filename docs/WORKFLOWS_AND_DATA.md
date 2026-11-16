# Workflows and Data Model

## Transaction lifecycle
```text
School:  Draft -> Submitted -> Checked (Division decision)
                       |             |
                       v             +--> Pending edit -> approve/reject
                    Returned --------> correct and resubmit
                                     +--> Cancellation request -> approve/reject
Division-originated: Division Draft -> Checked
Unsubmitted Draft -> logically Deleted/cancelled
Checked -> Void (authorized reviewer)
```

An edit request to a Checked school transaction retains the prior approved snapshot until reviewed. `deleted` and `void` are historical states, not physical tax-record deletion. BIR status (`not_encoded`, `encoded`, `reconciled`) is a separate monitoring dimension.

## Month controls
Each target (individual school or Division Office) has its own month state: not opened, open, reopened or closed. The system blocks transaction changes when the applicable month is not writable. Reopenings record a reason; pending drafts, submissions, returned items, changes or cancellations can prevent closure.

## Tax computation implemented in the current source
Amounts are stored in integer **centavos**. The function `calculate_tax` uses a rounded base of `gross / 1.12` for VAT-registered suppliers, otherwise gross itself; independently computes VAT withholding and EWT based on configured percentages; net payment is gross minus both withholding components. This describes the **code logic, not a determination that it is legally appropriate for every transaction**. Validate rate, supplier classification, exemptions, rounding and effective dates against approved BIR rules.

## Database inventory
The uploaded SQL contains **21 tables**:
- **Identity:** `members`, `password_reset_requests`, `login_throttle`.
- **Master:** `division`, `schools`, `suppliers`, `atcs`, `source_funds`.
- **Transactions:** `transactions`, `transaction_pending_edits`, `transaction_submitted_backups`, `transaction_deletion_requests`.
- **Controls/audit:** `audit`, `transaction_history`, `transaction_periods`, `transaction_period_history`.
- **Operational:** `transaction_reference_counters`, `division_transaction_reference_counters`, `schema_migrations`, `update_history`, `system_settings`.

The export defines foreign keys and indexes and **14 named triggers** for record integrity, immutability and period checks. JSON-containing `LONGTEXT` fields store configurable master data and frozen transaction snapshots. User records include password hashes/session versions. Both the uploaded SQL and its backup copies contain identifying and potentially sensitive information and must not be placed in a public GitHub repository.

## Financial and privacy governance
The registered payor/supplier identity, TIN, gross amounts, ATC classification, check remarks, approval history and user identifiers require controlled access, approved retention and secured backup. Test data must be synthetic.
