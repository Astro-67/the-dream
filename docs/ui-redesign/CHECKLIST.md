# UI migration checklist

Current inventory: **81 page files** under `resources/js/pages/`.
Page paths below are relative to that directory. Add/remove entries when the
page inventory changes. Each page appears in exactly one batch.

**Checked means implemented, visually reviewed and validated**, using the
completion criteria in [the handoff brief](README.md#check-and-hand-off).
Approved mockups are references, not completed production pages.

## Preparation

- [x] Preserve the approved landing, login, signup and both front-desk themes.
- [x] Preserve the public pricing snapshot with its limitations.
- [x] Inventory all current page files and define bounded batches.
- [x] Commit this handoff package before depending on it from a new worktree.
- [x] Verify the pending PR/implementation base and preserve existing local work.
- [x] Reconcile this page inventory against the merged implementation base.

## Batch 0 - Shared UI foundation

Start here. Scope is existing shared UI, not new business features.

- [x] Reconcile the approved operational decisions into `docs/design.md`, preserving its existing edits.
- [x] Scope public/auth versus operational radius, spacing and density deliberately.
- [x] Implement the approved masthead/menu using the existing app shell/navigation, sticky context and parent-navigation components.
- [x] Reuse/update existing Button, input/select, Table, StatusChip/Tag, Identifier, EmptyState, Pagination and overlay components.
- [x] Keep the full brand and real organization/property context, permission-based navigation and property-switch state resets.
- [x] Wire Light/Dark/System through the existing appearance hook and theme tokens.
- [x] Verify representative operational and public/auth surfaces after shared changes.
- [x] Update affected theme, sidebar, workspace and page-navigation frontend checks.
- [x] Complete the shared completion criteria and update the handoff record.

Do not wire the prototype guest queue or change reservation queries in this
batch. That belongs to Batch 3.

## Batch 1 - Public site and authentication

Reference: [landing](approved/landing.html), [login](approved/login.html),
[signup account/email steps](approved/signup.html).

- [x] Implement the approved landing hero, original footer and current plan-driven pricing.
- [x] Preserve passkey capability checks, two-factor and password/reset/verification behaviour.
- [x] Carry the auth visual family across the remaining auth screens without another concept round.

9 page files:

- [x] `auth/confirm-password.tsx`
- [x] `auth/forgot-password.tsx`
- [x] `auth/invitation.tsx`
- [x] `auth/login.tsx`
- [x] `auth/register.tsx`
- [x] `auth/reset-password.tsx`
- [x] `auth/two-factor-challenge.tsx`
- [x] `auth/verify-email.tsx`
- [x] `welcome.tsx`

## Batch 2 - Organization setup and onboarding

Reference: [signup business/property/checklist steps](approved/signup.html).

Implemented, browser-reviewed and validated on 18 November 2026; see the
[Batch 2 handoff](BATCH-2.md) for checks, evidence and remaining limits.

- [x] Stage business/property draft fields while retaining the single existing creation action.
- [x] Preserve required property fields, optional registration details, jurisdiction rules and server validation.
- [x] Migrate the shared onboarding checklist, preserving real readiness and go-live gates.
- [x] Preserve the reservation-import workflow; consult `docs/imports.md` when changing its UI.

2 page files:

- [x] `onboarding/import.tsx`
- [x] `organizations/create.tsx`

## Batch 3 - Front desk, reservations and guest records

Reference: [front desk light](approved/front-desk-light.html) /
[dark](approved/front-desk-dark.html).

Implemented, browser-reviewed and validated on 18 November 2026. See the
[Batch 3 handoff](BATCH-3.md) for all 19 surfaces, tests, evidence and limits.

- [x] Implement the direct-access guest-list cards, clear active state and distinct icons.
- [x] Supply the guest queue from existing scoped reservation-list logic; retain real search, pagination and permissions.
- [x] Preserve independent occupancy/readiness states, balance semantics and guarded check-in/out.
- [x] Reuse list/detail/form patterns for guest and booker pages; retain merge confirmations and identity privacy.
- [x] Preserve property business dates and night-audit behaviour.

19 page files:

- [x] `bookers/create.tsx`
- [x] `bookers/edit.tsx`
- [x] `bookers/index.tsx`
- [x] `bookers/show.tsx`
- [x] `dashboard.tsx`
- [x] `guests/create.tsx`
- [x] `guests/edit.tsx`
- [x] `guests/index.tsx`
- [x] `guests/merge.tsx`
- [x] `guests/show.tsx`
- [x] `night-audit/index.tsx`
- [x] `night-audit/show.tsx`
- [x] `reservations/availability.tsx`
- [x] `reservations/create.tsx`
- [x] `reservations/guests.tsx`
- [x] `reservations/group-statement.tsx`
- [x] `reservations/index.tsx`
- [x] `reservations/show.tsx`
- [x] `reservations/statement.tsx`

## Batch 4 - Rooms, housekeeping, maintenance and rates

- [x] Apply the operational visual family to the existing list/detail/form patterns.
- [x] Keep room readiness, assignments and out-of-service periods distinct.
- [x] Keep calendar/date-range navigation, horizontal scrolling and sticky labels usable.
- [x] Preserve real room/rate validation, currency labels and permissions.

19 page files:

- [x] `housekeeping/assignments.tsx`
- [x] `housekeeping/history.tsx`
- [x] `housekeeping/index.tsx`
- [x] `housekeeping/my-rooms.tsx`
- [x] `inventory/room-types/create.tsx`
- [x] `inventory/room-types/edit.tsx`
- [x] `inventory/room-types/index.tsx`
- [x] `inventory/rooms/create.tsx`
- [x] `inventory/rooms/edit.tsx`
- [x] `inventory/rooms/index.tsx`
- [x] `maintenance/index.tsx`
- [x] `maintenance/show.tsx`
- [x] `rates/calendar.tsx`
- [x] `rates/create.tsx`
- [x] `rates/edit.tsx`
- [x] `rates/index.tsx`
- [x] `rates/prices.tsx`
- [x] `rates/quote.tsx`
- [x] `rates/restrictions.tsx`

## Batch 5 - Finance, reporting and compliance

- [x] Keep money, balances and mixed currencies precise; retain approval/override and receipt rules.
- [x] Keep reporting periods, authorized exports and jurisdiction-specific controls intact.
- [x] Recheck A4/80mm print views and fiscal/non-fiscal information after shared-style changes.

14 page files:

- [x] `approvals/index.tsx`
- [x] `billing/open-balances.tsx`
- [x] `cashier-shifts/index.tsx`
- [x] `customer-accounts/index.tsx`
- [x] `customer-accounts/show.tsx`
- [x] `fiscal-receipting/checkout-report.tsx`
- [x] `fiscal-receipting/zra-document.tsx`
- [x] `payments/index.tsx`
- [x] `rates/tax-preview.tsx`
- [x] `rates/taxes.tsx`
- [x] `receipts/show.tsx`
- [x] `receipts/index.tsx`
- [x] `reports/group.tsx`
- [x] `reports/index.tsx`

## Batch 6 - Settings, teams, subscriptions and platform

Visual refinement in progress on 23 November 2026: shared brand-rule page
headings, clearer table-header hierarchy, stronger active navigation and a
thin masthead accent; square, flat settings and platform panels; and clearer
profile/security form grouping. Existing forms and actions are unchanged. The
batch remains unchecked pending full screen-by-screen and browser review.
The available browser control stopped because URL policy enforcement is
unsupported for this Windows browser session.

Account-settings polish on 22 November 2026 preserves the approved brand:
navigation icons and selected-page indication, friendlier introductory copy,
and responsive Light/Dark/System choices with descriptions and accessible
pressed states. This is a scoped refinement, not completion of Batch 6.
Production build, TypeScript, changed-file lint and formatting pass. The
standard `composer check:fast` is blocked by the local broken Bun executable;
browser review and the full frontend suite remain outstanding. No page below
is marked complete by this change.
With SQLite enabled for the test process, the 11 settings feature tests yield
8 passes and 3 failures: page renders attempt an unfaked request to the local
Vite SSR endpoint. These environment-related failures remain unresolved.

- [ ] Reuse the settings/form/list family with bounded reading widths.
- [ ] Keep organization, property, team-role and platform-admin boundaries visible and enforced.
- [ ] Keep subscription billing, payment-slip privacy, confirmation and configuration behaviour.

18 page files:

- [ ] `invitations/create.tsx`
- [ ] `members/edit.tsx`
- [ ] `members/index.tsx`
- [ ] `platform/billing-details/edit.tsx`
- [ ] `platform/invoices/index.tsx`
- [ ] `platform/organizations/index.tsx`
- [ ] `platform/organizations/show.tsx`
- [ ] `platform/plans/index.tsx`
- [ ] `platform/staff/index.tsx`
- [ ] `properties/create.tsx`
- [ ] `properties/edit.tsx`
- [ ] `properties/index.tsx`
- [ ] `properties/show.tsx`
- [ ] `settings/appearance.tsx`
- [ ] `settings/profile.tsx`
- [ ] `settings/security.tsx`
- [ ] `subscription/invoice.tsx`
- [ ] `subscription/show.tsx`

## Final cross-screen pass

- [ ] Every current page file is represented and checked off; no unreviewed page is counted as migrated.
- [ ] Shared dialogs/sheets, search, empty/error/loading/offline states and destructive confirmations are consistent.
- [ ] Light/Dark/System, narrow mobile/tablet/desktop, keyboard focus and long-content cases pass.
- [ ] Server pagination, calendar ranges, sticky context/table headers and internal scrolling remain usable.
- [ ] Financial and print verification passes; property switching and isolation checks remain green.
- [ ] Required automated checks pass and actual remaining limitations are recorded.
- [ ] The owner reviews representative completed flows across all screen families.

## Handoff record

- Latest completed implementation: **Batch 5 - Finance, reporting and
  compliance**, browser-reviewed on 19 November 2026.
- Page migration: **63 of 81 pages complete**, including all 14 Batch 5 entries.
  The next bounded batch is **Batch 6 - Settings, teams, subscriptions and platform**.
- Existing Batch 4 work remains preserved on `feat/ui-redesign-batch-4`.
  Neither batch had been committed, pushed or deployed at the implementation
  handoff; the owner subsequently requested combined delivery.
- Browser: all 14 entries at desktop Light/mobile Dark, representative 320px,
  System, keyboard, tax preview, returned report validation and offline report
  retention. A4/80mm non-fiscal and synthetic sandbox fiscal print emulation
  pass; physical printers and live fiscal acceptance remain unverified.
- Automated: 2,853 Pest tests passed (26 skipped; 49,361 assertions), Pint,
  PHPStan, final build, TypeScript and all frontend checks. Combined release
  validation reran the complete `composer check:fast` successfully, superseding
  the earlier evidence-JSON formatting interruption; both reservation calendar
  row tests also pass.
- Actual scope, checks, evidence and remaining limits: [Batch 5 handoff](BATCH-5.md).

### Preserved Batch 4 record

- Latest completed implementation: **Batch 4 - Rooms, housekeeping, maintenance
  and rates**, browser-reviewed on 18 November 2026.
- Page migration: **49 of 81 pages complete**: nine in Batch 1, two in Batch 2,
  19 in Batch 3 and all 19 in Batch 4. Batch 5 remains untouched.
- Batch 3 PR [#261](https://github.com/Astro-67/the-dream/pull/261) passed both
  checks and merged as `0fc8fb08`; main CI and deployment then succeeded.
- Batch 4 uses `feat/ui-redesign-batch-4` from that merged base. It remains
  local/uncommitted; no Batch 4 PR, merge or deployment.
- Browser: 210 distinct passing cases cover all 19 pages, desktop/mobile,
  Light/Dark/System, 320/768px, long content, real room/rate/assignment/maintenance
  writes, loading/offline states, keyboard scrolling, touch and property isolation.
  No remaining application JavaScript errors.
- Final automated checks pass: build, 2,853 Pest tests (26 skipped; 49,361
  assertions), Pint, PHPStan, TypeScript, all frontend checks and repository
  formatting/lint. Calendar row unit tests pass (2/2); the design scan,
  evidence links and 49/81 inventory pass. No backend/query changes; no MySQL rerun.
- Scope, final automated results, screenshots and limits: [Batch 4 handoff](BATCH-4.md).
- Next bounded batch: **Batch 5 - Finance, reporting and compliance**.

### Preserved Batch 3 record

- Latest completed work: **Batch 3 - Front desk, reservations and guest records**,
  implemented, browser-reviewed and validated on 18 November 2026.
- Page migration: **30 of 81 pages complete**: nine in Batch 1, two in Batch 2
  and all 19 in Batch 3. No later batch is checked off by shared styling.
- Browser: 186 distinct cases, all 19 pages at desktop/mobile in
  Light/Dark/System, extra 320/768px stress cases, real workflows/permissions,
  offline/loading states and generated A4/80mm print output. No remaining
  application JavaScript errors.
- Automated: full fast checks pass with 2,853 Pest tests (26 skipped; 49,361
  assertions), frontend checks, build, PHP/static/type/lint/format checks.
  MySQL: 145 broad focused tests and 44 post-priority-fix dashboard/list tests.
- Scope, fixes, screenshots, PDFs and limits: [Batch 3 handoff](BATCH-3.md).
- Next bounded batch: **Batch 4 - Rooms, housekeeping, maintenance and rates**.
  Batch 3 ships through `feat/ui-redesign-batch-3` and a PR against `main`;
  CI, review, merge and deployment remain separate gates.

### Preserved Batch 2 record

- Latest completed work: **Batch 2 - Organization setup and onboarding**,
  implemented, browser-reviewed and validated on 18 November 2026.
- Page migration: **11 of the 81 pages are complete**: nine in Batch 1 and two
  in Batch 2. The shared checklist migration does not complete the rest of
  `dashboard.tsx`; that page remains in Batch 3.
- Browser: 97 distinct checks cover 320/390/768/1440px, Light/Dark/System,
  native and returned errors, keyboard/touch targets, loading/offline states,
  atomic creation, real imports/pagination and property isolation. No
  application JavaScript errors remain after the browser fixes.
- Automated checks, representative captures, fixture/harness corrections and
  remaining limits are recorded in [the Batch 2 handoff](BATCH-2.md).
- Next bounded batch: **Batch 3 - Front desk, reservations and guest records**.
  Keep its guest queue/cards and page-owned migration separate from Batch 2.

### Preserved Batch 1 record

- Previous completed work: **Batch 1 - Public site and authentication**, implemented
  and validated on 13 November 2026 after integrating Batch 0's finished
  `d9cf9ef9` foundation in `fc6a4fdd`. Merge/deployment status is tracked in
  [PR #257](https://github.com/Astro-67/the-dream/pull/257).
- Page migration at that handoff: **nine of the 81 pages were complete**, all
  in Batch 1. Shared styling alone is not page migration.
- The next bounded batch at that handoff was Batch 2; guest-list cards and
  the operational queue remained reserved for Batch 3.
- Batch 1 checks: final serialized `composer check:fast` exits zero (2,843 Pest
  tests passed, 26 skipped; 49,165 assertions), with build, Pint, PHPStan,
  TypeScript and formatting/lint passing. All frontend checks pass. Only actual
  admin-configured plans are advertised; the admin-update/fresh-guest and
  empty-plan regressions pass (24 focused landing/subscription tests, 233
  assertions). Supplied photography and the owner's landing
  masthead/DM Sans/icon-only-control refinements passed a combined 90-result
  browser matrix plus a native reduced-motion menu check and nine real admin/
  public pricing cases. The handoff records
  the corrected evidence-formatting finding and earlier build/test collision.
  The original nine-page review also covers validation, loading, unsupported
  passkeys and offline retention. Physical passkey success and native
  background-tab lifecycle remain unverified; visibility events were simulated.
- Batch 1 scope, dependencies, actual checks and evidence: [validation record](BATCH-1.md).

### Preserved Batch 0 record

- Base: PR #255, `2aded5fe`; PR checks, main CI and the production deployment all
  succeeded before implementation. The checkout was clean, including
  `docs/design.md`. The branch includes the approved `93a4f619` handoff via
  `2fe909f4`, preserving both sides of the detector-config conflict.
- Changed surfaces: operational masthead/menu, tenant/date/user context,
  breadcrumbs and existing parent navigation, scoped shared controls and
  portals, flat table/card geometry, status/tag geometry, identifier targets,
  empty states, pagination, theme aliases and online/offline feedback.
- Automated: `composer check:fast` passed (2,836 Pest tests passed, 26 skipped;
  49,087 assertions), including build, Pint, PHPStan, TypeScript and repository
  formatting/lint. Final affected build, `bun run types:check`,
  `bun run test:frontend`, exact 81-page inventory and `git diff --check` passed.
  Disposable SQLite only; the development database was not migrated, wiped or
  seeded. PHPStan's optional local Turbo-extension warning is non-blocking.
- Browser: operational 320/390/768/1440px and public landing/login/signup at
  390/1440px; Light/Dark/System against both device schemes. Keyboard/focus,
  desktop/mobile menus and confirmations, select portal, property-switch reset,
  validation, empty state, real server pagination/scroll reset, long-content
  stress, coarse-pointer targets, native reduced motion and real browser
  offline/recovery checks passed. No browser JavaScript errors.
- Evidence and full scope: [Batch 0 validation record](BATCH-0.md), including
  representative before/after captures. T3 preview screenshot timeouts required
  a documented switch to the already-installed Playwright/Chrome tools.
- Remaining limitations: physical-device and full A4/80mm print reviews remain
  outstanding; no new local MySQL run (no schema/query changes), no production
  deployment or merge. Page-owned layouts and later batches remain unmigrated.
  Offline protection cancels Inertia writes; it is not a queue, does not promise
  server reachability while online and does not visually disable every existing
  write control.
