# Integrated design verification (#208)

Verified on 1 November 2026 against #205, after #206 and #207. This is
representative evidence, not full WCAG certification or physical-printer approval.

## Environment and scope

- Continued `12972630`; its theme-check registration and preserved Cancel
  documentation remain intact.
- Used a new, owned T3 browser tab and port 8209, serving this ticket worktree
  with `php artisan serve --no-reload`. Every server/fixture command explicitly
  selected SQLite and this worktree's `.scratch/preview.sqlite`; builds selected
  `.scratch/build.sqlite`. Pest selected SQLite `:memory:`.
- Recovered the existing disposable account and fixtures. Added an assigned
  long-name guest, 32 idempotent extra charges and a sample SaaS invoice only
  in preview SQLite. No development MySQL access, reset, seed or migration.
- Kept the existing rendered-Inertia checks and browser seam. No framework,
  route, permission, workflow, dependency or logo changes.

## Screen matrix

Each row was checked in **both themes at desktop, 820px tablet and 390px mobile**
widths. Desktop was 1440px, except the populated reservation list at 1280px.
All had zero document-wide horizontal overflow.

| Surface            | Data/state                                                              | Lowest settled text contrast, light / dark |
| ------------------ | ----------------------------------------------------------------------- | ------------------------------------------ |
| Reservations       | 40 real persisted rows; long names; credit, unpaid and confirmed labels | 4.84 / 4.64                                |
| Rate calendar      | 15 days; assigned long-name guest; room/rate/availability rows          | 5.36 / 4.64                                |
| Reservation detail | Charges, payment, expanded payments and fixed nightly prices            | 4.84 / 4.64                                |
| Add booker         | Bounded short form; native and server validation                        | 5.80 / 4.64                                |
| New reservation    | Wide, multi-section long form                                           | 5.80 / 4.64                                |
| Profile settings   | Settings navigation and bounded form                                    | 4.83 / 4.64                                |
| Confirm password   | Authentication layout and full logo                                     | 5.80 / 8.41                                |
| Public landing     | Existing photography, product screenshots and full logo                 | 5.62 / 7.71                                |

Contrast measurements used computed foreground colours and composited ancestor
backgrounds after fonts loaded and theme transitions settled (250ms). Disabled
controls and hidden text were excluded. Photo-backed CTA text was measured
separately against the rendered image crop plus its existing 60% black scrim:
paragraph minima were **5.99 desktop, 5.12 tablet and 4.52 mobile**; its large
heading also passed. Naive ancestor-only measurements over that sibling scrim,
and samples taken mid-transition, were false positives and are not passes/fails.

### Geometry, navigation and states

- Reservation tables scroll internally: 873px content in 514px tablet and
  356px mobile frames. The populated list's header stayed 1px below the frame
  edge after 1000px vertical scrolling; horizontal scrolling also worked.
  The calendar containing the long guest label used a 3146px internal scroll
  area on mobile, without widening the document.
- Booker form centre offset was 0px at all three widths, including expanded
  and collapsed desktop navigation. The collapsed rail left a 1392px workspace
  at 1440px. The reservation form remained 1344px wide there; settings retained
  a 720px form column.
- Direct entry into Booker edit exposed the named record parent, linked
  ancestors and non-navigable current breadcrumb. Its mobile return link grew
  to 76px high for the long name, without overflow. Following it reached detail;
  detail returned to Bookers. Keyboard Enter on the create-page parent reached
  Bookers. Edit Cancel still targets `/bookers`, not the new record parent.
- Keyboard Tab from the parent reached Name with a visible 2px focus ring and
  2px offset; dark computed ring was `#93b2f0`. Theme controls changed the actual
  preference from System to Light to Dark. The token check covers text,
  status, input-boundary and focus contrast in both themes.
- Switching between the two disposable properties returned to Dashboard and
  replaced property context; switching back restored the demo context and
  business date. Organization identity remained available in the context menu.
  No tenant entry point or remount implementation changed.
- Empty reservation search displayed the explanation and Find availability
  action, not an empty table. Empty Name triggered native validation and focus.
  With native validation deliberately bypassed, the server returned the field
  error; its contrast passed and no record was created. While submitting, the
  form became inert and showed the loading spinner, then recovered.
