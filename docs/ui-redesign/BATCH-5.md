# Batch 5 - Finance, reporting and compliance

Implemented and browser-reviewed on 19 November 2026. All 14 Batch 5 entries
are migrated; the completed inventory is **63 of 81 pages**. Batch 6 is next.

## Base and scope

- Confirmed remote main at `0fc8fb083af24f46a396bc1b0dd4fa1ae88e21ca`;
  its CI and deployment succeeded. Implementation used
  `feat/ui-redesign-batch-4`, preserving the existing local Batch 4 work.
  Neither batch had been committed, pushed or deployed at the
  implementation handoff. The owner subsequently requested combined delivery.
- Covers approvals, open balances, cashier shifts, day-use/event accounts,
  payments, tax rules/preview, receipts, property/group reports and ZRA
  document overview/printing.
- The checkout-report page remains a thin wrapper around the existing
  `features/fiscal-receipting/zra-documents.tsx`; its page-owned composition
  is migrated there. Property/group reports and payments reuse the existing
  report layout rather than adding another page abstraction.
- No routes, controllers, queries, migrations, dependencies, permissions,
  fiscal payloads or financial write actions changed. No new exports or
  financial summaries were invented. Existing property intent, authorization,
  validation, confirmations and offline write protection remain authoritative.

## Presentation

- Shell-owned gutters, semantic page headings and flat header/filter/section
  rules match the preceding operational batches. Tables retain internal
  horizontal scrolling and existing pagination.
- Customer account identifiers use the existing copyable identifier component;
  long customer headings wrap. Shift history has a named section distinct
  from the current shift and its currency counts.
- Payment/report navigation wraps on mobile, keeps explicit current-page
  semantics and retains the existing business-date and settled-folio rules.
  Monetary values still use the existing currency-aware formatter, including
  TZS whole units, credits and per-currency totals.
- Tax controls reuse `NativeSelect`; add/change dialogs scroll within narrow
  viewports. The preview exposes a named breakdown, processing protection and
  a reserved spinner slot. Tax calculations and effective dates are unchanged.
- Receipt screens keep their historical snapshot and non-fiscal warning.
  ZRA sheets keep legal labels, stored fiscal fields, sandbox warnings,
  corrections, verification controls and document page breaks. Screen-only
  separators disappear in print; empty print views have a heading and disabled
  print action. The existing A4/80mm page sizes and print CSS are unchanged.

## Automated validation

- `composer check:fast` ran against a freshly migrated disposable SQLite
  database. Build, **2,853 Pest tests passed, 26 skipped, 49,361 assertions**,
  Pint, PHPStan and TypeScript passed. The final formatting gate rejected the
  newly generated browser-evidence JSON, which was then formatted.
- After the visual fixes: the build, TypeScript, all frontend checks and the
  final repository formatting/lint gate pass. The complete Pest suite was not
  rerun for the subsequent presentation-only edits or JSON formatting.
  PHPStan's existing optional local Turbo-extension warning is non-blocking.
- Added `FinanceWorkspaceCheck.tsx` to `test:frontend`: semantic headings,
  disabled self-approval decisions, property intent, zero approval thresholds,
  separate USD/TZS balances and credits, account-create permission gating,
  empty states, tax breakdown and report date/time context.
- Extended `CheckoutReportCheck.tsx` for empty ZRA print heading/message and
  disabled printing. Existing receipt, fiscal document, report, balance,
  navigation and theme regressions remain green.
- `git diff --check` and the exact 81-page/63-completed inventory pass.
  No schema/query changes: no additional MySQL run. Development MySQL was
  not migrated, wiped or seeded.

## Browser evidence

The [initial matrix](evidence/batch-5/browser-results.json) records 40 passing
checks; the [confirmation](evidence/batch-5/confirmation-results.json) records
23, including repeated checks. These are not 63 distinct scenarios.

- Every Batch 5 page entry: 1440px Light and 390px Dark, labelled fields,
  page-level horizontal overflow and representative content/empty states.
- Representative 320px account, payment, receipt, tax and group-report
  workspaces; System against both device color schemes; tax-field keyboard
  order; real tax-preview submission; returned report date validation;
  offline retention of a computed report.
- Non-fiscal receipt A4/80mm emulation and generated PDFs; synthetic sandbox
  fiscal receipt A4/80mm emulation and generated PDFs. Print controls hide,
  values stay black on white and fiscal/non-fiscal distinctions remain visible.
  Fiscal print props were explicitly substituted in the browser for this
  visual test; no document was issued or fiscal evidence inserted.
- No application JavaScript errors. The review removed a duplicate account
  header/folio divider and added the empty ZRA document heading.

Representative captures:

- [Payments, mobile Dark](evidence/batch-5/payments-390-dark.png)
- [Customer account, desktop Light](evidence/batch-5/customer-account-1440-light.png)
- [Customer account, mobile Dark](evidence/batch-5/customer-account-390-dark.png)
- [Property reports, desktop Light](evidence/batch-5/property-report-1440-light.png)
- [Tax preview result, narrow Dark](evidence/batch-5/tax-preview-breakdown-320-dark.png)
- [Non-fiscal receipt, A4 emulation](evidence/batch-5/receipt-print-a4.png)
- [Synthetic sandbox fiscal receipt, thermal emulation](evidence/batch-5/zra-print-thermal-synthetic.png)

## Limits and next batch

Browser tests used a private copy of the existing synthetic SQLite fixture,
isolated uploads, blocked outbound HTTP and a frozen **10 December 2026**
fixture clock. That future date belongs to test data, not the review date.
No production financial write, real guest data or live fiscal service was used.

Physical printers, physical devices, screen readers and Safari/Firefox remain
unverified. Fiscal browser printing used synthetic props; stored-document,
QR, correction and authorization behavior is covered by the existing automated
tests, not a live ZRA acceptance test. Browser validation samples representative
states rather than every combination of every form. Offline retention is not
an offline write queue or a server-reachability guarantee.

Continue with **Batch 6 - Settings, teams, subscriptions and platform**.
The final cross-screen owner review remains a separate gate.

## Combined release validation

Before committing Batches 4 and 5 together on 19 November 2026, the complete
`composer check:fast` was rerun against another freshly migrated disposable
SQLite database and exited zero: build, 2,853 Pest tests passed, 26 skipped,
49,361 assertions, Pint, PHPStan, TypeScript and repository formatting/lint.
All frontend checks and both reservation calendar row tests passed afterwards.
This full run includes the final presentation edits and supersedes the earlier
formatting-only interruption; no development database was changed.

Both batches use the existing `feat/ui-redesign-batch-4` branch for combined
delivery. PR CI, merge, main CI and production deployment are separate gates;
local validation alone does not establish that the screens are live.
