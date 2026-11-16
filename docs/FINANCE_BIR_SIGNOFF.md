# NONIUSWTAX — Finance / BIR Tax and Form 2307 Acceptance Record

**Status: NOT REVIEWED / NOT APPROVED.** Do not interpret local arithmetic smoke tests as a BIR ruling or finance approval.

## A. Source and scope

This project manages supplier payments and withholding tax for school and Division Office transactions. Its baseline calculation uses a VAT-registered tax-base assumption dividing gross by **1.12**, and applies configured VAT/EWT/ATC percentages with centavo rounding. Whether a particular payment is subject to that formula depends on the payee's status, transaction, ATC, government withholding rules, validity period, invoicing and actual law.

Current official starting references to validate with the responsible finance/BIR reviewer:
- [BIR Forms — Certificates and Form 2307 description](https://www.bir.gov.ph/bir-forms?tab=Certificates)
- [BIR Form 2307 specimen PDF (January 2018 ENCS)](https://bir-cdn.bir.gov.ph/local/pdf/2307%20Jan%202018%20ENCS%20v3.pdf)

The reviewer must independently identify the **rules effective for each applicable payment date** and verify whether any later regulations, RMCs or revised forms apply. Nothing here asserts particular VAT/EWT/ATC rates are always correct.

## B. Independent legal rule matrix (complete before approval)

| Scenario ID | Payee category / VAT registration | Payment type / invoice evidence | ATC code and tax type | Rate and legal authority (RR/RMC section) | Effective from/to | Reviewer | Status |
|---|---|---|---|---|---|---|---|
| FIN-01 | VAT-registered, sample goods | Synthetic receipt and supporting documents | TO BE CONFIRMED | TO BE CONFIRMED | TO BE CONFIRMED | — | PENDING |
| FIN-02 | VAT-registered, sample services | Synthetic receipt and supporting documents | TO BE CONFIRMED | TO BE CONFIRMED | TO BE CONFIRMED | — | PENDING |
| FIN-03 | Non-VAT supplier | Synthetic invoice, classification | TO BE CONFIRMED | TO BE CONFIRMED | TO BE CONFIRMED | — | PENDING |
| FIN-04 | Government/Division payor | Validate current government-specific requirements | TO BE CONFIRMED | TO BE CONFIRMED | TO BE CONFIRMED | — | PENDING |
| FIN-05 | Tax-exempt / special treatment | Document specific exemption certificate/rule | TO BE CONFIRMED | TO BE CONFIRMED | TO BE CONFIRMED | — | PENDING |
| FIN-06 | Historical effective dates | Transactions spanning a rate/form revision | TO BE CONFIRMED | TO BE CONFIRMED | TO BE CONFIRMED | — | PENDING |

Add rows for every actual supplier category, payment type, source of fund and ATC maintained in NONIUSWTAX. Do not treat configured rate tables as authoritative unless they have documentary approval.

## C. Independently calculated golden-case ledger

For each approved scenario, prepare figures outside NONIUSWTAX using a separately approved decimal-arithmetic method. The 13 synthetic tests already in `tests/release/tax-golden.json` are **mechanical regression fixtures only**, not independent proof that the correct BIR tax rules were chosen.

| Case ID | Payment date | Gross PHP | Basis / VAT treatment | Applicable VAT withheld | Expanded withholding tax | Expected net payment | Expected Form 2307 figure(s) | Approved source | Matched app? |
|---|---|---|---|---|---|---|---|---|---|
| TAX-01 | — | — | — | — | — | — | — | — | PENDING |
| TAX-02 | — | — | — | — | — | — | — | — | PENDING |
| TAX-03 | — | — | — | — | — | — | — | — | PENDING |

Review at least the following edge categories: ₱0.01 and centavo half-up boundaries; VAT/non-VAT; goods/services; exempt/reduced-rate; effective-date changes; zero and invalid inputs; amounts near software limits; different ATCs on the same supplier; refunds/cancellations; corrected and previously Checked transactions; monthly and quarterly rollups.

A mismatch blocks release. Fix legal treatment **in application source and tests**, then repeat the complete automated verification on the new commit and update candidate provenance. Do not adjust official approved source figures to agree with current code.

## D. Real Form 2307 specimen review

The Docker package generated `test-results/bir2307-rendered-synthetic.pdf` and `bir2307-visual-review.png`; automatic success **does not** validate visual positions, legal form edition, authorized signature or printed legibility.

The responsible reviewer must inspect **actual printed output** against the BIR-approved form for representative School, Division, VAT and non-VAT cases, including:

- [ ] Correct form edition and correct period From/To; month/quarter treatment matches accounting records.
- [ ] Part I: Payee name, TIN, registered address and ZIP correspond to approved supplier record.
- [ ] Part II: Payor name, TIN, registered address and ZIP correspond to correct government/school/Division identity.
- [ ] Proper nature of income, ATC classification, payment/monthly breakdown, gross/base and total tax withheld.
- [ ] Correct centavos, rounding, cumulative totals, spelling and no clipped or truncated text.
- [ ] Printed font size, row/column alignment, page size and legibility; PDF metadata and current document revision.
- [ ] Authorized printed/electronic signatory details and issuance procedures; blank/default/dummy TINs must never enter actual issuance.
- [ ] Frozen Checked figures agree with the source register, approved VAT/EWT cases and the corresponding reconciliation report.
- [ ] Returned/corrected/voided records cannot be misrepresented as final approved certificates.

Store reviewed **redacted or synthetic** printed specimens with the private release record, not in public GitHub.

## E. Approval statement — leave unsigned until checked

**Tax / Accounting reviewer (name & designation):** ______________________

**Division / Agency (if required):** ______________________

**Reviewed legal references and effective dates:** ______________________

**Independently verified test-case register and date:** ______________________

**Form 2307 specimen register, version and date:** ______________________

**Decision (APPROVE / REJECT / NEEDS CORRECTION):** ______________________

**Signature and date:** ______________________

**Current decision: PENDING.** Neither the application nor this document automatically files or remits taxes to the BIR.