- Expanded reservation payments, nightly prices and folio postings have sibling
  elevated table frames, not Tables inside elevated Cards. Computed checks found
  **no nested elevated panels** in `folio-panel` or `reservations/show`; no
  speculative elevation rewrite was needed.

## Fixes and browser red/green checks

1. Shared guest/booker Save button: failed at **62.5 -> 78.5 -> 62.5px** during
   submission. Reserving its spinner slot now keeps **86.5px** throughout idle,
   inert/loading and validation recovery, without fixing the text width.
2. Print tables: a 34-posting statement clipped **1705px into 886px** on virtual
   A4. Print now removes the screen height cap and scrolling, and makes table
   headers static. All 34 rows, including verification item 32, are exposed.
3. Thermal columns: previously **400px inside 270px**, with vertical clipping.
   Print-only cell padding and description wrapping now fit **270/270px**.
   Headings and monetary values retain normal word wrapping. Printed borders,
   text and backgrounds are black/white, and shadows are removed.
4. SaaS invoices lacked the shared print-document opt-in. They now use it:
   invoice identity and amounts remain, while shell, return link, upload form
   and print controls disappear.
5. The browser discarded statement `@page size: 80mm auto` (parsed size was
   empty). It now accepts **80mm 200mm**, matching the existing receipt pattern.

### Virtual print method and result

Used the loaded browser CSSOM's actual print-media declarations, applied
temporarily to the page, at printable widths of **703px for A4/12mm margins**
and **272px for 80mm paper/4mm margins**. Inspected parsed `@page` rules separately.
This exercises computed styles and containment, **not the browser's paged-media
pagination engine**.

- Final A4 statement: table width **701/701px**, height **1705/1705px**.
- Final thermal statement: **270/270px**, **5625/5625px**, all 34 rows present,
  accepted 80mm/200mm page size, black borders and zero colour/shadow failures.
- Non-fiscal receipt: A4 and thermal retain `RCT-000001`, non-fiscal heading and
  USD 100.00; return navigation and controls are hidden.
- SaaS invoice: A4 retains `SAAS-000001`, billing details and amounts; zero
  horizontal overflow or colour/shadow failures.

Browser assertions threw on the original failures and passed after correction.
The containment self-check, after activating print CSS, was:

```js
const frames = [...document.querySelectorAll('[data-slot="table-container"]')];
if (
    frames.some(
        (frame) =>
            frame.scrollHeight > frame.clientHeight ||
            frame.scrollWidth > frame.clientWidth,
    )
)
    throw new Error('Printed table clips content');
```

## Review fixup (#209)

Rechecked on 1 November 2026 in `ticket/205-review-fixup`, based on `2963ba44`,
using a new owned T3 tab. Setup used
`scripts/worktree-setup.sh /home/astro/Documents/Invited-Project/the-dream`.
Builds explicitly selected this worktree's `.scratch/build.sqlite`.

### Repaired acceptance gaps

- Shared buttons, sidebar rows, linked sidebar section labels and dropdown
  actions now declare minimum **44 x 44px** targets under
  `any-pointer: coarse`. This includes hybrid devices without expanding
  mouse-only desktop controls. The coarse-pointer collapsed rail is 60px;
  its inner frame measured 59px after the border, with unclipped 44px targets.
- At **390px**, both themes measured Save **86.5 x 44px**, Cancel **78 x 44px**,
  toggle **44 x 44px**, and all 18 mobile sidebar controls at least
  **271 x 44px**. At **820px**, both themes measured all 18 sidebar controls
  at least **239 x 44px** expanded and **44 x 44px** collapsed. No document-wide
  horizontal overflow occurred. The property menu's short row changed from
  **32px to 44px**; the long current-property row retained its natural 72px.
- These are **coarse-rule geometry checks, not coarse-input emulation**.
  T3's iPhone preset still reported `pointer: fine`,
  `any-pointer: coarse = false` and `maxTouchPoints = 0`; its exposed tools
  offer no pointer-mode override. Used T3 evaluation to activate the built
  stylesheet's actual coarse media rule through CSSOM, then measured it.
  Unmodified fine-pointer sidebar rows and short menu actions remained 32px.
  A physical/hybrid touch-device session remains unverified.
