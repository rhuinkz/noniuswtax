# School User — Step-by-Step Manual

## Access and first sign-in
1. Obtain the School User's assigned login (normally the school code) and temporary password through an approved secure channel.
2. Sign in over HTTPS and complete first-login recovery email and password change.
3. Use **My Account** for a password/recovery-email update and the supported one-time username change. Never share credentials.
4. Use **Forgot password?** to submit a reset request, subject to Division approval.

## Navigation
School Users have **Dashboard, Transactions, Suppliers, ATC Master, Reports, My Account**. They can view only the assigned school's transactions and related reports.

## Before preparing a payment transaction
Confirm the supplier's identity/TIN, VAT registration, correct transaction type, applicable VAT/EWT ATCs, source of fund, payment date and gross amount against accounting documents. The Division must open the month first. You may add a missing *new* supplier or ATC record, but existing shared records must be corrected by an authorized Division user.

## Draft and submit
1. Open **Transactions** and the new transaction action; the origin must be your school.
2. Select supplier and configured supplier tax profile (Goods, Services or another supported type), date, source of fund and gross amount.
3. Review the tax-base, VAT/EWT withholding, total withheld and net amount. Report any classification/rate uncertainty rather than overriding financial assumptions.
4. Save a **Draft** and note the generated reference. Investigate duplicate warnings.
5. Review the Draft and explicitly **Submit** it for Division checking. Submission freezes the computation/master-data snapshot.

## Corrections and cancellation
- For **Returned**: read the Division remarks, correct the record while its month is open, then resubmit.
- For **Checked**: a later edit creates a request for approval, not an immediate change to the official Checked information.
- If you originated a Checked record that must be cancelled, submit the cancellation request with a clear reason; await Division action. History remains retained.
- If the period is closed, ask the Division for documented reopening; do not attempt to bypass the lock.

## BIR Form 2307 and reports
Open an eligible Checked record to generate the Form 2307 PDF. Verify the supplier/payor names, TINs, ATCs, dates and every amount before issuing. The browser PDF generation currently depends on online access to pdf-lib. In **Reports**, choose filters and export CSV only to approved secure destinations. BIR `encoded`/`reconciled` indicators must be checked against the external official process.

## End-of-month checklist
Resolve unsubmitted Drafts and Returned items; confirm pending submissions and edited/cancelled records are reviewed; reconcile checked totals and source funds with accounting records; identify missing certificates; coordinate with Division before period closure.
