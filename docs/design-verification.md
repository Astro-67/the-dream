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

## Scoped audit and remaining limits

| Dimension                | Score / 4 | Qualification                                                    |
| ------------------------ | --------- | ---------------------------------------------------------------- |
| Accessibility            | 3         | Measured contrast/focus/states pass; inherited gaps below        |
| Performance              | 3         | Production build, no added dependencies; not a runtime profile   |
| Responsive design        | 3         | Containment and geometry pass; touch qualification incomplete    |
| Theming                  | 4         | Both themes, token checks and monochrome print verified          |
| Implementation integrity | 4         | Shared components retained; no nested elevation or new identity  |
| Total                    | 17 / 20   | Good for this representative matrix, not universal certification |

- **P1, inherited project-standard gap:** sampled sidebar rows are 32px and many
  shared actions are 36px, below the documented 44px touch target. These sizes
  predate #205; this ticket does not claim the whole application meets 44px.
  Viewport resizing does not emulate a real coarse-pointer device. Parent review
  must decide the follow-up before claiming full touch acceptance.
- **P2, inherited keyboard issue:** the mobile sheet can close with Escape, but
  focus can land on the body rather than its toggle. Context switching remounts
  the trigger as well. Existing controlled-sheet behaviour was not rewritten.
- No physical A4/thermal printer, printer-driver calibration, PDF pagination,
  assistive-technology session, other browser engine or exhaustive route audit.
  Long thermal statements require multiple pages with the accepted 200mm size.
  Fiscal report content has automated coverage; no fresh fiscal filing was
  generated for browser printing.
- No approved compact mark exists. Existing full branding/product text remains;
  no replacement artwork was introduced.
- Full `composer check:fast`, MySQL checks and final cross-ticket review are
  intentionally left to the parent once after final integration. No push or PR.