- A hidden sidebar tooltip was consuming the first Escape. Tooltips are now
  mounted only for the collapsed desktop rail, where labels are unavailable.
  Mobile Escape closes the sheet in one press and restores the current
  surviving toggle; Enter reopens it. This passed after an actual property
  switch, without changing tenant-switch behaviour.
- A disposable real React/Inertia fixture in `.scratch/sidebar-focus.tsx`
  also keyed/remounted `AppSidebarHeader` while the sheet remained open.
  The old toggle became disconnected; Escape focused its replacement,
  Enter reopened navigation, and an explicit close again focused the
  replacement. This fixture uses the existing sidebar primitives, not another
  browser or test framework. The focus assertion after each close was:

```js
if (
    document.querySelector('[role="dialog"][data-state="open"]') ||
    document.activeElement !==
        document.querySelector('[data-sidebar="trigger"]')
)
    throw new Error('Sidebar close lost the surviving toggle');
```

- Booker and Guest ancestor links now use existing generated route helpers.
  Empty Guest merge renders its explanation and exactly one shared return
  link, not a second local Back action. The rendered-Inertia regression
  failed with two return links before removal and passes with one afterward.
- `ThemeTokensCheck` now compares both Blade pre-paint backgrounds with
  the CSS background tokens. A deliberate temporary ivory-to-white drift
  failed the check; restoring the existing colour passed. No additional
  token owner or generated configuration was introduced.

### Minimal heuristic decisions

- Kept the short declarative reservation breadcrumb arrays on their pages:
  each names its own parent and current label; a builder would introduce an
  interface without hiding meaningful complexity.
- Touched Booker/Guest current items use the existing `href: '#'` convention.
  The shared breadcrumb still renders only ancestors as links. No
  `BreadcrumbItem` API expansion or unrelated placeholder sweep.
- Invoice scope now uses the existing `useCurrentUrl().isCurrentOrParentUrl`
  with the generated platform-invoices route, not a literal URL prefix or
  new server prop. Both invoice contexts and a paginated query URL pass.
- Kept the repeated standalone-form container classes and the existing
  `ContactShow` detail composition. The former already express the same
  simple layout; the latter is not a standalone form. No wrapper, blanket
  detail-page centring or operational-width reduction was added.

## Automated checks

- All **22** registered checks: `bun run test:frontend`, including
  `ThemeTokensCheck`, folio, calendar/list, workspace/navigation, wide forms,
  receipt, SaaS invoice and fiscal report checks.
- Production build: `DB_CONNECTION=sqlite DB_DATABASE="$PWD/.scratch/build.sqlite" bun run build`.
- TypeScript: `bun run types:check`.
- Targeted `bun run check` for the changed CSS and contact, invoice and statement
  files; commit hooks also ran their existing staged-file formatter/linter.
- Affected Pest: **63 tests, 968 assertions**, covering `ReservationListsTest`,
  `ReservationCalendarTest`, `CalendarFiltersTest`, `FolioStatementTest`,
  `ReceiptSummaryTest` and `SaasInvoicesTest`.
- After the last print fix: the three print/billing Pest files again passed
  **43 tests, 420 assertions**, and receipt/invoice/fiscal frontend checks passed.
- `git diff --check` passed. Parent integration tip `fc3eb3c9` was merged locally
  with an already-up-to-date result.

Raw command logs and disposable browser helpers remain in `.scratch/`; the
measurements above are the durable record. Captures were made in this resumed
session, not inherited from the failed provider.

Review-fixup results:

- All **22** registered frontend checks pass, including the extended sidebar,
  actual empty-merge rendering, navigation and pre-paint checks.
- Explicit scratch-SQLite production build and `bun run types:check` pass.
  Scoped formatting/lint checks and `git diff --check` pass. The Impeccable
  detector returned `[]` for the changed UI files.
- Affected SQLite Pest (`GuestsTest`, `GuestAccessTest`, `GuestMergeTest`):
  **33 passed, 380 assertions**.
- One full `composer test:mysql` run: **2,410 passed, 1 skipped,
  40,276 assertions**, exit 0, **822.366 seconds**, with its throwaway Docker
  container cleaned up. No PHP/server implementation changed; the final
  frontend-only context-menu sizing follow-up received a fresh build,
  frontend checks, TypeScript, scoped lint and browser red/green check.
