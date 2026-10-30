# Uhuru role login and access comparison

Date: 21 October 2026. This is authenticated first-party browser evidence, not a comparison of advertised role descriptions.

## Scope and safety

- Created a separately labelled test account for each Uhuru role: Owner/Admin, Manager, Reception and Housekeeping. Each generated password was used successfully through the normal property-code/email/password login form.
- Inspected 12 direct-route surfaces per role. Recorded response statuses from the application's own UI-triggered GET requests, without changing those responses or bypassing authentication.
- Opened and cancelled the Manager and Reception payment dialogs and the Housekeeping new-maintenance-ticket dialog. Did not submit payments, guest bookings, check-ins, repair tickets, settings changes or physical room-status changes.
- No generated passwords, authentication tokens, guest names, document values or financial payloads are stored in this report or issue drafts.
- One initial QA admin credential could not be recovered from the dismissed one-time dialog. That unused account was deactivated, then a replacement was created and tested. All five accounts created in this run are now deactivated.
- Original staff roles and active/inactive states were preserved. The original owner session was restored. The older reference Reception test account remains deactivated.

Primary source: authenticated Uhuru UI at `app.uhurupms.app`, relative property routes `#/dashboard`, `#/reservations`, `#/hk`, `#/maintenance`, `#/payments`, `#/receipts`, `#/reports`, `#/rates`, `#/rooms`, `#/settings`, `#/staff`, `#/billing`. HTTP paths below are requests made by those screens, not an assertion that every possible API operation was tested.

## Observed matrix

`200` means the observed UI-triggered read request succeeded. It does not prove authority to submit a financial or administrative mutation.

| Surface / observed request                                    | Owner/Admin                         | Manager                                                | Reception                                    | Housekeeping                                                |
| ------------------------------------------------------------- | ----------------------------------- | ------------------------------------------------------ | -------------------------------------------- | ----------------------------------------------------------- |
| Normal generated-password login                               | Passed                              | Passed                                                 | Passed                                       | Passed                                                      |
| Today / `/api/dashboard`                                      | 200                                 | 200                                                    | 200                                          | 200; collections card visible                               |
| Reservations / `/api/reservations`                            | 200; New booking and Check in shown | 200; New booking and Check in shown                    | 200; New booking and Check in shown          | 200 by direct navigation; booking controls shown            |
| Housekeeping / `/api/housekeeping/rooms`                      | 200                                 | 200                                                    | 200                                          | 200; Mark clean shown                                       |
| Maintenance / `/api/maintenance/tickets`                      | 200; New ticket shown               | 200; New ticket shown                                  | 200; write not tested                        | 200; New ticket form opened and cancelled                   |
| Aggregate payments / `/api/payments/transactions`             | 200                                 | 200                                                    | 403                                          | 403                                                         |
| Receipt list / `/api/reservations/receipts`                   | 200                                 | 200                                                    | 200                                          | 200 by direct navigation                                    |
| Revenue reports / `/api/reports/kpis`, `/api/reports/revenue` | 200                                 | 200                                                    | 403                                          | 403                                                         |
| Rate plans / `/api/rates/plans`, rate calendar                | 200                                 | 200                                                    | 200; manager/admin required to change prices | 200; same read-only warning                                 |
| Room list / `/api/rooms`, `/api/rooms/types`                  | 200; edit controls                  | 200; edit controls                                     | 200; writes not tested                       | 200; Status controls shown, not submitted                   |
| Property settings / `/api/settings`                           | 200; Save changes shown             | 200; Save changes shown                                | 200 by direct navigation; write not tested   | 200 by direct navigation; Save changes shown, not submitted |
| Staff lookup / `/api/staff`                                   | 200; staff-management controls      | 200; management UI says only an admin can manage staff | 200; management writes not tested            | 200; management UI says only an admin can manage staff      |
| SaaS billing / `/api/billing/invoices`                        | 200                                 | 403; owner-only notice                                 | 403                                          | 403; owner-only notice                                      |

Additional focused checks:

- Manager and Reception each opened a reservation through its normal detail UI, then opened Record payment. Both dialogs exposed amount, method and reference. Methods included Cash, Card, Mobile money, Bank transfer, Online and Other. Both dialogs were cancelled. No payment-post success is claimed.
- Housekeeping's New ticket form included title, description, priority, optional room, common-area text and Repair/Preventive/Corrective classification. No ticket was submitted.
- Non-admin staff management and SaaS billing are different boundaries: staff lookup can return 200 while management controls are denied; billing returned 403.
- Some denied route transitions retained earlier content under a new page heading. Consequently, role decisions in this report use the observed endpoint status and explicit notices, not page headings or hidden menu links alone.

## Comparison with Dream

The source baseline and exact policy/route references are in `uhuru-role-comparison-dream-baseline-2026-10-21.md`. That baseline is static source inspection, not a claim that Dream role logins ran during this Uhuru session.

1. **Manager payment-taking is a real policy difference, not a label fix.** Uhuru Manager opened Record payment and read aggregate payments/reports. Dream Property manager already has report/folio reads but lacks `charges.post`. `FolioPolicy::recordPayment()` delegates to `postCharge()` and therefore denies that capability by default (`app/Domains/Tenancy/Enums/OrganizationRole.php`, `app/Domains/Billing/Policies/FolioPolicy.php`, `app/Domains/Billing/Policies/CashierShiftPolicy.php`). Existing #132/#142 explicitly preserve that default, so any change requires an explicit role-policy decision rather than silently extending #142.
2. **A maintenance-ticket workflow is missing in Dream.** Uhuru has an operational ticket board and reporting form available to the tested Housekeeping role. Dream has housekeeping transitions and dated inventory out-of-service periods, but no dedicated work-order module. No open maintenance-ticket issue was found; #132 deliberately excluded maintenance. A separate follow-up can implement the small work-order workflow without reimplementing inventory state.
3. **Reception rate/inventory reads are not a new Dream gap.** Uhuru shows read-only rate/inventory data; Dream already permits reservation-viewing staff to read those surfaces while management actions require their own grants. Do not file a duplicate or incorrectly grant rate management.
4. **Do not copy the reference's wider Housekeeping data exposure.** The tested Uhuru Housekeeping login received dashboard, reservation, receipt and settings reads that Dream intentionally does not grant. Keep Dream's guest/identity/finance/staff restrictions. These observations are not a request to expand access, and no mutation authorization was inferred from an exposed button.
5. **Existing feature differences remain under their existing issues.** Staff onboarding/role explanations and authorization coverage belong to #142; receipts/payments/report presentation to #135-#137; unpaid checkout to #138; optional cashier shifts to #139; reservation presentation and room dashboard to #140-#141. Attach evidence rather than creating duplicates.
6. **Do not copy administrator-visible reusable staff passwords.** Uhuru generated passwords enabled this controlled test. Dream should retain the invitation/verification/password-setting flow specified in #142.

## Cleanup verification

The staff list confirmed all five accounts created in this run were deactivated, including the unused initial QA admin. Original owner and existing Reception staff remained active; the pre-existing reference test remained inactive.

After cleanup, a normal login attempt with the previously working generated QA Owner/Admin credential was rejected with `Invalid credentials` and remained on the login page. The original owner then signed in successfully again. Immediate revocation of an already-open staff session was not tested.

## Ticket publication

- #143: Add property-scoped maintenance tickets with role-limited reporting and assignment. Enhancement / medium priority / ready for implementation; independently scoped follow-up outside the original Spec 12 delivery.
- #144: Decide owner-approved Property manager payment-taking parity with Uhuru. Enhancement / question / medium priority; explicitly blocked on a product-owner permission decision, not marked ready for implementation.
- Existing #142 receives the new runtime role evidence and the security boundaries that must remain intact. Existing #133-#141 are not duplicated.

These are published issue filings, not an implementation or deployment claim.

## Not tested

No real financial write, booking lifecycle, authority submission, destructive administration of original users, cross-property/cross-organization isolation, existing-session revocation or credential rotation was exercised. These findings are a role-access and workflow comparison, not a full penetration test or production certification.
