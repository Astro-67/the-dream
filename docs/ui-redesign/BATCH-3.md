# Batch 3 - Front desk, reservations and guest records

Implemented, browser-reviewed and validated on 18 November 2026. **Batch 3 is
complete:** all 19 inventoried pages are checked off. The completed migration
count is now 30 of 81 pages.

## Base and scope

- Batch 2 PR #260 passed frontend/backend CI and merged as `b235f96a`. Main's
  CI and deployment both subsequently succeeded for that exact commit.
- Work stays on `feat/ui-redesign-batch-3`, fast-forwarded to that merged base
  without changing the Batch 2 PR or discarding its work.
- The batch covers all 19 inventoried pages: dashboard; guest/booker list,
  create, edit and detail; guest merge; reservation availability, creation,
  list, detail, guests and both statements; night-audit list and detail.
  Thin guest/booker page wrappers reuse the migrated contact/identity modules.
- No new route, migration, dependency, tenancy bypass or business write action.
  Existing booking, identity, fiscal, payment and night-audit validations and
  policies remain authoritative.

## Front desk

- Arrivals, Departures and In house are direct-access navigation cards. All
  three remain side by side at 320px, with active state and concierge bell,
  exit and people icons. Counts retain the existing scoped list semantics.
- The guest queue uses the property business date, validated filters, real
  search and the existing `ListReservations` guest/room/balance presentation.
  Its dashboard-only query sorts priorities **before** 50-row pagination:
  overdue departures, actionable arrivals/departures, in-progress stays and
  completed departures. Group counts say "on this page"; the original
  reservation list order is unchanged.
- Priorities compare calendar dates through `DATE(...)`, matching existing
  date filters on SQLite and MySQL, including stored datetime values.
- Guest pages use `guests_page`; room pages retain `page`. Search, selected
  list and collections period preserve each other. List changes and searches
  reset guest pagination.
- The bounded, keyboard-scrollable queue sits beside the room ledger on wide
  screens and stacks on smaller screens. Queue links use route-bound
  reservation numbers, not database IDs.
- Balance labels have one shared frontend owner. Zero posted balance with
  unposted nights remains Not yet charged; absent folios are not labelled
  paid. Credits/currencies and occupancy/housekeeping readiness stay separate.
  Queue links open existing guarded reservation workflows.

## Page migration

- Guest/booker pages use aligned flat headings/details, bounded two-column
  forms that stack on mobile, and shell-owned gutters. Contact/identity
  sections use separators instead of rounded nested panels. Long unbroken
  names and values wrap. Read-only empty states do not suggest unavailable
  create actions.
- Identity editing/masking stays permission-based. Guest merge retains named
  destructive confirmation, irreversible consequences, property intent and
  survivor rules.
- Availability, reservation lists, registration and the guest-first multi-room
  booking form use operational sections. Room-line indexes, refresh behavior,
  options, prices and validation are preserved. Create/merge forms are inert
  while processing.
- Reservation details replace page-owned cards with flat stay/fiscal sections
  while retaining dialogs, secondary forms, group actions, folios and history.
- Night audit uses shared heading/separator/loading patterns while preserving
  business dates, exceptions, charges and no-show actions.
- Folio/group statements retain non-fiscal labels, separate currencies,
  fiscal-report references and A4/80mm settings.

## Browser review and fixes

The shared T3 preview was tried first: mobile resize, device-theme override,
real login/two-factor and property selection were exercised. The same
multi-page scan timed out twice with "Preview automation evaluate timed out
after 15000ms." The tab was closed and the full matrix used the installed
Playwright/Google Chrome fallback. No browser dependency was installed.

The initial review found and fixed:

- Queue links used database IDs instead of route-bound reservation numbers.
  Browser confirmation follows the real link; frontend coverage asserts its URL.
- Long unbroken contact names expanded the detail grid. Minimum grid widths
  and wrapping now contain the long-name stress case.
- The initial 50-row queue pushed room readiness several screens below the
  work area. A focusable scroll region and wide-screen columns keep both
  usable without dropping rows or server pagination.
- Priority comparisons needed calendar-date normalization. Tests cover
  due-day priorities and a later overdue stay sorting before 51 normal stays.

Harness corrections: frozen future clocks need database-backed preview
sessions rather than expiring file sessions; direct reservation URLs use
numbers; heading locators account for per-page counts; booking uses actual
first/last-name fields. These do not change production authentication.

## Automated checks

- New feature tests cover all queue lists, search, malformed filters,
  permission/setup suppression, property/organization isolation, independent
  paginators and global priority order.
- Frontend checks cover queue/cards/icons, number-bound links, scroll-region
  keyboard access, multi-currency/unposted balances and shared contact sections.
  Existing booking, folio, statement, night-audit and navigation checks remain.
- MySQL 8 disposable containers: 145 focused tests passed, 1,900 assertions,
  covering dashboard/lists, guest/identity/merge, statements and night audit.
  After the date-priority fix, 44 dashboard/list tests passed again with 815
  assertions.
- The initial MySQL invocation supplied multiple paths, rejected by the
  parallel runner. It was rerun with its supported test-name filter. The script
  cleaned up the throwaway containers; the development database was untouched.
