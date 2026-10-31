# Workspace navigation inventory (#207)

The existing Inertia `layout.breadcrumbs` contract owns navigation. Each
ancestor has an explicit internal destination; the final item is current-page
text. The sidebar layout shows **Back to [immediate ancestor]** before page
content, including on direct entry. Single-item trails have no return link.
Return navigation is hidden in print and does not change Cancel or submission.
Record-level return links can therefore differ from legacy Cancel destinations;
the exceptions below deliberately preserve the existing form behaviour.

Paths below are relative to `resources/js/pages/`. Braces enumerate files.

| Nested surfaces                                      | Intended immediate parent               | Placement                                                                             |
| ---------------------------------------------------- | --------------------------------------- | ------------------------------------------------------------------------------------- |
| `bookers/{create,show}`                              | Bookers `/bookers`                      | Bounded create; readable detail                                                       |
| `bookers/edit`                                       | Named booker `/bookers/{id}`            | Bounded form; Cancel retains `/bookers`                                               |
| `guests/{create,show}`                               | Guests `/guests`                        | Bounded create; detail/stay history retains composition                               |
| `guests/{edit,merge}`                                | Named guest `/guests/{id}`              | Bounded edit (Cancel retains `/guests`); merge comparison retains composition         |
| `properties/{create,edit}`                           | Properties `/properties`                | Bounded create; multi-section edit retains composition                                |
| `invitations/create`, `members/edit`                 | Staff `/members`                        | Bounded invitation; access/ownership/removal sections retain composition              |
| `inventory/room-types/{create,edit}`                 | Room types `/room-types`                | Bounded forms                                                                         |
| `inventory/rooms/{create,edit}`                      | Rooms `/rooms`                          | Bounded forms                                                                         |
| `rates/create`                                       | Rate plans `/rate-plans`                | Bounded form, policy sections retained                                                |
| `rates/edit`                                         | Prices `/rate-plans/{plan}/prices`      | Bounded form; Cancel retains `/rate-plans`                                            |
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

### Verification on 1 November 2026

- All registered frontend checks, TypeScript and the production build pass.
- Affected Pest suites: 190 tests, 2,710 assertions, including contact forms,
  inventory, rates, reservation creation, invoices, properties, tenant switching
  and property intent.
- Browser widths: 390px, 820px, 1280px and 1440px; light/dark themes,
  expanded/collapsed sidebar and mobile navigation sheet.
- Bounded Booker form centre offset: 152px before, 0px after, measured relative
  to the remaining workspace in both desktop sidebar states and on tablet.
- Direct-entry detail/edit return chain, original Cancel destination, native
  required-field feedback, keyboard focus ring and parent-link-to-Name tab order
  confirmed. Parent and ancestor links are at least 44px high.
- A 200-character unbroken parent label wraps without horizontal overflow or
  spilling outside its link. Its link grows to 136px for 118.5px of text.
- Mobile Bookers table: 433px inside a 356px scroll container. Tablet calendar:
  1773px inside a 514px scroll container. Neither causes body overflow.
- Reservation creation remains wide (1136px form in an 1184px workspace);
  account settings retain navigation and a 720px form column.

These are representative browser checks, not a visual pass over every inventory
entry. Full integrated contrast, physical print and accessibility qualification
remain with #208. Browser data is disposable worktree SQLite; serve with
`--no-reload` so Artisan preserves exported database settings.
