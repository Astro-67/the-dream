# Workspace navigation inventory (#207)

The existing Inertia `layout.breadcrumbs` contract owns navigation. Each
ancestor has an explicit internal destination; the final item is current-page
text. The sidebar layout shows **Back to [immediate ancestor]** before page
content, including on direct entry. Single-item trails have no return link.
Return navigation is hidden in print and does not change Cancel or submission.

Paths below are relative to `resources/js/pages/`. Braces enumerate files.

| Nested surfaces                                      | Intended immediate parent               | Placement                                                                             |
| ---------------------------------------------------- | --------------------------------------- | ------------------------------------------------------------------------------------- |
| `bookers/{create,edit,show}`                         | Bookers `/bookers`                      | Bounded forms; readable detail                                                        |
| `guests/{create,edit,show,merge}`                    | Guests `/guests`                        | Bounded forms; detail/stay history and merge comparison retain composition            |
| `properties/{create,edit}`                           | Properties `/properties`                | Bounded create; multi-section edit retains composition                                |
| `invitations/create`, `members/edit`                 | Staff `/members`                        | Bounded invitation; access/ownership/removal sections retain composition              |
| `inventory/room-types/{create,edit}`                 | Room types `/room-types`                | Bounded forms                                                                         |
| `inventory/rooms/{create,edit}`                      | Rooms `/rooms`                          | Bounded forms                                                                         |
| `rates/{create,edit}`                                | Rate plans `/rate-plans`                | Bounded forms, policy sections retained                                               |
| `rates/prices`                                       | Rate plans `/rate-plans`                | Wide table plus bounded editor                                                        |
| `rates/{restrictions,quote}`                         | Prices `/rate-plans/{plan}/prices`      | Wide results plus bounded editor                                                      |
| `rates/tax-preview`                                  | Tax rules `/tax-rules`                  | Bounded input plus results                                                            |
| `reservations/{create,show}`                         | Reservations `/reservations`            | Wide multi-section form/detail                                                        |
| `reservations/{guests,statement}`                    | Reservation `/reservations/{number}`    | Bounded guest management / printable statement                                        |
| `receipts/show`, `fiscal-receipting/checkout-report` | Reservation `/reservations/{number}`    | Existing printable document composition                                               |
| `night-audit/show`                                   | Night audit `/night-audit`              | Wide charges and exceptions                                                           |
| `maintenance/show`                                   | Maintenance `/maintenance`              | Readable ticket and history                                                           |
| `housekeeping/{history,assignments}`                 | Housekeeping `/housekeeping`            | Wide history and assignments                                                          |
| `platform/organizations/show`                        | Organizations `/platform/organizations` | Wide multi-section detail                                                             |
| `subscription/invoice` (owner route)                 | Subscription `/subscription`            | Existing invoice/payment/slip composition                                             |
| `subscription/invoice` (platform route)              | SaaS invoices `/platform/invoices`      | Same document; parent selected from the current route, not roles or upload permission |

## Intentional roots and exceptions

- List/index pages, dashboard, rate calendar, tax rules, reports/group reports,
  subscription, approvals, payments, cashier shifts and open balances are
  workspaces, not nested records. They keep their operational width and no
  artificial parent.
- Availability, My rooms and reservation import are independently accessible
  tasks. They remain roots even though their URLs contain another segment.
- Platform billing details is the platform's root settings screen, not a record
  editor below an index.
- Profile, security and appearance retain the settings navigation and bounded
  form column; no invented Settings landing route.
- Organization creation is first-run setup outside the app shell. Authentication,
  invitations accepted from email and public landing pages keep their own layouts.
- Dialog/inline editors (reservation amendments, room moves, payments, taxes,
  maintenance reporting, staff/platform plans) remain in their owning page.
  They do not gain page-return navigation.

## Checks

`PageNavigationCheck.tsx` renders every nested page's public Inertia layout
contract (34 destinations including the two invoice contexts) and checks roots.
`WorkspaceLayoutCheck.tsx` renders real Booker detail/create/edit pages with both
sidebar states, linked ancestors, current labels, context, Cancel and form intent.
Existing page checks cover the underlying workflows; browser checks establish
geometry, responsive overflow, focus and actual navigation.
