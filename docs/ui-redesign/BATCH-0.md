# Batch 0 - Shared UI foundation

## Desktop navigation follow-up

The owner's 13 November 2026 follow-up supersedes the menu-only navigation
described in the original capture below. Desktop navigation stays expanded from
768px upwards, regardless of old sidebar cookies; smaller screens use the
existing modal drawer. A shared page wrapper supplies 16px/24px padding and
removes duplicate legacy page padding. Sidebar space and page padding disappear
for print.

Validation: the desktop visibility regression failed before the change and now
passes. All frontend checks and `composer check:fast` pass (2,836 Pest tests,
26 skipped). A fresh synthetic-data browser pass covers 26 responsive, theme,
drawer, keyboard and print-layout cases with no JavaScript exceptions. This
does not replace physical-device or full A4/80mm content verification.

## Verified implementation base

Verified on 13 November 2026, before implementation:

- [PR #255](https://github.com/Astro-67/the-dream/pull/255) merged as
  `2aded5feebdb62d96b006f0ae99d753d9f14a394`. Its backend and frontend checks passed.
- [Main CI](https://github.com/Astro-67/the-dream/actions/runs/43389266453)
  passed for that exact merge.
- [Deployment](https://github.com/Astro-67/the-dream/actions/runs/43392441977)
  passed, including the upload/deploy step. GitHub production deployment
  `7760626926` reported `success` for that same SHA at 08:21 UTC.
- The checkout started clean at detached `93a4f619`; there were no uncommitted
  `docs/design.md` edits to stash, discard or include inadvertently.
- `feat/ui-redesign-batch-0` starts from updated main. The missing approved
  handoff was cherry-picked as `2fe909f4`. The sole conflict was the detector
  configuration: main's existing test exemptions and the handoff's archived
  reference exemptions were both retained.

The inventory now covers 81 unique page files, up from 75. Added entries:
customer account list/detail, ZRA document, property detail, receipt list and
group statement. No individual page is marked migrated by this batch.

## Changed shared surfaces

- Existing `AppShell`, sidebar provider, `AppSidebarHeader`, tenant switcher,
  `NavMain` and `NavUser`: full-width operational masthead and modal workspace
  menu on desktop and mobile. The existing permission/feature-filtered item
  definitions remain the navigation owner.
- Sticky organization/property and business-date context, full inverse logo,
  user menu, breadcrumbs, deterministic parent links and the existing
  Light/Dark/System control. Page remount and prefetch-cache invalidation on a
  tenant switch remain unchanged.
- Scoped square controls, shared cards/table frames, chips/tags, empty states,
  pagination and portaled overlays. Static operational elevation is removed
  through its tokens, not by removing keyboard focus shadows. Public/auth
  radius, elevation, typography and existing functionality remain separate.
- Masthead-specific semantic tokens and inverse focus/hover aliases; existing
  General Sans, DM Sans and JetBrains Mono assets are reused.
- Table height and focus-scroll clearance follow the measured sticky context,
  including wrapped names.
  Identifier copy targets retain their behavior and gain coarse-pointer sizing.
- Reactive online/offline status and a persistent warning. Non-GET Inertia
  visits are canceled while the browser is offline; existing page-level
  protections remain. There is no offline queue and no optimistic success.

No routes, authorization rules, reservation queries, financial calculations,
guest queue, public/auth redesign or individual page redesign are introduced.

## Automated validation

- `composer check:fast`: build, Pest, Pint, PHPStan, TypeScript and repository
  formatting/lint passed. Pest: **2,836 passed, 26 skipped**, 49,087 assertions.
- Final affected frontend build, `bun run types:check` and
  `bun run test:frontend` passed. The frontend command now includes the focused
  offline-write check alongside theme, sidebar, workspace and parent-navigation
  coverage. Theme checks cover masthead contrast and inverse focus aliases;
  print checks also cover the new sheet surfaces.
- The checklist matches all 81 current page files exactly, with no duplicates
  and no page marked migrated. `git diff --check` passed.
- Builds read migrated disposable SQLite, never the development database.
  The final full check uses a unique SQLite file under `/dev/shm`; parallel
  test databases are disposable siblings. Previewing uses an independent
  `.scratch/batch0-preview.sqlite` copy and synthetic fixtures.
- PHPStan emits a non-blocking local optional Turbo-extension startup warning;
  analysis completes with zero errors. No unrelated code or formatting fixes
  are included.

## Browser evidence

Browser checks and captures use the real Laravel/Inertia application, not the
reference HTML. The business date in captures comes from the selected property,
not the machine's current date. Fixtures are synthetic and are not shipped.

T3 preview was used first. Its screenshot operation subsequently timed out
twice; the remaining checks and final captures use the already-installed
Playwright library and an isolated headless Chrome context. No project browser
dependency was added.

Passed scenarios:

- Operational viewports 320/390/768/1440px: Light, Dark and System, each checked
  against device Light and Dark. Explicit choices stay explicit; System follows
  changes. No page-level horizontal overflow; table and theme-control radius is
  zero; full logo loads and measured sticky height follows responsive context.
- Real password + two-factor login; Ctrl+B menu shortcut, Tab containment,
  Escape and focus return with the inverse ring; normal navigation and nested
  user-menu Settings navigation close the workspace menu.
- Authorized property switching keeps the existing dashboard redirect, removes
  the unsaved form, and renders a fresh form with the new `PropertyIntent`.
  Reservation filters render the shared empty state; invalid room submission
  renders the server's field errors.
- Portaled select and desktop/mobile destructive confirmations stay square and
  bounded. Close targets are 44px. Native reduced-motion emulation removes
  sheet animation and transitions.
- Real browser offline/recovery events update the warning; an attempted
  non-GET Inertia form submission is canceled before visiting the server.
- A disposable 55-guest fixture exercises actual server pagination, current-page
  semantics and internal table scroll reset.
- Temporary DOM stress (not shipped fixtures or production scripts) checks
  wrapping long names, a sticky context at page scroll, sticky table headers
  and bounded two-axis table scrolling. Coarse-pointer emulation checks
  input/select/submit targets of at least 44px.
- Public landing, login and signup at 390/1440px pass all three modes against
  both device schemes. No operational scope leaks in; public/auth inputs retain
  their 6px radius. No JavaScript exceptions across verified surfaces.

Representative captures, from the actual application with synthetic data:

| Surface                         | Before                                                    | After                                                   |
| ------------------------------- | --------------------------------------------------------- | ------------------------------------------------------- |
| Operational desktop Light       | [Before](evidence/batch-0/before-rooms-desktop.png)       | [After](evidence/batch-0/after-rooms-desktop-light.png) |
| Operational mobile Light        | [Before](evidence/batch-0/before-rooms-mobile.png)        | [After](evidence/batch-0/after-rooms-mobile-light.png)  |
| Login desktop Light (preserved) | [Before](evidence/batch-0/before-login-desktop-light.png) | [After](evidence/batch-0/after-login-desktop-light.png) |

Additional captures: [desktop Dark](evidence/batch-0/after-rooms-desktop-dark.png),
[mobile Dark](evidence/batch-0/after-rooms-mobile-dark.png),
[workspace menu](evidence/batch-0/after-workspace-menu.png),
[offline warning](evidence/batch-0/after-offline-mobile.png).

Physical-device behavior and full A4/80mm print review remain unverified. No
additional local MySQL run was needed for this UI-only change; MySQL remains a
CI gate. These checks do not claim full migration or full workflow review of any
individual page. Existing write controls are not all visually disabled offline;
the shared protection cancels their Inertia mutations rather than queuing them.

## Boundaries and next batch

The shared foundation changes how existing screens render, but their remaining
page-owned rounded containers, layout and interactions still belong to their
assigned batches. Arrivals / Departures / In house cards and the approved
front-desk content are Batch 3, not partially implemented here.

Next: **Batch 1 - Public site and authentication**, using the approved landing,
login and signup references while retaining actual pricing, sign-in choices,
validation and registration behavior. Do not merge or deploy this batch without
the owner's approval.