- Parent integration `2963ba44` fast-check log
  `/tmp/karafuu-spec205-fast-check.log`: **2,392 passed, 19 skipped,
  40,151 assertions**, Pint and Larastan passed, TypeScript and lint passed.
  The fixup did not repeat the full fast suite.

## Pending paged-media verification handoff

**Actual PDF pagination is still unverified.** T3 has no PDF-export operation.
No auxiliary browser or headless Chromium export was used without permission.
The earlier virtual-print measurements do not close this acceptance gap.

The fixup owns `.scratch/print.sqlite`, a read-only-source SQLite backup of the
populated ticket-208 preview database, not the development database. It retains
the long guest labels, **34 statement rows** and all **32 verification extras**;
the browser confirmed `verification item 32` is present. The copied fixture
account's 2FA was re-encrypted for this worktree's own app key only; no
application security middleware was changed.

To resume locally from the fixup worktree:

```sh
DB_CONNECTION=sqlite DB_DATABASE="$PWD/.scratch/print.sqlite" \
  php artisan serve --no-reload --host=127.0.0.1 --port=8125
```

Log in with the synthetic `design-preview@example.test` / `password` account.
For its current disposable TOTP code, run:

```sh
DB_CONNECTION=sqlite DB_DATABASE="$PWD/.scratch/print.sqlite" \
  php .scratch/print-auth.php
```

Select **Demo: Sample Waterfront Property**. Confirm the same synthetic password
if the subscription route requests it. The exact fixtures are:

- Long statement:
  `http://localhost:8125/reservations/RES-000005/statement`
- Non-fiscal receipt `RCT-000001`:
  `http://localhost:8125/receipts/01m6a6a8gvzr6jx93eh6c2h5mc`
- SaaS invoice `SAAS-000001`:
  `http://localhost:8125/subscription/invoices/01m6a9n2tdc3z021np1spmnyrs`

For the statement and receipt, choose `a4` then `thermal` using
`#statement-paper` or `#receipt-paper`; paper selection is client state,
**not a query-string parameter**. The actual print style declares A4/12mm
margins or **80mm x 200mm / 4mm margins**. The invoice uses A4.

After explicit auxiliary-export permission, export with CSS page size respected,
browser headers/footers disabled and fonts settled. Inspect **every page**, not
only the first: verify all 34 statement postings and the final item 32, repeated
table headers, no clipped rows/columns, complete totals/identifiers, and
black-on-white output. The long thermal statement must span multiple 200mm
pages; continuous-layout height alone proves none of that. Physical printer
calibration remains a separate unavailable check.

Owned ports 8125 (app/print fixtures) and 8126 (focus fixture) are stopped at
handoff; the restart command above preserves explicit SQLite overrides.

## Scoped audit and remaining limits

| Dimension                | Score / 4 | Qualification                                                    |
| ------------------------ | --------- | ---------------------------------------------------------------- |
| Accessibility            | 3         | Repaired focus/targets; no physical device or AT certification   |
| Performance              | 3         | Production build, no added dependencies; not a runtime profile   |
| Responsive design        | 3         | Geometry passes; coarse CSS activated, not input emulated        |
| Theming                  | 4         | Both themes and virtual-print CSS pass; PDF pagination pending   |
| Implementation integrity | 4         | Shared components retained; no nested elevation or new identity  |
| Total                    | 17 / 20   | Good for this representative matrix, not universal certification |

- Touch target sizing and mobile-sheet focus defects are repaired as recorded
  above, not waived as inherited. Real coarse-input/hardware qualification
  remains distinct from the measured coarse-rule geometry.
- No physical A4/thermal printer, printer-driver calibration, PDF pagination,
  assistive-technology session, other browser engine or exhaustive route audit.
  Long thermal statements require multiple pages with the accepted 200mm size.
  Fiscal report content has automated coverage; no fresh fiscal filing was
  generated for browser printing.
- No approved compact mark exists. Existing full branding/product text remains;
  no replacement artwork was introduced.
- Parent fast checks and fixup MySQL checks passed as recorded above. Final
  cross-ticket review and paged-media evidence remain with the parent. No
  integration push or PR was created by the fixup.
