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

- [ ] Stage business/property draft fields while retaining the single existing creation action.
- [ ] Preserve required property fields, optional registration details, jurisdiction rules and server validation.
- [ ] Migrate the shared onboarding checklist, preserving real readiness and go-live gates.
- [ ] Preserve the reservation-import workflow; consult `docs/imports.md` when changing its UI.

2 page files:

- [ ] `onboarding/import.tsx`
- [ ] `organizations/create.tsx`

## Batch 3 - Front desk, reservations and guest records

Reference: [front desk light](approved/front-desk-light.html) /
[dark](approved/front-desk-dark.html).

- [ ] Implement the direct-access guest-list cards, clear active state and distinct icons.
- [ ] Supply the guest queue from existing scoped reservation-list logic; retain real search, pagination and permissions.
- [ ] Preserve independent occupancy/readiness states, balance semantics and guarded check-in/out.
- [ ] Reuse list/detail/form patterns for guest and booker pages; retain merge confirmations and identity privacy.
- [ ] Preserve property business dates and night-audit behaviour.

19 page files:

- [ ] `bookers/create.tsx`
- [ ] `bookers/edit.tsx`
- [ ] `bookers/index.tsx`
- [ ] `bookers/show.tsx`
- [ ] `dashboard.tsx`
- [ ] `guests/create.tsx`
- [ ] `guests/edit.tsx`
- [ ] `guests/index.tsx`
- [ ] `guests/merge.tsx`
- [ ] `guests/show.tsx`
- [ ] `night-audit/index.tsx`
- [ ] `night-audit/show.tsx`
- [ ] `reservations/availability.tsx`
- [ ] `reservations/create.tsx`
- [ ] `reservations/guests.tsx`
- [ ] `reservations/group-statement.tsx`
- [ ] `reservations/index.tsx`
- [ ] `reservations/show.tsx`
- [ ] `reservations/statement.tsx`

## Batch 4 - Rooms, housekeeping, maintenance and rates

- [ ] Apply the operational visual family to the existing list/detail/form patterns.
- [ ] Keep room readiness, assignments and out-of-service periods distinct.
- [ ] Keep calendar/date-range navigation, horizontal scrolling and sticky labels usable.
- [ ] Preserve real room/rate validation, currency labels and permissions.

19 page files:

- [ ] `housekeeping/assignments.tsx`
- [ ] `housekeeping/history.tsx`
- [ ] `housekeeping/index.tsx`
- [ ] `housekeeping/my-rooms.tsx`
- [ ] `inventory/room-types/create.tsx`
- [ ] `inventory/room-types/edit.tsx`
- [ ] `inventory/room-types/index.tsx`
- [ ] `inventory/rooms/create.tsx`
- [ ] `inventory/rooms/edit.tsx`
- [ ] `inventory/rooms/index.tsx`
- [ ] `maintenance/index.tsx`
- [ ] `maintenance/show.tsx`
- [ ] `rates/calendar.tsx`
- [ ] `rates/create.tsx`
- [ ] `rates/edit.tsx`
- [ ] `rates/index.tsx`
- [ ] `rates/prices.tsx`
- [ ] `rates/quote.tsx`
- [ ] `rates/restrictions.tsx`

## Batch 5 - Finance, reporting and compliance

- [ ] Keep money, balances and mixed currencies precise; retain approval/override and receipt rules.
- [ ] Keep reporting periods, authorized exports and jurisdiction-specific controls intact.
- [ ] Recheck A4/80mm print views and fiscal/non-fiscal information after shared-style changes.

14 page files:

- [ ] `approvals/index.tsx`
- [ ] `billing/open-balances.tsx`
- [ ] `cashier-shifts/index.tsx`
- [ ] `customer-accounts/index.tsx`
- [ ] `customer-accounts/show.tsx`
- [ ] `fiscal-receipting/checkout-report.tsx`
- [ ] `fiscal-receipting/zra-document.tsx`
- [ ] `payments/index.tsx`
- [ ] `rates/tax-preview.tsx`
- [ ] `rates/taxes.tsx`
- [ ] `receipts/show.tsx`
- [ ] `receipts/index.tsx`
- [ ] `reports/group.tsx`
- [ ] `reports/index.tsx`

## Batch 6 - Settings, teams, subscriptions and platform

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

- Latest completed work: **Batch 1 - Public site and authentication**, implemented
  and validated on 13 November 2026 after integrating Batch 0's finished
  `d9cf9ef9` foundation in `fc6a4fdd`. Merge/deployment status is tracked in
  [PR #257](https://github.com/Astro-67/the-dream/pull/257).
- Page migration: **nine of the 81 pages are complete**, all in Batch 1. No other
  batch's page checkboxes change. Shared styling alone is not page migration.
- Next bounded batch: **Batch 2 - Organization setup and onboarding**. The
  operational guest-list cards and queue remain Batch 3.
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
