# Batch 4 - Rooms, housekeeping, maintenance and rates

Implemented, browser-reviewed and validated on 18 November 2026. All 19 Batch 4 page
files are migrated; the inventory is now **49 of 81 pages**. Batch 5 is next,
not part of this change.

## Base and delivery

- Batch 3 PR [#261](https://github.com/Astro-67/the-dream/pull/261) passed
  frontend/backend CI and merged as `0fc8fb083af24f46a396bc1b0dd4fa1ae88e21ca`.
  Main CI and deployment subsequently passed for that exact merge.
- Batch 4 uses `feat/ui-redesign-batch-4`, created from updated main.
  At the implementation handoff, changes were local and uncommitted, with no
  PR, merge or deployment. Batch 5 shares this branch for combined delivery.
- No backend query, route, migration, dependency or business write action
  changed. Existing property intent, permissions and server validation remain
  authoritative. Development MySQL and its data were untouched.

## Scope and structure

- Housekeeping board, history, assignments and My rooms now use explicit
  page titles, shell-owned gutters, aligned flat sections and separators.
  Assigned rooms are separated rows rather than rounded nested cards.
- Room/room-type lists and create/edit forms use the same operational family.
  Standalone forms retain their 720px bounds; lists retain the available work
  area. The shared description field uses the existing surface/focus/error
  tokens instead of page-owned radius and shadow styling.
- Maintenance retains its on-demand report dialog, named actions, priority
  labels and history. Long ticket titles/descriptions wrap. Assignment and
  report forms are inert while processing.
- Rate-plan create/edit, prices, restrictions and quote preview retain actual
  currencies, derivation, cancellation policy and returned validation. Simple
  selections now use the existing `NativeSelect`; quote submission exposes
  processing and makes its form inert.
- Read-only rate-plan empty states no longer suggest an unavailable create
  action. Maintenance completion still explicitly says it does not change
  room status or availability.
- Room readiness, cleaning completion, maintenance and out-of-order inventory
  remain separate. No new workflow or configuration was added.

## Calendar

- A named, focusable scroll region preserves keyboard access for managers and
  read-only staff. The table retains sticky date headings and room labels,
  a caption, native date-range navigation, guest search and real reservation
  links. The current business date has a labelled date-column highlight.
- Visual review found content-sized date columns and an over-wide sticky room
  column hiding the first mobile date. A fixed-layout column group now uses
  a 10rem label column and equal 7rem date columns. Booking/departure content
  and room actions wrap inside those bounds, without dropping labels or links.
- Bulk-edit dates and amounts stack on narrow screens instead of forcing two
  or three fields across a mobile dialog.
- A later long-name stress case exposed the room-type filter's min-content
  width. The fieldset and labels now shrink/wrap; the 240-character unbroken
  name passes at 320px and 1440px.
- Existing out-of-order periods, calendar write validation, stop-sell release,
  minimum stay, closed-to-arrival and derived-price behavior stay intact.

## Automated verification

- The final `composer check:fast` passed after the column-layout and
  long-filter corrections: build,
  **2,853 Pest tests passed**, 26 skipped, 49,361 assertions, Pint, PHPStan,
  TypeScript and repository formatting/lint.
- All frontend checks pass, including room/reservation calendar, existing
  rate-plan policy/adjustment and workspace/navigation checks. The previously
  standalone `RateCalendarCheck` is now included in `test:frontend` so its
  focus, columns, business-date and wrapping assertions run routinely.
- `bun test resources/js/features/reservations/calendar-rows.test.tsx` passes
  both reservation clipping/overstay checks. The first direct-file invocation
  was rejected by the test runner; the supported `bun test` invocation passed.
- Workspace checks cover the full 19-page migration: explicit titles, no
  doubled gutters and no remaining rounded page-owned panels. Rate-plan
  checks retain policy defaults, saved adjustments and shared select markup.
- The design detector reports no deterministic findings. This does not replace
  the actual desktop/mobile review. Final repository formatting/lint,
  `git diff --check`, the 81-page inventory, all 19 completed Batch 4 checkboxes
  and handoff evidence links pass.
- MySQL was not rerun: no backend, migration or query changed in this batch.
  SQLite tests use disposable test databases; builds read `.scratch/build.sqlite`.
  The existing optional local PHPStan Turbo-extension warning is non-blocking.

## Browser verification

[Consolidated results](evidence/batch-4/browser-results.json) record **210
distinct passing cases**, with no remaining application JavaScript errors:

- 152 surface cases: all 19 pages at 1440px/390px, explicit Light against a
  dark device, explicit Dark against a light device, and System against both.
- 24 further 320px/768px cases: assignments, My rooms, room edit, maintenance
  detail, calendar and price editing in Light/Dark. All stay inside the viewport
  and expose labelled visible inputs.
- Real room edit; room-type native/server validation and successful creation;
  housekeeping assignment/unassignment; out-of-order creation and rejected
  overlap; real prices, quote, restrictions and derived-rate-plan creation.
- Maintenance reporting, assignment/completion, dialog focus/Escape restoration;
  calendar keyboard scrolling, sticky headings/labels, mobile bulk price save
  and stop-sell release preserving minimum stay and closed-to-arrival.
- An offline room save is cancelled without losing entered values; reconnect
  shows the server did not accept the write. A delayed real save exposes an
  inert form while processing.
- Housekeeper touch targets meet 44px; the reduced-motion mobile flow completes
  a dirty-room assignment. An invalid Clean-to-Clean transition is refused,
  rather than silently counted as completion. Rate-plan management returns 403.
- Property switching shows the other property's board and refuses the original
  maintenance ticket with 404. A separate empty synthetic property verifies
  six honest empty states.
- Six long-content cases cover unbroken maintenance title/description and
  room-type/calendar labels at 320px/1440px. Calendar date range and search
  values round-trip through the actual GET form.

The shared preview performed real login/two-factor and page access first.
Two multi-page scans reported "Preview automation evaluate timed out after
15000ms." Its tab was closed; the installed Playwright/Google Chrome fallback
ran the matrix without installing browser dependencies.

Raw runs under `.scratch/batch4-visuals` preserve the initial review and
confirmation. Harness corrections are retained in consolidated provenance:
explicit fixture-property selection, waiting for returned errors/animations,
actual room/rate-plan redirect destinations, dirty-versus-clean fixture
readiness, and an actually empty property instead of the populated audit
fixture. Passing targeted cases are deduplicated; failed harness attempts and
repeat confirmation captures are not counted as extra passing cases.

## Representative evidence

- Housekeeping: [desktop board](evidence/batch-4/housekeeping-index-1440-light.png),
  [mobile assignments](evidence/batch-4/housekeeping-assignments-390-dark.png),
  [touch completion](evidence/batch-4/housekeeper-touch-completion.png).
- Rooms: [mobile edit](evidence/batch-4/rooms-edit-390-dark.png),
  [room-type validation](evidence/batch-4/room-type-server-validation.png),
  [overlap refusal](evidence/batch-4/out-of-order-overlap-refused.png),
  [inert save](evidence/batch-4/room-save-inert-loading.png).
- Maintenance: [mobile detail](evidence/batch-4/maintenance-show-390-dark.png),
  [completed ticket](evidence/batch-4/maintenance-completed.png),
  [long title](evidence/batch-4/long-maintenance-320-light.png).
- Rates: [desktop calendar](evidence/batch-4/rates-calendar-1440-light.png),
  [mobile calendar](evidence/batch-4/rates-calendar-390-dark.png),
  [mobile price dialog](evidence/batch-4/calendar-mobile-price-dialog.png),
  [mobile prices](evidence/batch-4/rates-prices-390-dark.png),
  [long filter](evidence/batch-4/long-rate-calendar-320-light.png).
- Desktop Light and mobile Dark captures for all 19 pages are retained in
  the evidence directory.

## Limits

Browser data uses only a copy of the earlier synthetic SQLite preview fixture,
private scratch storage and database sessions with a frozen 10 December 2026
clock. No production write, real guest data, external fiscal service or email.

Chromium viewport/touch/reduced-motion emulation is not physical-device,
Safari/Firefox or screen-reader verification. No print surface changed;
physical printers and Batch 5 financial/print flows were not rechecked.
Offline protection follows the browser's online signal; it is not a write
queue or a guarantee of server reachability.
