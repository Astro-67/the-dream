# UI redesign handoff

The owner approved the direction below. **Batch 0 implements the shared
operational foundation; individual page migrations remain pending.**
Continue from this package rather than repeating the design conversation.

## Start a batch

1. Check current main, the preceding implementation's checks/deployment and
   existing local changes. Batch 0's verified base and checks are recorded in
   [its validation record](BATCH-0.md). The reference capture was made at
   `74535c02`; that is not an instruction to use it as the implementation base.
2. Reconcile the page inventory in [the checklist](CHECKLIST.md) after a merge
   or rebase, then load only the current batch's reference and relevant
   application files. Follow the checklist's next bounded batch.
3. Implement one batch with the existing Laravel/Inertia components and real
   authorized data. Update its checkboxes and the handoff record after checks.
   A shared style reaching a page does not by itself finish that page.

The files are saved in a trackable directory, not ignored scratch storage.
The original package was preserved in `93a4f619`. Ensure that commit or its
equivalent changes are included before starting in a new checkout.

## Approved direction

| Surface    | Visual target                                                                   | Retain                                                                                                                          |
| ---------- | ------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------- |
| Landing    | [B](approved/landing.html)                                                      | B's composition; animated hotel photography and headline; real pricing; the original copyright / Pricing / FAQ / Contact footer |
| Login      | [Login](approved/login.html)                                                    | Focused form, full brand, hotel photograph on wider screens; password, passkey, reset, remember-me and registration access      |
| Signup     | [Signup and setup](approved/signup.html)                                        | Account, email confirmation, business and first-property steps, followed by the setup checklist                                 |
| Front desk | [Light](approved/front-desk-light.html) / [Dark](approved/front-desk-dark.html) | Direct-access Arrivals / Departures / In house navigation cards; flat priority-grouped guest rows and room-status ledger        |

### Operational design decisions

- Use the approved front-desk masthead/menu as the operational shell target,
  keeping the work area wide. Reuse the existing navigation components and
  permission-based item definitions. The owner's follow-up overrides the
  prototype's menu-only navigation: keep the expanded sidebar visible from
  768px upwards, with a modal menu only on smaller screens. Apply shared
  16px/24px page gutters without doubling existing page padding.
- Use intentional navigation cards, not generic rounded containers everywhere.
  All three guest-list choices stay visible and one click away, including on
  mobile. A dropdown is not the approved replacement for these choices.
- The operational reference uses straight edges. Its content stays flat, with
  hierarchy, alignment and separators rather than rounded panels and room tiles.
  Public/auth controls retain their separately approved treatment.
- Arrival uses a **concierge bell**, departure an **exit** symbol, and in-house
  a **people** symbol. Distinguish meanings by shape as well as labels.
- Use General Sans headings, DM Sans UI text and JetBrains Mono identifiers.
  Reuse the full approved logo. Use the project palette and semantic tokens.
- Support **Light, Dark and System** on every migrated surface. System follows
  the device; explicit choices must not inherit the surrounding chat's theme.
  Use the existing `useAppearance` hook and `AppearanceToggle` in production.
- Keep the organization, property and property business date visible.
  Design operational screens for action and scanning, not marketing animation.

[The existing design system](../design.md) remains the source for shared
components, accessibility, money, dates, status semantics and print rules.
The operational masthead, straight-edged treatment and direct-access cards above
are the approved visual changes. In Batch 0, reconcile them into that document while
preserving its existing local edits; do not globally flatten the approved
public/auth surfaces.

## Keep the real behaviour

- Retain tenant scoping, server authorization, property-switch remounts,
  `PropertyIntent`, validation, confirmations and offline write protection.
  Follow [architecture section 4](../architecture.md) and the existing code.
- Prototype guest names, counts, rooms and balances are synthetic. Use real,
  authorized page props in production. The dashboard currently exposes counts
  and a room board, not the prototype's complete guest queue: Batch 3 must reuse
  scoped reservation-list queries and account for pagination and permissions.
- Available, reserved, occupied and housekeeping readiness are distinct.
  Zero balance with unposted charges is not "paid"; currencies stay separate.
  Keep the existing check-in, checkout, credit and override rules.
- Signup's business/property steps are a draft presentation of the existing
  **single organization + first-property creation**. Keep that write atomic,
  require real email verification, retain every required field and use
  server-provided password rules. Registration does not bypass go-live checks.
- Landing prices come from `WelcomeController`'s current plan props and use
  `formatMoney`, per subscribed room and billing period. The
  [pricing snapshot](approved/pricing-snapshot.json) is not a production price
  list. Contact details come from configuration, not the demo email address.
- Reimplement the designs in existing React components. Snapshot DOM scripts,
  read-only demo fields, preview actions and self-checks are not application code.
  Keep the accepted hero's pause, visibility and reduced-motion behaviour.

## Check and hand off

Use [the development workflow](../agents/dev-workflow.md) for environment and
test commands. For production UI changes, run affected checks, the frontend
checks and `composer check:fast`. New routes also need the documented isolation
coverage; prefer the existing routes.

Before a page is checked off: verify populated, empty, validation/error,
loading and offline states; desktop and mobile; Light/Dark/System; keyboard
access; authorized actions; long content and pagination where applicable.
Receipts/statements/reports also retain their A4/80mm and fiscal information.
Historic checks in [design verification](../design-verification.md) do not
verify a new batch.

At batch end, update the checklist's handoff record with changed surfaces,
actual checks run, remaining risks and the next bounded batch. Capture a fresh
visual review on representative pages, not another concept round for every page.

## New-thread prompt

```text
Read AGENTS.md and docs/ui-redesign/README.md.
Start Batch 0 only from docs/ui-redesign/CHECKLIST.md after verifying the implementation base.
Preserve existing local work, use the approved references and existing shared components,
validate the changed UI and required tests, and update the checklist and handoff record.
Leave later batches for subsequent threads.
```