- All frontend checks pass. `composer check:fast` exits zero: build, 2,853 Pest
  tests passed (26 skipped; 49,361 assertions), Pint, PHPStan, TypeScript and
  repository formatting/lint. The optional local PHPStan Turbo-extension
  warning remains non-blocking. The original partial slice's missing-spinner
  failure is resolved and covered by the passing full run.
- `git diff --check` and the 81-page inventory pass. Browser evidence was added
  after the full run; its formatting and the repository checks are rerun before
  handoff, without rebuilding over a running test suite.

## Browser verification

The [consolidated results](evidence/batch-3/browser-results.json) contain **186
distinct passing cases**, with no remaining application JavaScript errors:

- 152 surface cases cover every one of the 19 pages at 1440px and 390px, each
  in explicit Light against a dark device, explicit Dark against a light
  device, and System against both device schemes.
- Sixteen additional 320/768px cases cover dashboard, guest details, reservation
  creation and folio statements in Light/Dark. A real long-name contact
  creation adds a seventeenth stress case. No remaining page-level horizontal
  overflow or unlabelled visible input was found.
- Guest-list search/clear, active mobile cards, independent guest pagination,
  global priority order, number-bound navigation and separate room readiness
  labels were exercised through the running application.
- Guest/booker creation and editing use actual native and returned server
  errors. A delayed real save verifies inert loading and a long unbroken name.
  Merge checks the named irreversible confirmation, Escape/focus restoration,
  actual duplicate removal and survivor-note preservation.
- Actual check-in refuses an unready room and accepts a registered guest in
  a clean assigned room. Front desk cannot check out open USD debt/EUR credit;
  the owner still needs an explicit dialog and required override reason.
- Availability and reservation creation use real server prices and routes;
  creation freezes while processing. Real offline contact save is cancelled,
  entered values remain and reconnect restores normal navigation.
- Night audit resolves a no-show proposal, posts real fixture charges and
  advances only its property's business date. Auditor access retains identity
  masking and removes write actions; housekeeping receives no guest-queue
  payload and guest access returns 403.
- Property switching clears an unsaved contact form, updates property intent,
  shows honest empty states and refuses the other property's guest with 404.
- Coarse-pointer form controls meet 44px. Keyboard checks cover search tab
  order, queue scrolling, merge-dialog focus, and a usable reduced-motion
  interface.
- Actual print-to-PDF output was generated and visually inspected:
  [folio A4](evidence/batch-3/folio-a4.pdf) (two pages),
  [folio 80mm](evidence/batch-3/folio-80mm.pdf) (five 80mm by 200mm pages),
  and [group A4](evidence/batch-3/group-a4.pdf) (two pages).
  Print tables are not scroll-clipped, currencies remain separate, non-fiscal
  labels remain, and private identity/notes are absent.

One initial review/fix batch was followed by confirmation. Targeted harness
corrections completed the confirmation without further production edits:
query-parameter ordering; two booking-group Check in buttons; the actual
Confirm reservation button name; reading dashboard props after login; and
checkout correctly being disabled at its trigger for an unauthorized override,
not inside a reachable dialog. Raw runs remain under
`.scratch/batch3-visuals`; the consolidated record preserves their provenance.

## Representative evidence

- Front desk: [desktop Light](evidence/batch-3/dashboard-1440-light.png),
  [mobile Dark](evidence/batch-3/dashboard-390-dark.png),
  [real guest pagination](evidence/batch-3/dashboard-filtered-pagination-mobile.png).
- Contacts: [guest detail desktop](evidence/batch-3/guests-show-1440-light.png),
  [guest edit mobile](evidence/batch-3/guests-edit-390-dark.png),
  [booker form](evidence/batch-3/bookers-create-1440-light.png),
  [merge](evidence/batch-3/guests-merge-390-dark.png),
  [loading save](evidence/batch-3/guest-create-loading.png).
- Reservations: [creation](evidence/batch-3/reservations-create-390-dark.png),
  [detail](evidence/batch-3/reservations-show-390-dark.png),
  [guest registration](evidence/batch-3/reservations-guests-390-dark.png),
  [list](evidence/batch-3/reservations-index-1440-light.png),
  [availability](evidence/batch-3/reservations-availability-1440-light.png),
  [blocked checkout](evidence/batch-3/checkout-currencies-blocked.png),
  [owner override](evidence/batch-3/checkout-owner-override.png).
- Night audit: [index](evidence/batch-3/night-audit-index-390-dark.png),
  [detail](evidence/batch-3/night-audit-show-1440-light.png),
  [real close](evidence/batch-3/night-audit-closed.png).
- Both desktop Light and mobile Dark captures for every inventoried page are
  retained in the evidence directory, not just the representative links here.

## Remaining limits

Browser fixtures use `.scratch/batch3-browser.sqlite`, private scratch uploads
and database sessions, with synthetic 10 December 2026 business dates. No real
guest, development database, ZRA/VFMS service, email or production write.

Chromium viewport/touch/reduced-motion emulation is not physical hardware,
Safari/Firefox or screen-reader verification. Browser print PDFs do not verify
physical thermal-printer calibration. Offline protection follows the browser
online signal; it is not a write queue or server-reachability guarantee.

Delivery uses `feat/ui-redesign-batch-3` and a PR against `main`.
CI, review, merge and deployment remain separate gates.
Batch 4 - Rooms, housekeeping, maintenance and rates - stays out of scope.
