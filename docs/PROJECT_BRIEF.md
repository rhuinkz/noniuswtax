# NON-IUS Withholding Tax Monitoring — Project Brief and Narrative

**Reviewed version:** 0.4.56 · **Date:** 16 November 2026 · **Institutional context:** Department of Education, school and Division Office transactions.

## Rationale
School and Division supplier disbursements require consistent tax classification, accurate withholding computations, traceable supporting certificates and an accountable review process. A manual spreadsheet-based approach can result in duplicate encoding, inconsistent supplier profiles, delayed review and difficulty reconstructing corrections. NON-IUS centralizes those activities into a browser-based, role-scoped workflow.

## General objective
Provide a shared system for preparing, calculating, submitting, checking, reporting and monitoring withholding tax transactions originating at participating schools or at the Division Office, while preserving the approved calculation and master-data snapshot of each transaction.

## Specific objectives
1. Maintain registered Division and school details, suppliers and tax profiles, ATC master data and source-of-fund choices.
2. Generate permanent human-readable transaction references and prevent accidental duplication through warning checks.
3. Calculate tax base, VAT withholding, expanded withholding tax, total withheld and net payment according to configured tax codes.
4. Distinguish school preparation/submission from Division review, with return-for-correction and controlled checked-record revisions.
5. Generate BIR Form 2307 from frozen transaction information.
6. Track BIR encoding/reconciliation separately from review status.
7. Support month-specific opening, closing and reopening and preserve audit history.

## Users and governance
The three actual authentication roles are **Project Owner**, **Division Administrator**, and **School User**. Accounting, BIR and finance personnel may be stakeholders but do not have separately implemented roles in this version. The Project Owner maintains privileged operations; Division Administrators manage division-wide work; School Users are limited to their assigned school's transactions.

## Process narrative
The Project Owner installs and secures the application. The Division Office configures its payor identity, schools, source funds, suppliers and VAT/EWT ATCs. A school receives a linked account, completes first-login setup and waits for the Division to open the relevant month. The school creates a Draft, reviews the calculated withholding and submits it. A Division reviewer checks the submission or returns it with remarks. Once Checked, the frozen snapshot provides certificate data; subsequent edits and cancellation requests require a defined review. The Division monitors pending work, recording and reconciliation and closes completed periods.

## Boundaries
This application is **not** a direct BIR e-filing or payment interface. It does not establish that every ATC choice or tax computation is legally correct; finance/tax professionals must review the selected classifications and outputs. No tested claim of capacity for 1,000 simultaneous users is made.

## Suggested indicators
Pending submissions, returned transactions, checked-value totals, unresolved month-close blockers, reconciled records, duplicate warnings, recovery readiness and time from submission to checking.
